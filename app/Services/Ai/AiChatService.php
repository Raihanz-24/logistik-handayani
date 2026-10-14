<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Models\User;
use App\Services\Ai\Tools\ToolException;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Orkestrator percakapan AI: menyusun konteks, memanggil model,
 * menjalankan tool (whitelist), menyimpan riwayat, dan mencatat pemakaian token.
 */
class AiChatService
{
    public function __construct(
        private readonly DeepSeekClient $client,
        private readonly SystemPromptBuilder $promptBuilder,
    ) {
    }

    /** Pastikan fitur aktif sebelum dipakai. */
    public function assertEnabled(): void
    {
        if (! config('ai.enabled')) {
            throw new AiClientException('Fitur Asisten AI sedang nonaktif.');
        }
    }

    /**
     * Buat percakapan baru untuk user.
     */
    public function startConversation(User $user, ?string $title = null): AiConversation
    {
        return AiConversation::query()->create([
            'user_id' => $user->getKey(),
            'title' => $title,
            'status' => AiConversation::STATUS_ACTIVE,
            'last_message_at' => now(),
        ]);
    }

    /**
     * Kirim satu pesan user dan kembalikan pesan assistant final.
     *
     * @throws AiClientException
     */
    public function reply(User $user, AiConversation $conversation, string $userMessage): AiMessage
    {
        $this->assertEnabled();
        $this->assertOwnership($user, $conversation);

        $setting = AiSetting::current();
        $registry = new ToolRegistry($user);
        $startedAt = microtime(true);

        $conversation->messages()->create([
            'role' => AiMessage::ROLE_USER,
            'content' => $userMessage,
        ]);
        $conversation->forceFill(['last_message_at' => now()])->save();

        $messages = $this->buildModelMessages($user, $conversation, $userMessage);
        $tools = $registry->toOpenAiTools();
        $maxIterations = $this->maxIterations($setting);

        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $toolCallCount = 0;
        $iterations = 0;

        try {
            for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
                $iterations = $iteration;
                $response = $this->client->chat($messages, $tools, $this->overrides($setting));
                $usage = $this->addUsage($usage, $response['usage'] ?? []);

                $assistant = $response['message'];
                $content = trim((string) ($assistant['content'] ?? ''));
                $toolCalls = $assistant['tool_calls'] ?? [];

                if (! is_array($toolCalls) || $toolCalls === []) {
                    $reply = $conversation->messages()->create([
                        'role' => AiMessage::ROLE_ASSISTANT,
                        'content' => $content !== '' ? $content : '(tidak ada balasan)',
                    ]);

                    $this->recordUsage($user, $conversation, $setting, $usage, $toolCallCount, $iterations, $startedAt, AiUsage::STATUS_SUCCESS);

                    return $reply;
                }

                $conversation->messages()->create([
                    'role' => AiMessage::ROLE_ASSISTANT,
                    'content' => $content !== '' ? $content : null,
                    'tool_payload' => ['tool_calls' => $toolCalls],
                ]);
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $content,
                    'tool_calls' => $toolCalls,
                ];

                foreach ($toolCalls as $call) {
                    $name = (string) ($call['function']['name'] ?? '');
                    $callId = (string) ($call['id'] ?? '');
                    $arguments = $this->decodeArguments($call['function']['arguments'] ?? []);

                    $result = $this->runTool($registry, $name, $arguments, $user, $conversation);
                    $toolCallCount++;
                    $resultJson = (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $conversation->messages()->create([
                        'role' => AiMessage::ROLE_TOOL,
                        'tool_name' => $name,
                        'tool_call_id' => $callId,
                        'content' => $resultJson,
                        'tool_payload' => $result,
                    ]);

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $callId,
                        'name' => $name,
                        'content' => $resultJson,
                    ];
                }
            }

            $reply = $conversation->messages()->create([
                'role' => AiMessage::ROLE_ASSISTANT,
                'content' => 'Maaf, permintaan ini butuh terlalu banyak langkah. Mohon diperjelas atau dipecah.',
            ]);

            $this->recordUsage($user, $conversation, $setting, $usage, $toolCallCount, $iterations, $startedAt, AiUsage::STATUS_SUCCESS);

            return $reply;
        } catch (AiClientException $exception) {
            $this->recordUsage($user, $conversation, $setting, $usage, $toolCallCount, $iterations, $startedAt, AiUsage::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            $this->recordUsage($user, $conversation, $setting, $usage, $toolCallCount, $iterations, $startedAt, AiUsage::STATUS_ERROR, 'internal_error');

            throw $exception;
        }
    }

    private function runTool(ToolRegistry $registry, string $name, array $arguments, User $user, AiConversation $conversation): array
    {
        try {
            $result = $registry->get($name)->execute($arguments, $user, $conversation);

            if (! is_array($result)) {
                $result = ['result' => $result];
            }

            return ['ok' => true] + $result;
        } catch (ToolException $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        } catch (Throwable $exception) {
            Log::warning('AI tool gagal dijalankan.', [
                'tool' => $name,
                'error' => $exception->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'Terjadi kesalahan internal saat menjalankan tool.'];
        }
    }

    /**
     * Bangun daftar pesan untuk model (system + riwayat terbatas).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildModelMessages(User $user, AiConversation $conversation, string $userMessage): array
    {
        $limit = max(2, (int) config('ai.history_limit', 20));
        $history = $conversation->messages()->orderBy('id')->get()->slice(-$limit)->values();

        // Jangan mulai dari pesan 'tool' (butuh assistant tool_calls pendahulu).
        while ($history->isNotEmpty() && $history->first()->role === AiMessage::ROLE_TOOL) {
            $history->shift();
        }

        $messages = [[
            'role' => 'system',
            'content' => $this->promptBuilder->build($user, $userMessage),
        ]];

        foreach ($history as $message) {
            $toolCalls = $message->tool_payload['tool_calls'] ?? null;

            if ($message->role === AiMessage::ROLE_ASSISTANT && is_array($toolCalls) && $toolCalls !== []) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => (string) ($message->content ?? ''),
                    'tool_calls' => $toolCalls,
                ];

                continue;
            }

            if ($message->role === AiMessage::ROLE_TOOL) {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) $message->tool_call_id,
                    'name' => (string) $message->tool_name,
                    'content' => (string) ($message->content ?? ''),
                ];

                continue;
            }

            $messages[] = [
                'role' => $message->role,
                'content' => (string) ($message->content ?? ''),
            ];
        }

        return $messages;
    }

    /** @return array{temperature?: float, max_tokens?: int} */
    private function overrides(?AiSetting $setting): array
    {
        $overrides = [];

        if ($setting?->temperature !== null) {
            $overrides['temperature'] = (float) $setting->temperature;
        }

        if ($setting?->max_tokens !== null && (int) $setting->max_tokens > 0) {
            $overrides['max_tokens'] = (int) $setting->max_tokens;
        }

        return $overrides;
    }

    private function maxIterations(?AiSetting $setting): int
    {
        $configured = $setting?->max_tool_iterations;

        if (is_int($configured) && $configured > 0) {
            return $configured;
        }

        return max(1, (int) config('ai.max_tool_iterations', 6));
    }

    /**
     * @param  array{prompt_tokens: int, completion_tokens: int, total_tokens: int}  $total
     * @param  array<string, mixed>  $usage
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    private function addUsage(array $total, array $usage): array
    {
        return [
            'prompt_tokens' => $total['prompt_tokens'] + (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => $total['completion_tokens'] + (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens' => $total['total_tokens'] + (int) ($usage['total_tokens'] ?? 0),
        ];
    }

    /**
     * @param  array{prompt_tokens: int, completion_tokens: int, total_tokens: int}  $usage
     */
    private function recordUsage(
        User $user,
        AiConversation $conversation,
        ?AiSetting $setting,
        array $usage,
        int $toolCalls,
        int $iterations,
        float $startedAt,
        string $status,
        ?string $errorMessage = null,
    ): void {
        if (! Schema::hasTable('ai_usages')) {
            return;
        }

        try {
            AiUsage::query()->create([
                'ai_conversation_id' => $conversation->getKey(),
                'user_id' => $user->getKey(),
                'provider' => (string) config('ai.provider'),
                'model' => (string) config('ai.model'),
                'prompt_tokens' => $usage['prompt_tokens'],
                'completion_tokens' => $usage['completion_tokens'],
                'total_tokens' => $usage['total_tokens'],
                'tool_calls' => $toolCalls,
                'iterations' => $iterations,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'status' => $status,
                'error_message' => $errorMessage,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Gagal mencatat pemakaian AI.', ['error' => $exception->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function decodeArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (! is_string($arguments) || trim($arguments) === '') {
            return [];
        }

        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function assertOwnership(User $user, AiConversation $conversation): void
    {
        if ($user->hasRole('super_admin')) {
            return;
        }

        if ((int) $conversation->user_id !== (int) $user->getKey()) {
            throw new AiClientException('Percakapan ini bukan milik Anda.');
        }
    }
}

<?php

namespace App\Filament\Pages\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Validator;

class Login extends BaseLogin
{
    protected static string $view = 'filament.pages.auth.login';

    protected static string $layout = 'filament.layouts.auth';

    /**
     * @var array{email: string, password: string, remember: bool}
     */
    public ?array $data = [
        'email' => '',
        'password' => '',
        'remember' => false,
    ];

    public ?string $loginErrorMessage = null;

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());
        }

        $this->data = [
            'email' => '',
            'password' => '',
            'remember' => false,
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return 'Masuk - Warehouse Monitoring PT ISS';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Selamat datang kembali';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Masuk untuk melanjutkan pengelolaan stok dan mutasi barang.';
    }

    /**
     * @return array<string, string>
     */
    public function getExtraBodyAttributes(): array
    {
        return [
            'class' => 'wm-login-body',
        ];
    }

    public function authenticate(): ?LoginResponse
    {
        $this->resetErrorBag();
        $this->loginErrorMessage = null;

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->showLoginFailure("Terlalu banyak percobaan masuk. Coba lagi dalam {$exception->secondsUntilAvailable} detik.");

            return null;
        }

        $validator = Validator::make($this->data ?? [], [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ], [
            'email.required' => 'Alamat email wajib diisi.',
            'email.email' => 'Masukkan alamat email yang valid.',
            'password.required' => 'Kata sandi wajib diisi.',
        ]);

        if ($validator->fails()) {
            $this->showLoginFailure($validator->errors()->first());

            $this->data['password'] = '';

            return null;
        }

        $data = $validator->validated();
        $remember = (bool) ($data['remember'] ?? false);

        if (! Filament::auth()->attempt([
            'email' => $data['email'],
            'password' => $data['password'],
        ], $remember)) {
            $this->showLoginFailure('Email atau kata sandi tidak sesuai. Periksa kembali data login Anda.');
            $this->data['password'] = '';

            return null;
        }

        $user = Filament::auth()->user();

        if (
            ($user instanceof FilamentUser) &&
            (! $user->canAccessPanel(Filament::getCurrentPanel()))
        ) {
            Filament::auth()->logout();

            $this->showLoginFailure('Akun Anda belum memiliki akses ke panel ini. Hubungi administrator.');
            $this->data['password'] = '';

            return null;
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function updated(string $property, mixed $value = null): void
    {
        $this->loginErrorMessage = null;
        $this->resetErrorBag();
    }

    private function showLoginFailure(string $message): void
    {
        $this->loginErrorMessage = $message;
    }
}

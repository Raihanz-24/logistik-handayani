@php
    $user = auth()->user();
@endphp

@if ($user instanceof \App\Models\User && $user->hasRole('super_admin'))
    <x-filament::dropdown placement="bottom-end" teleport>
        <x-slot name="trigger">
            <x-filament::icon-button
                color="gray"
                icon="heroicon-m-ellipsis-vertical"
                label="Menu khusus"
                tooltip="Menu khusus"
            />
        </x-slot>

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item
                :href="\App\Filament\Pages\FotoBarangEditor::getUrl()"
                icon="heroicon-m-pencil-square"
                tag="a"
                :spa-mode="true"
            >
                Editor Foto Maps
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif

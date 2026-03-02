<x-filament-panels::page>
    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}

        @if($this->selectedRole)
        <x-filament-panels::form.actions
            :actions="[
                \Filament\Actions\Action::make('save')
                    ->label('Salvează permisiunile')
                    ->submit('save')
                    ->color('primary'),
            ]"
        />
        @endif
    </x-filament-panels::form>
</x-filament-panels::page>

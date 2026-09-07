<form wire:submit="generate" style="display: grid; gap: 1.5rem; align-content: start;">
    {{ $this->form }}
    @if ($hasGenerator)
        <div>
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="generate">Generar serie</x-filament::button>
        </div>
    @endif
</form>

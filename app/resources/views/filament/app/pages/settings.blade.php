<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">Guardar cambios</x-filament::button>
        </div>
    </form>

    <div class="mt-6">
        {{ $this->content }}
    </div>
</x-filament-panels::page>

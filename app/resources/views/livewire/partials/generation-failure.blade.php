<div wire:key="failure-{{ $generation->id }}">
    <p>{{ $generation->error_message }}</p>
    @if ($generation->retryable)
        @if (in_array($generation->failure_reason?->value, ['poll_timeout', 'download_failed']))
            <x-filament::button color="gray" wire:click="checkStatus({{ $generation->id }})">
                {{ $generation->failure_reason->value === 'poll_timeout' ? 'Comprobar estado' : 'Reintentar descarga' }}
            </x-filament::button>
        @endif
        {{ ($this->restartAction)(['generationId' => $generation->id]) }}
    @endif
</div>

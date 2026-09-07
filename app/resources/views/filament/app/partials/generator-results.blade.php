<div @if ($running->isNotEmpty()) wire:poll.3s="refreshResults" @endif>
    <x-filament::section heading="Imágenes generadas">
        <div style="display: grid; gap: 1.5rem;">
            @foreach ($running as $generation)
                <x-filament::section wire:key="generation-{{ $generation->id }}">
                    <p>{{ match ($generation->kind->value) { 'series' => 'Serie', 'edit' => 'Edición', 'upscale' => 'Ampliación' } }}</p>
                    <p>{{ match ($generation->jobs->first()?->status) { null, 'pending', 'queued', 'submitted' => 'en cola', 'processing', 'running' => 'en proceso', 'completed', 'done' => 'completado', 'failed' => 'fallido', 'cancelled' => 'cancelado', default => $generation->jobs->first()->status } }}</p>
                    @if ($generation->jobs->first()?->queue_position !== null)
                        <p>posición {{ $generation->jobs->first()->queue_position }}</p>
                    @endif
                </x-filament::section>
            @endforeach
            @if ($latestPieces->isNotEmpty())
                <x-filament::section heading="Última serie">
                    @include('filament.app.partials.piece-grid', ['pieces' => $latestPieces])
                </x-filament::section>
            @endif
            @if ($failure)
                <x-filament::section heading="No se pudo completar la generación">
                    <p>{{ $failure->error_message }}</p>
                    @if ($failure->retryable)
                        @if (in_array($failure->failure_reason?->value, ['poll_timeout', 'download_failed']))
                            <x-filament::button color="gray" wire:click="checkStatus({{ $failure->id }})">
                                {{ $failure->failure_reason->value === 'poll_timeout' ? 'Comprobar estado' : 'Reintentar descarga' }}
                            </x-filament::button>
                        @endif
                        {{ ($this->restartAction)(['generationId' => $failure->id]) }}
                    @endif
                </x-filament::section>
            @endif
            @if ($previousPieces->isNotEmpty())
                <x-filament::section heading="Series anteriores">
                    @include('filament.app.partials.piece-grid', ['pieces' => $previousPieces])
                    <x-filament::link :href="$galleryUrl">Ver galería</x-filament::link>
                </x-filament::section>
            @endif
            @if ($running->isEmpty() && $latestPieces->isEmpty() && $previousPieces->isEmpty() && ! $failure)
                <p>Aquí saldrán tus imágenes.</p>
            @endif
        </div>
    </x-filament::section>
</div>

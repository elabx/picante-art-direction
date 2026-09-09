<div wire:poll.5s="poll" x-data @visibilitychange.document="if (!document.hidden) $wire.$refresh()"
    x-on:x-modal-opened.document="if ($event.detail.id === 'jobs-queue') $wire.markSeen()">
    <x-filament::modal id="jobs-queue" heading="Cola de trabajos" slide-over sticky-header teleport="body" width="md" close-button
        :extra-modal-window-attribute-bag="new \Filament\Support\View\ComponentAttributeBag(['autofocus' => true, 'tabindex' => '-1'])">
        <x-slot name="trigger">
            <x-filament::icon-button icon="heroicon-o-queue-list" label="Cola de trabajos" :badge="$activeCount ?: ($unseen ?: null)" />
        </x-slot>
        <x-slot name="description">
            {{ $activeCount ? $activeCount.' '.($activeCount === 1 ? 'trabajo en curso' : 'trabajos en curso') : 'No hay trabajos en curso.' }}
        </x-slot>
        <div style="display:grid; gap:1.5rem;">
            @foreach (['active' => 'En curso', 'recent' => 'Recientes'] as $group => $heading)
                @php($groupRows = $rows->filter(fn ($row) => $row['generation']->status->isTerminal() === ($group === 'recent')))
                <section aria-label="{{ $heading }}" style="display:grid; gap:.75rem;">
                    <h3 style="font-size:.875rem; font-weight:600;">{{ $heading }}</h3>
                    @forelse ($groupRows as $row)
                        @php($generation = $row['generation'])
                        <article wire:key="job-{{ $generation->id }}" style="display:grid; gap:.75rem; padding:1rem; border:1px solid color-mix(in srgb, currentColor 12%, transparent); border-radius:.75rem;">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:.75rem;">
                                <h4 style="font-size:.875rem; font-weight:600;">{{ match ($generation->kind->value) { 'series' => 'Serie', 'edit' => 'Edición', 'upscale' => '4K' } }} <span style="opacity:.55;">#{{ $generation->id }}</span></h4>
                                <x-filament::badge :color="match ($generation->status->value) { 'completed' => 'success', 'failed' => 'danger', default => 'warning' }">{{ match ($generation->status->value) { 'pending', 'submitting', 'submitted' => 'En cola', 'processing' => 'Procesando', 'downloading' => 'Descargando', 'completed' => 'Lista', 'failed' => 'Falló' } }}</x-filament::badge>
                            </div>
                            <p style="font-size:.875rem; line-height:1.5; overflow-wrap:anywhere;">{{ $row['summary'] }}</p>
                            @if ($generation->status->value === 'completed')
                                <div style="display:flex; gap:.5rem;">
                                    @foreach ($generation->pieces as $piece)
                                        <button type="button" aria-label="Abrir pieza {{ $piece->id }}"
                                            x-on:click="$dispatch('close-modal', { id: 'jobs-queue' }); $nextTick(() => $dispatch('open-piece', { pieceId: {{ $piece->id }} }))">
                                            <img src="{{ route('media.piece', $piece) }}" alt="Abrir pieza" style="width:5rem; height:4rem; object-fit:cover; border-radius:.375rem;" onerror="if(!this.dataset.r){this.dataset.r=1;this.src=this.src.split('?')[0]+'?r='+Date.now();}">
                                        </button>
                                    @endforeach
                                </div>
                            @elseif ($generation->status->value === 'failed')
                                @include('livewire.partials.generation-failure', ['generation' => $generation])
                            @endif
                        </article>
                    @empty
                        <p style="font-size:.875rem; opacity:.65;">{{ $group === 'active' ? 'Nada en la cola.' : 'Todavía no hay resultados recientes.' }}</p>
                    @endforelse
                </section>
            @endforeach
            <p style="font-size:.8125rem; opacity:.65;">Puedes seguir trabajando; te avisamos al terminar.</p>
        </div>
    </x-filament::modal>
    <x-filament-actions::modals />
</div>

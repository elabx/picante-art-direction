<div wire:poll.5s="poll" x-data @visibilitychange.document="$wire.$refresh()">
    <x-filament::dropdown width="md" max-height="70vh" placement="bottom-end"
        x-on:mousedown="if ($event.target.closest('.fi-dropdown-trigger')) $nextTick(() => { if ($refs.panel.style.display === 'block') $wire.markSeen() })"
        x-on:keyup.enter="if ($event.target.closest('.fi-dropdown-trigger')) $nextTick(() => { if ($refs.panel.style.display === 'block') $wire.markSeen() })"
        x-on:keyup.space="if ($event.target.closest('.fi-dropdown-trigger')) $nextTick(() => { if ($refs.panel.style.display === 'block') $wire.markSeen() })">
        <x-slot name="trigger">
            <x-filament::icon-button icon="heroicon-o-bell" label="Trabajos · Cola" :badge="$unseen ?: null" />
        </x-slot>
        <div style="padding: 1rem; display: grid; gap: 1rem;">
            <h2>Trabajos · Cola</h2>
            @forelse ($rows as $row)
                @php($generation = $row['generation'])
                <x-filament::section wire:key="job-{{ $generation->id }}">
                    <p>{{ match ($generation->kind->value) { 'series' => 'Serie', 'edit' => 'Edición', 'upscale' => '4K' } }}</p>
                    <x-filament::badge>{{ match ($generation->status->value) { 'pending', 'submitting', 'submitted' => 'En cola', 'processing' => 'Procesando', 'downloading' => 'Descargando', 'completed' => 'Lista', 'failed' => 'Falló' } }}</x-filament::badge>
                    <p>{{ $row['summary'] }}</p>
                    @if ($generation->status->value === 'completed')
                        <div style="display: flex; gap: .5rem;">
                            @foreach ($generation->pieces as $piece)
                                <button type="button" wire:click="$dispatch('open-piece', { pieceId: {{ $piece->id }} })">
                                    <img src="{{ route('media.piece', $piece) }}" alt="Abrir pieza" style="width: 5rem; height: 5rem; object-fit: cover;" onerror="if(!this.dataset.r){this.dataset.r=1;this.src=this.src.split('?')[0]+'?r='+Date.now();}">
                                </button>
                            @endforeach
                        </div>
                    @elseif ($generation->status->value === 'failed')
                        @include('livewire.partials.generation-failure', ['generation' => $generation])
                    @endif
                </x-filament::section>
            @empty
                <p>Nada en la cola.</p>
            @endforelse
            <p>Puedes seguir trabajando; te avisamos al terminar.</p>
        </div>
    </x-filament::dropdown>
    <x-filament-actions::modals />
</div>

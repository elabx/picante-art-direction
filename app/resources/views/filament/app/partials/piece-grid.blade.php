<div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem;">
    @foreach ($pieces as $piece)
        <button type="button" wire:key="piece-{{ $piece->id }}" x-on:click="$dispatch('open-piece', { pieceId: {{ $piece->id }} })" aria-label="Abrir pieza {{ $piece->id }}">
            <img src="{{ route('media.piece', $piece) }}" alt="Pieza {{ $piece->id }}" loading="lazy" style="aspect-ratio: 1; width: 100%; border-radius: .5rem; object-fit: cover;"
                 onerror="if(!this.dataset.r){this.dataset.r=1;this.src=this.src.split('?')[0]+'?r='+Date.now();}">
        </button>
    @endforeach
</div>

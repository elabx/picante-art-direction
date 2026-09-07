<div>
    <x-filament::modal id="piece-viewer" width="7xl" heading="Pieza">
        @if ($piece)
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr)); gap: 1.5rem;">
                <div>
                    <img src="{{ route('media.piece', $piece) }}" alt="Pieza" style="width: 100%;" onerror="if(!this.dataset.r){this.dataset.r=1;this.src=this.src.split('?')[0]+'?r='+Date.now();}" wire:key="viewer-image-{{ $piece->id }}">
                    <p>{{ $piece->width }} × {{ $piece->height }}</p>
                    <div style="display: flex; gap: .75rem; overflow-x: auto;">
                        @foreach ($versions as $version)
                            <button type="button" wire:click="selectVersion({{ $version->id }})" wire:key="version-{{ $version->id }}" aria-pressed="{{ $version->id === $piece->id ? 'true' : 'false' }}">
                                <img src="{{ route('media.piece', $version) }}" alt="Versión {{ $loop->iteration }}" style="width: 5rem; height: 5rem; object-fit: cover;" onerror="if(!this.dataset.r){this.dataset.r=1;this.src=this.src.split('?')[0]+'?r='+Date.now();}">
                                <span>v{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                @if ($version->is_4k)<x-filament::badge>4K</x-filament::badge>@endif
                            </button>
                        @endforeach
                    </div>
                </div>
                <div style="display: grid; gap: 1.5rem;">
                    <x-filament::section heading="Editar la pieza">
                        <dl>
                            @foreach ($originatingInputs as $input)
                                <dt>{{ $input['label'] }}</dt>
                                <dd>
                                    @if (isset($input['url']))
                                        <img src="{{ $input['url'] }}" alt="{{ $input['label'] }}" style="width: 5rem; height: 5rem; object-fit: cover;" onerror="if(!this.dataset.r){this.dataset.r=1;this.src=this.src.split('?')[0]+'?r='+Date.now();}">
                                    @else
                                        {{ $input['text'] }}
                                    @endif
                                </dd>
                            @endforeach
                        </dl>
                        <form wire:submit="applyEdit">
                            <label for="piece-instruction">Qué cambias</label>
                            <x-filament::input.wrapper>
                                <textarea id="piece-instruction" wire:model="instruction" style="width: 100%;" rows="4"></textarea>
                            </x-filament::input.wrapper>
                            @error('generation')<p role="alert">{{ $message }}</p>@enderror
                            <x-filament::button type="submit" :disabled="! $hasEditor">Aplicar edición</x-filament::button>
                            @if (! $hasEditor)<p>Esta campaña no tiene editor configurado.</p>@endif
                        </form>
                    </x-filament::section>
                    <x-filament::section heading="Entrega">
                        {{ $this->upscaleAction }}
                        @if (! $hasUpscaler)<p>Esta campaña no tiene upscaler configurado.</p>@endif
                        @if ($meetsTarget)<p>Esta versión ya alcanza el tamaño de entrega.</p>@endif
                        <label style="display: block;">
                            <x-filament::input.checkbox wire:click="toggleSelected" :checked="$piece->selected" />
                            Marcar seleccionada
                        </label>
                        <p>Visible para todo el equipo de la marca.</p>
                        <x-filament::button color="gray" wire:click="download">Descargar</x-filament::button>
                    </x-filament::section>
                    @if ($running)<p wire:poll.3s="refreshResults">Aplicando…</p>@endif
                    @foreach ($failures as $generation)
                        @include('livewire.partials.generation-failure', ['generation' => $generation])
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::modal>
    <x-filament-actions::modals />
</div>

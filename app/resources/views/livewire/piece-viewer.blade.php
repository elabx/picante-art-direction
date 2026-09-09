<div>
    @once
        <style>
            .muse-viewer { display:grid; grid-template-columns:minmax(0, 1.65fr) minmax(19rem, 1fr); gap:1.5rem; align-items:start; }
            .muse-viewer-preview { display:block; width:100%; max-height:62vh; object-fit:contain; border-radius:.75rem; background:#f3f4f6; }
            .muse-viewer-meta { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin:.75rem 0; font-size:.8125rem; color:#6b7280; }
            .muse-viewer-versions { display:flex; gap:.5rem; overflow-x:auto; padding:.25rem; }
            .muse-viewer-version { flex:0 0 4rem; padding:.25rem; border:2px solid transparent; border-radius:.5rem; font-size:.75rem; }
            .muse-viewer-version[aria-pressed="true"] { border-color:var(--primary-500, #ef4444); background:color-mix(in srgb, var(--primary-500, #ef4444) 8%, transparent); }
            .muse-viewer-version img { display:block; width:100%; height:3rem; object-fit:cover; border-radius:.25rem; margin-bottom:.25rem; }
            .muse-viewer-controls { display:flex; flex-direction:column; gap:1.25rem; min-width:0; }
            .muse-viewer-form { display:grid; gap:.75rem; }
            .muse-viewer-heading { font-size:1rem; font-weight:600; line-height:1.5; }
            .muse-viewer-label { display:block; margin-bottom:.375rem; font-size:.875rem; font-weight:500; }
            .muse-viewer-hint { font-size:.8125rem; line-height:1.5; color:#6b7280; }
            .muse-viewer-instruction { display:block; width:100%; padding:.75rem; min-height:6rem; resize:vertical; border:0; background:transparent; font:inherit; line-height:1.5; outline:none; }
            .muse-viewer-actions { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; }
            .muse-viewer-delivery { border-top:1px solid #e5e7eb; padding-top:1rem; display:grid; gap:.75rem; }
            .muse-viewer-selection { display:flex; align-items:center; gap:.5rem; font-size:.875rem; cursor:pointer; }
            .muse-viewer-origin { border-top:1px solid #e5e7eb; padding-top:1rem; font-size:.8125rem; }
            .muse-viewer-origin summary { cursor:pointer; font-weight:500; }
            .muse-viewer-origin dl { display:grid; gap:.75rem; margin-top:.75rem; }
            .muse-viewer-origin dt { color:#6b7280; margin-bottom:.25rem; }
            .muse-viewer-origin dd { overflow-wrap:anywhere; line-height:1.5; }
            .muse-viewer-origin img { width:4rem; height:4rem; object-fit:cover; border-radius:.375rem; }
            .muse-viewer-status { font-size:.875rem; line-height:1.5; padding:.75rem; border-radius:.5rem; background:#f9fafb; }
            .dark .muse-viewer-preview, .dark .muse-viewer-status { background:#18181b; }
            .dark .muse-viewer-delivery, .dark .muse-viewer-origin { border-color:#3f3f46; }
            .dark .muse-viewer-hint, .dark .muse-viewer-meta, .dark .muse-viewer-origin dt { color:#a1a1aa; }
            @media (max-width: 800px) { .muse-viewer { grid-template-columns:minmax(0, 1fr); } .muse-viewer-preview { max-height:40vh; } }
        </style>
    @endonce
    <x-filament::modal id="piece-viewer" width="7xl" heading="Pieza">
        @if ($piece)
            <div class="muse-viewer">
                <div style="min-width:0;">
                    <img class="muse-viewer-preview" src="{{ route('media.piece', $piece) }}" alt="Pieza" onerror="if(!this.dataset.r){this.dataset.r=1;this.src=this.src.split('?')[0]+'?r='+Date.now();}" wire:key="viewer-image-{{ $piece->id }}">
                    <div class="muse-viewer-meta">
                        <span>{{ $piece->width }} × {{ $piece->height }}</span>
                        <span>{{ count($versions) }} {{ count($versions) === 1 ? 'versión' : 'versiones' }}</span>
                    </div>
                    <div class="muse-viewer-versions" aria-label="Versiones de la pieza">
                        @foreach ($versions as $version)
                            <button class="muse-viewer-version" type="button" wire:click="selectVersion({{ $version->id }})" wire:key="version-{{ $version->id }}" aria-pressed="{{ $version->id === $piece->id ? 'true' : 'false' }}">
                                <img src="{{ route('media.piece', $version) }}" alt="Versión {{ $loop->iteration }}" onerror="if(!this.dataset.r){this.dataset.r=1;this.src=this.src.split('?')[0]+'?r='+Date.now();}">
                                <span>v{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                @if ($version->is_4k)<x-filament::badge>4K</x-filament::badge>@endif
                            </button>
                        @endforeach
                    </div>
                </div>
                <div class="muse-viewer-controls">
                    <form wire:submit="applyEdit" class="muse-viewer-form">
                        <div>
                            <h2 class="muse-viewer-heading">Editar la pieza</h2>
                            <p class="muse-viewer-hint">Describe el cambio para crear una nueva versión.</p>
                        </div>
                        <div>
                            <label class="muse-viewer-label" for="piece-instruction">Qué cambias</label>
                            <x-filament::input.wrapper>
                                <textarea class="muse-viewer-instruction" id="piece-instruction" wire:model="instruction" rows="3" placeholder="Ej. Cambia el fondo a azul claro."></textarea>
                            </x-filament::input.wrapper>
                        </div>
                        @error('generation')<p role="alert">{{ $message }}</p>@enderror
                        <div class="muse-viewer-actions">
                            <x-filament::button type="submit" :disabled="! $hasEditor" wire:loading.attr="disabled" wire:target="applyEdit">Aplicar edición</x-filament::button>
                        </div>
                        @if (! $hasEditor)<p class="muse-viewer-hint">Esta campaña no tiene editor configurado.</p>@endif
                    </form>
                    <div class="muse-viewer-delivery">
                        <div class="muse-viewer-actions">
                            <x-filament::button color="gray" wire:click="download" icon="heroicon-m-arrow-down-tray">Descargar</x-filament::button>
                            {{ $this->upscaleAction }}
                        </div>
                        @if (! $hasUpscaler)<p class="muse-viewer-hint">Esta campaña no tiene upscaler configurado.</p>@endif
                        @if ($meetsTarget)<p class="muse-viewer-hint">Esta versión ya alcanza el tamaño de entrega.</p>@endif
                        <div>
                            <label class="muse-viewer-selection">
                                <x-filament::input.checkbox wire:click="toggleSelected" :checked="$piece->selected" />
                                Marcar seleccionada
                            </label>
                            <p class="muse-viewer-hint" style="margin-top:.25rem;">Visible para todo el equipo de la marca.</p>
                        </div>
                    </div>
                    @if ($running)<p class="muse-viewer-status" wire:poll.3s="refreshResults" role="status">Aplicando…</p>@endif
                    @foreach ($failures as $generation)
                        <div class="muse-viewer-status" role="status">
                            @include('livewire.partials.generation-failure', ['generation' => $generation])
                        </div>
                    @endforeach
                    <details class="muse-viewer-origin" wire:key="viewer-origin-{{ $piece->id }}">
                        <summary>Detalles de origen</summary>
                        <dl>
                            @foreach ($originatingInputs as $input)
                                <div>
                                    <dt>{{ $input['label'] }}</dt>
                                    <dd>
                                        @if (isset($input['url']))
                                            <img src="{{ $input['url'] }}" alt="{{ $input['label'] }}" onerror="if(!this.dataset.r){this.dataset.r=1;this.src=this.src.split('?')[0]+'?r='+Date.now();}">
                                        @else
                                            {{ $input['text'] }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </details>
                </div>
            </div>
        @endif
    </x-filament::modal>
    <x-filament-actions::modals />
</div>

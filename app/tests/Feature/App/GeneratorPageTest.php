<?php

use App\Enums\UserRole;
use App\Filament\App\Pages\Generator;
use App\Jobs\DownloadOutputJob;
use App\Jobs\PollGenerationJob;
use App\Jobs\RunGenerationJob;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\GenerationJob;
use App\Models\GenerationOutput;
use App\Models\InputUpload;
use App\Models\Piece;
use App\Models\PipelineField;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

afterEach(function (): void {
    Livewire::flushState();
});

it('renders and submits the current schema while retaining inputs and rotating the request UUID', function (): void {
    Queue::fake([RunGenerationJob::class]);
    [$editor, $brand, $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug]);
    $requestId = $page->get('requestId');

    $page->assertSee('1 · Qué quieres ver')->assertSee($pipeline->label)
        ->fillForm(['inputs.describe_la_escena' => 'taller de bicis'])
        ->call('generate')->assertHasNoFormErrors()
        ->assertNotified('Serie enviada. Aparecerá aquí cuando termine.')
        ->assertSet('inputs.describe_la_escena', 'taller de bicis');

    expect(Generation::sole()->request_id)->toBe($requestId);
    expect(Generation::sole()->execution_snapshot['inputs']['describe_la_escena'])->toBe('taller de bicis');
    expect(Generation::sole()->execution_snapshot['config_revision'])->toBe(3);
    expect($page->get('requestId'))->not->toBe($requestId);
    Queue::assertPushed(RunGenerationJob::class, 1);
});

it('validates required schema rules and keeps the request UUID on failure', function (): void {
    Queue::fake();
    [$editor, , $campaign] = editorInCampaign();
    readyGenerator($campaign);
    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug]);
    $requestId = $page->get('requestId');

    $page->call('generate')->assertHasFormErrors(['inputs.describe_la_escena'])->assertSet('requestId', $requestId);

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects stale revision without refreshing it during validation', function (): void {
    Queue::fake();
    [$editor, , $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])->fillForm(['inputs.describe_la_escena' => 'x']);
    $pipeline->increment('config_revision');

    $page->call('generate')->assertNotified('La configuración cambió. Recarga el formulario.')->assertSet('formRevision', 3);

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('selects the active campaign default and resets inputs when switching pipelines', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    $first = readyGenerator($campaign);
    $second = readyGenerator($campaign);
    $second->update(['label' => 'Segundo', 'config_revision' => 8]);
    $campaign->update(['default_pipeline_id' => $second->id]);

    Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])
        ->assertSet('pipelineId', $second->id)->assertSee('Segundo')
        ->fillForm(['inputs.describe_la_escena' => 'anterior'])
        ->set('pipelineId', $first->id)->assertSet('formRevision', 3)
        ->assertSet('inputs.describe_la_escena', null);
});

it('retains historical results without an active generator and hides submit', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])
        ->assertSee('Esta campaña no tiene generador configurado.')
        ->assertSee('Aquí saldrán tus imágenes.')->assertDontSee('Generar serie')
        ->assertSee('sin piezas archivadas todavía')->assertDontSee('wire:poll.3s', false);
});

it('resolves identical campaign slugs inside the current brand on the real HTTP route', function (): void {
    $foreign = Campaign::factory()->create(['slug' => 'compartida', 'name' => 'Ajena']);
    [$editor, $brand, $campaign] = editorInCampaign();
    $editor->brands()->attach($foreign->brand_id);
    $campaign->update(['slug' => 'compartida', 'name' => 'Propia']);

    $this->actingAs($editor)->get("/app/{$brand->slug}/campaigns/compartida")->assertSee('Propia')->assertDontSee('Ajena');
});

it('404s foreign or deleted campaign slugs', function (bool $deleted): void {
    [$editor, $brand, $campaign] = editorInCampaign();
    $target = $deleted ? $campaign : Campaign::factory()->create();
    if ($deleted) {
        $target->delete();
    }
    $this->actingAs($editor)->get("/app/{$brand->slug}/campaigns/{$target->slug}")->assertNotFound();
})->with([false, true]);

it('rejects foreign campaign objects and IDs in direct Livewire mounting', function (bool $object): void {
    [$editor] = editorInCampaign();
    $foreign = Campaign::factory()->create();
    Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $object ? $foreign : $foreign->id])->assertNotFound();
})->with([false, true]);

it('reauthorizes fresh role and membership for actions and refreshes', function (string $change, string $action): void {
    Queue::fake();
    [$editor, $brand, $campaign] = editorInCampaign();
    readyGenerator($campaign);
    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])->fillForm(['inputs.describe_la_escena' => 'x']);
    if ($change === 'role') {
        $editor->fresh()->update(['role' => UserRole::ArtDirector]);
    } else {
        $brand->users()->detach($editor);
    }

    $page->call($action)->assertForbidden();
    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['role', 'membership'])->with(['generate', '$refresh']);

it('rejects a forged pipeline from another campaign even when both belong to the user brand', function (): void {
    Queue::fake();
    [$editor, $brand, $campaign] = editorInCampaign();
    readyGenerator($campaign);
    $foreign = readyGenerator(Campaign::factory()->for($brand)->create());
    Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])->set('pipelineId', $foreign->id)->assertNotFound();
    Queue::assertNothingPushed();
});

it('finalizes a real form upload to an owned ID and reuses its UI path on a second series', function (): void {
    Queue::fake([RunGenerationJob::class]);
    Storage::fake('inputs');
    [$editor, $brand, $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    PipelineField::factory()->for($pipeline)->create(['name' => 'imagen', 'input_type' => 'image', 'required' => true]);
    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])
        ->fillForm(['inputs.describe_la_escena' => 'x', 'inputs.imagen' => UploadedFile::fake()->image('referencia.png')])
        ->call('generate')->assertHasNoFormErrors();
    $upload = InputUpload::sole();
    expect($upload->user_id)->toBe($editor->id)->and($upload->brand_id)->toBe($brand->id);
    expect(Generation::sole()->execution_snapshot['inputs']['imagen'])->toBe(['__upload' => $upload->id]);
    expect(array_values($page->get('inputs.imagen')))->toBe([$upload->storage_path]);
    Storage::disk('inputs')->assertExists($upload->storage_path);
    expect(Storage::disk('inputs')->allFiles('tmp'))->toBe([]);

    $page->call('generate')->assertHasNoFormErrors();

    expect(InputUpload::count())->toBe(1)->and(Generation::count())->toBe(2);
    expect(Generation::latest('id')->first()->execution_snapshot['inputs']['imagen'])->toBe(['__upload' => $upload->id]);
    Queue::assertPushed(RunGenerationJob::class, 2);
});

it('refuses other users or brands finalized upload paths and forged record arrays', function (string $case): void {
    Queue::fake();
    Storage::fake('inputs');
    [$editor, $brand, $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    PipelineField::factory()->for($pipeline)->create(['name' => 'imagen', 'input_type' => 'image']);
    $upload = InputUpload::factory()->create([
        'brand_id' => $case === 'brand' ? Brand::factory()->create()->id : $brand->id,
        'user_id' => $case === 'user' ? User::factory()->create()->id : $editor->id,
    ]);
    Storage::disk('inputs')->put($upload->storage_path, file_get_contents(base_path('tests/Fixtures/images/tiny.png')));
    $value = $case === 'array' ? ['__upload' => $upload->id] : ['forged' => $upload->storage_path];

    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])
        ->fillForm(['inputs.describe_la_escena' => 'x'])->set('inputs.imagen', $value)->call('generate');
    $page->assertHasFormErrors(['inputs.imagen']);
    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['user', 'brand', 'array']);

it('renders bounded results with media endpoints, queue positions and polling only while running', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    $older = Generation::factory()->for($campaign)->for($pipeline)->create(['status' => 'completed', 'completed_at' => now()->subHour()]);
    $previous = Piece::factory()->count(27)->for($older)->sequence(fn ($sequence) => ['index' => $sequence->index])->create();
    $latest = Generation::factory()->for($campaign)->for($pipeline)->create(['status' => 'completed', 'completed_at' => now()]);
    $recent = Piece::factory()->count(5)->for($latest)->sequence(fn ($sequence) => ['index' => $sequence->index])->create();
    $running = Generation::factory()->for($campaign)->for($pipeline)->create();
    GenerationJob::factory()->for($running)->create(['queue_position' => 7, 'status' => 'queued']);

    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])
        ->assertSee('32 piezas en 2 series')->assertSee('posición 7')->assertSee('wire:poll.3s', false)
        ->assertSee('Última serie')->assertSee('Series anteriores')->assertSee('Ver galería')
        ->assertSee(route('media.piece', $recent[0]), false)->assertDontSee(route('media.piece', $recent[4]), false)
        ->assertSee(route('media.piece', $previous[26]), false)->assertDontSee('src="'.route('media.piece', $previous[0]).'"', false)
        ->assertSee('open-piece')->assertSee('onerror=', false);
    $running->forceFill(['status' => 'completed'])->save();
    $page->dispatch('jobs-updated')->assertDontSee('wire:poll.3s', false);
});

it('recovers failed work without submitting another generation', function (string $reason, string $label): void {
    Queue::fake([RunGenerationJob::class, PollGenerationJob::class, DownloadOutputJob::class]);
    $engine = fakeEngine();
    [$editor, , $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    $generation = Generation::factory()->for($campaign)->for($pipeline)->for($editor)->create(['status' => 'failed', 'retryable' => true, 'failure_reason' => $reason, 'error_message' => 'No se pudo terminar.']);
    $job = GenerationJob::factory()->for($generation)->create(['normalized_status' => $reason === 'download_failed' ? 'completed' : 'pending']);
    if ($reason === 'download_failed') {
        GenerationOutput::factory()->for($generation)->for($job, 'job')->create(['status' => 'failed', 'attempts' => 3]);
    }
    Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])->assertSee($label)
        ->call('checkStatus', $generation->id)->assertHasNoErrors();
    expect(Generation::count())->toBe(1)->and($engine->submissions)->toBe([]);
    Queue::assertNotPushed(RunGenerationJob::class);
})->with([['poll_timeout', 'Comprobar estado'], ['download_failed', 'Reintentar descarga']]);

it('requires explicit restart confirmation with the exact failure-specific copy', function (string $reason, string $sentence): void {
    Queue::fake([RunGenerationJob::class]);
    [$editor, , $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    $generation = Generation::factory()->for($campaign)->for($pipeline)->for($editor)->create(['status' => 'failed', 'retryable' => true, 'failure_reason' => $reason]);
    $action = TestAction::make('restart')->arguments(['generationId' => $generation->id]);
    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])->mountAction($action)->assertActionMounted($action);
    $mounted = $page->instance()->getMountedAction();
    expect($mounted->isConfirmationRequired())->toBeTrue();
    expect($mounted->getModalHeading())->toBe('¿Iniciar una nueva generación?');
    expect($mounted->getModalDescription())->toBe($sentence.' Iniciar una nueva generación puede generar un cargo adicional. Intentaremos recuperar el resultado anterior cuando sea posible.');
    expect($mounted->getModalSubmitActionLabel())->toBe('Sí, generar de nuevo');
    expect($mounted->getModalCancelActionLabel())->toBe('Cancelar');
    expect(Generation::count())->toBe(1);
    Queue::assertNotPushed(RunGenerationJob::class);

    $page->callMountedAction()->assertHasNoErrors();
    expect(Generation::count())->toBe(2);
    expect(Generation::latest('id')->first()->restarted_from_generation_id)->toBe($generation->id);
    Queue::assertPushed(RunGenerationJob::class, 1);
})->with([['submission_unknown', 'El trabajo anterior podría seguir en curso.'], ['provider_failed', 'El trabajo anterior terminó sin completarse.']]);

it('rejects recovery and restart arguments from another campaign', function (bool $restart): void {
    Queue::fake();
    [$editor, $brand, $campaign] = editorInCampaign();
    $other = Campaign::factory()->for($brand)->create();
    $generation = Generation::factory()->for($other)->for($editor)->create(['status' => 'failed', 'retryable' => true, 'failure_reason' => 'poll_timeout']);
    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug]);
    if ($restart) {
        $page->mountAction(TestAction::make('restart')->arguments(['generationId' => $generation->id]))->assertNotFound();
    } else {
        $page->call('checkStatus', $generation->id)->assertNotFound();
    }
    Queue::assertNothingPushed();
})->with([false, true]);

it('deduplicates a delivered request replay including its already-consumed upload', function (): void {
    Queue::fake([RunGenerationJob::class]);
    Storage::fake('inputs');
    [$editor, , $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    PipelineField::factory()->for($pipeline)->create(['name' => 'imagen', 'input_type' => 'image', 'required' => true]);
    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])
        ->fillForm(['inputs.describe_la_escena' => 'x', 'inputs.imagen' => UploadedFile::fake()->image('referencia.png')]);
    $snapshot = $page->snapshot;
    $page->call('generate')->assertHasNoFormErrors();
    $page->snapshot = $snapshot;

    $page->call('generate')->assertHasNoFormErrors()->assertNotified('Serie enviada. Aparecerá aquí cuando termine.');

    expect(Generation::count())->toBe(1)->and(InputUpload::count())->toBe(1);
    Queue::assertPushed(RunGenerationJob::class, 1);
});

it('blocks upload RPC targets outside visible registered image components', function (): void {
    Storage::fake('local');
    config(['livewire.temporary_file_upload.disk' => 'local']);
    [$editor, , $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    PipelineField::factory()->for($pipeline)->create(['name' => 'imagen', 'input_type' => 'image']);
    Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])
        ->call('_startUpload', 'inputs.unregistered', [['name' => 'x.png', 'size' => 128, 'type' => 'image/png']], false)->assertForbidden();
    Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])
        ->call('_startUpload', 'inputs.imagen', [['name' => 'x.png', 'size' => 128, 'type' => 'image/png']], false)
        ->assertDispatched('upload:generatedSignedUrl');
});

it('rejects arbitrary temporary storage paths supplied by the client', function (): void {
    Queue::fake();
    Storage::fake('inputs')->put('tmp/foreign.png', file_get_contents(base_path('tests/Fixtures/images/tiny.png')));
    [$editor, , $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    PipelineField::factory()->for($pipeline)->create(['name' => 'imagen', 'input_type' => 'image']);
    Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])->fillForm(['inputs.describe_la_escena' => 'x'])
        ->set('inputs.imagen', ['forged' => 'tmp/foreign.png'])->call('generate')->assertHasFormErrors(['inputs.imagen']);
    expect(Generation::count())->toBe(0)->and(InputUpload::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('retains previous pieces when all generators are inactive and escapes campaign content', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    $campaign->update(['name' => '<script>alert(1)</script>']);
    $generation = Generation::factory()->for($campaign)->create(['status' => 'completed']);
    $piece = Piece::factory()->for($generation)->create();
    $generation->pipeline->update(['is_ready' => false]);

    Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])
        ->assertSee('Esta campaña no tiene generador configurado.')->assertSee(route('media.piece', $piece), false)
        ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Generar serie');
});

it('protects the server-owned campaign revision and UUID properties from updates', function (string $property, mixed $value): void {
    [$editor, , $campaign] = editorInCampaign();
    readyGenerator($campaign);
    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug]);
    expect(fn () => $page->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([['campaignId', 999], ['brandId', 999], ['formRevision', 99], ['requestId', 'forged'], ['restartRequestId', 'forged']]);

it('retains results and safely blocks submission when the selected generator becomes inactive', function (bool $hasAlternative): void {
    Queue::fake();
    [$editor, , $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    $campaign->update(['default_pipeline_id' => $pipeline->id]);
    $alternative = $hasAlternative ? readyGenerator($campaign) : null;
    $generation = Generation::factory()->for($pipeline)->for($campaign)->create(['status' => 'completed']);
    $piece = Piece::factory()->for($generation)->create();
    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])
        ->fillForm(['inputs.describe_la_escena' => 'sin enviar']);
    $requestId = $page->get('requestId');
    $pipeline->update(['is_ready' => false]);

    $page->call('refreshResults')->assertSee('Esta campaña no tiene generador configurado.')
        ->assertSee(route('media.piece', $piece), false)->assertDontSee('Generar serie')
        ->assertSet('pipelineId', $pipeline->id)->assertSet('formRevision', 3)
        ->assertSet('inputs.describe_la_escena', 'sin enviar')
        ->call('generate')->assertNotified('Esta campaña no tiene generador configurado.')
        ->assertSet('requestId', $requestId);

    expect(Generation::count())->toBe(1);
    Queue::assertNothingPushed();
    if ($alternative !== null) {
        $page->assertSee($alternative->label)->set('pipelineId', $alternative->id)
            ->assertSet('inputs.describe_la_escena', null)->assertSet('formRevision', $alternative->config_revision)
            ->assertSee('Generar serie');
    }
})->with([false, true]);

it('retains results and safely blocks submission when the selected generator is unassigned', function (bool $hasAlternative): void {
    Queue::fake();
    [$editor, , $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    $campaign->update(['default_pipeline_id' => $pipeline->id]);
    $alternative = $hasAlternative ? readyGenerator($campaign) : null;
    $generation = Generation::factory()->for($pipeline)->for($campaign)->create(['status' => 'completed']);
    $piece = Piece::factory()->for($generation)->create();
    $page = Livewire::actingAs($editor)->test(Generator::class, ['campaign' => $campaign->slug])
        ->fillForm(['inputs.describe_la_escena' => 'sin enviar']);
    $requestId = $page->get('requestId');
    $campaign->pipelines()->detach($pipeline);

    $page->call('refreshResults')->assertSee('Esta campaña no tiene generador configurado.')
        ->assertSee(route('media.piece', $piece), false)->assertDontSee('Generar serie')
        ->assertSet('pipelineId', $pipeline->id)->assertSet('formRevision', 3)
        ->assertSet('inputs.describe_la_escena', 'sin enviar')
        ->call('generate')->assertNotified('Esta campaña no tiene generador configurado.')
        ->assertSet('requestId', $requestId);

    expect(Generation::count())->toBe(1);
    Queue::assertNothingPushed();
    if ($alternative !== null) {
        $page->assertSee($alternative->label)->set('pipelineId', $alternative->id)
            ->assertSet('inputs.describe_la_escena', null)->assertSet('formRevision', $alternative->config_revision)
            ->assertSee('Generar serie');
    }
})->with([false, true]);

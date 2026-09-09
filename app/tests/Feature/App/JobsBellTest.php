<?php

use App\Jobs\PollGenerationJob;
use App\Jobs\RunGenerationJob;
use App\Livewire\JobsBell;
use App\Models\Generation;
use App\Models\GenerationJob;
use App\Models\Piece;
use App\Models\Pipeline;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

afterEach(fn () => Livewire::flushState());

it('uses a notification slide-over and separates active work from recent results', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    Generation::factory()->for($campaign)->for($editor)->create(['status' => 'processing']);
    $done = Generation::factory()->for($campaign)->for($editor)->create(['status' => 'completed']);
    $bell = Livewire::actingAs($editor)->test(JobsBell::class)
        ->assertSee('Cola de trabajos')->assertSee('En curso')->assertSee('Recientes')
        ->assertSee('fi-modal-slide-over', false)->assertSee('x-modal-opened', false)
        ->assertDontSee('fi-dropdown-trigger', false)->assertSet('activeCount', 1);
    expect($done->fresh()->seen_at)->toBeNull();
    $bell->call('markSeen')->assertSet('unseen', 0)->assertSet('activeCount', 1);
    expect($done->fresh()->seen_at)->not->toBeNull();
});

it('counts and marks only the displayed terminal jobs for this user and tenant', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    $pipeline = attachPipeline($campaign, Pipeline::factory()->create());
    $old = Generation::factory()->for($pipeline)->for($editor)->create(['status' => 'failed', 'created_at' => now()->subDay()]);
    $displayed = Generation::factory()->count(29)->for($pipeline)->for($editor)->create(['status' => 'completed']);
    $running = Generation::factory()->for($pipeline)->for($editor)->create(['status' => 'processing']);
    $colleague = Generation::factory()->for($pipeline)->create(['status' => 'failed']);
    $foreign = Generation::factory()->for($editor)->create(['status' => 'completed']);
    $editor->brands()->attach($foreign->campaign->brand_id);
    $bell = Livewire::actingAs($editor)->test(JobsBell::class)->assertSet('unseen', 29);
    $new = Generation::factory()->for($pipeline)->for($editor)->create(['status' => 'completed', 'created_at' => now()->addMinute()]);
    $bell->call('markSeen')->assertDispatched('jobs-updated')->assertSet('unseen', 1);
    expect($displayed->filter(fn ($generation) => $generation->fresh()->seen_at !== null))->toHaveCount(29);
    foreach ([$old, $running, $colleague, $foreign, $new] as $generation) {
        expect($generation->fresh()->seen_at)->toBeNull();
    }
});

it('keeps older active work visible ahead of a full page of recent results', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    $running = Generation::factory()->for($campaign)->for($editor)->create(['status' => 'processing', 'created_at' => now()->subDay()]);
    Generation::factory()->count(30)->for($campaign)->for($editor)->create(['status' => 'completed']);
    $bell = Livewire::actingAs($editor)->test(JobsBell::class)->assertSet('activeCount', 1);
    expect($bell->get('displayedIds'))->toContain($running->id)->toHaveCount(30);
});

it('renders job summaries statuses and at most three completed thumbnails', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    $pipeline = attachPipeline($campaign, Pipeline::factory()->create());
    $completed = Generation::factory()->for($pipeline)->for($editor)->create(['status' => 'completed', 'execution_snapshot' => snapshot(['inputs' => ['foto' => ['__upload' => 99]], 'bindings' => ['image' => 'foto']])]);
    $pieces = Piece::factory()->count(4)->for($completed)->create();
    Generation::factory()->for($pipeline)->for($editor)->create(['kind' => 'edit', 'status' => 'downloading', 'execution_snapshot' => snapshot(['inputs' => ['q' => 'quita la caja'], 'bindings' => ['prompt' => 'q']])]);
    Generation::factory()->for($pipeline)->for($editor)->create(['kind' => 'upscale', 'status' => 'failed', 'error_message' => '<script>falló</script>']);
    $bell = Livewire::actingAs($editor)->test(JobsBell::class)->assertSee('Cola de trabajos')->assertSee('1 imágenes')->assertSee('quita la caja')->assertSee('Descargando')->assertSee('Lista')->assertSee('Falló')->assertSee('Edición')->assertSee('4K')->assertSee('&lt;script&gt;falló&lt;/script&gt;', false)->assertSee('Puedes seguir trabajando; te avisamos al terminar.');
    foreach ($pieces->take(3) as $piece) {
        $bell->assertSee(route('media.piece', $piece), false);
    }
    $bell->assertDontSee(route('media.piece', $pieces->last()), false)->call('poll')->assertDispatched('jobs-updated');
});

it('renders an empty queue and refreshes on visibility changes', function (): void {
    [$editor] = editorInCampaign();
    Livewire::actingAs($editor)->test(JobsBell::class)->assertSee('Nada en la cola.')->assertSee('wire:poll.5s', false)->assertSee('@visibilitychange.document', false);
});

it('rejects revoked membership before marking or polling', function (string $action): void {
    [$editor, $brand, $campaign] = editorInCampaign();
    $generation = Generation::factory()->for($campaign)->for($editor)->create(['status' => 'completed']);
    $bell = Livewire::actingAs($editor)->test(JobsBell::class);
    $brand->users()->detach($editor);
    $bell->call($action)->assertForbidden();
    expect($generation->fresh()->seen_at)->toBeNull();
})->with(['markSeen', 'poll', '$refresh']);

it('requires confirmed restart and rejects another users recovery arguments', function (): void {
    Queue::fake();
    fakeEngine();
    [$editor, , $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    $failed = Generation::factory()->for($pipeline)->for($editor)->create(['status' => 'failed', 'retryable' => true, 'failure_reason' => 'submission_unknown']);
    $other = Generation::factory()->for($pipeline)->create(['status' => 'failed', 'retryable' => true, 'failure_reason' => 'download_failed']);
    $action = TestAction::make('restart')->arguments(['generationId' => $failed->id]);
    $bell = Livewire::actingAs($editor)->test(JobsBell::class)->mountAction($action)->assertActionMounted($action);
    expect($bell->instance()->getMountedAction()->getModalHeading())->toBe('¿Iniciar una nueva generación?');
    $bell->unmountAction();
    expect(Generation::where('restarted_from_generation_id', $failed->id)->count())->toBe(0);
    $bell->callAction($action);
    expect(Generation::where('restarted_from_generation_id', $failed->id)->count())->toBe(1);
    $bell->call('checkStatus', $other->id)->assertNotFound();
});

it('does not render a corrupt completed piece from another campaign', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    $generation = Generation::factory()->for($campaign)->for($editor)->create(['status' => 'completed']);
    $foreign = Piece::factory()->create();
    $foreign->forceFill(['generation_id' => $generation->id])->save();
    Livewire::actingAs($editor)->test(JobsBell::class)->assertDontSee(route('media.piece', $foreign), false);
});

it('keeps displayed ownership and restart IDs locked', function (string $property, mixed $value): void {
    [$editor] = editorInCampaign();
    expect(fn () => Livewire::actingAs($editor)->test(JobsBell::class)->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([['displayedIds', [999]], ['brandId', 999], ['restartRequestId', 'forged'], ['activeCount', 99]]);

it('replays confirmed restart delivery without creating a second replacement', function (): void {
    Queue::fake();
    fakeEngine();
    [$editor, , $campaign] = editorInCampaign();
    $failed = Generation::factory()->for(readyGenerator($campaign))->for($editor)->create(['status' => 'failed', 'retryable' => true, 'failure_reason' => 'provider_failed']);
    $action = TestAction::make('restart')->arguments(['generationId' => $failed->id]);
    $bell = Livewire::actingAs($editor)->test(JobsBell::class)->mountAction($action);
    $uuid = $bell->get('restartRequestId');
    $snapshot = $bell->snapshot;
    $bell->callMountedAction();
    $bell->snapshot = $snapshot;
    $bell->callMountedAction();
    expect(Generation::where('restarted_from_generation_id', $failed->id)->sole()->request_id)->toBe($uuid);
    expect($bell->get('restartRequestId'))->not->toBe($uuid);
});

it('rearms displayed failures without creating another generation', function (): void {
    Queue::fake([RunGenerationJob::class, PollGenerationJob::class]);
    [$editor, , $campaign] = editorInCampaign();
    $failed = Generation::factory()->for($campaign)->for($editor)->create(['status' => 'failed', 'retryable' => true, 'failure_reason' => 'poll_timeout']);
    GenerationJob::factory()->for($failed)->create(['normalized_status' => 'pending']);
    Livewire::actingAs($editor)->test(JobsBell::class)->assertSee('Comprobar estado')->call('checkStatus', $failed->id)->assertHasNoErrors();
    expect(Generation::count())->toBe(1);
    Queue::assertPushed(PollGenerationJob::class);
    Queue::assertNotPushed(RunGenerationJob::class);
});

it('rejects a changed role before disclosing queue summaries', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    $generation = Generation::factory()->for($campaign)->for($editor)->create(['status' => 'completed']);
    $bell = Livewire::actingAs($editor)->test(JobsBell::class);
    $editor->update(['role' => 'art_director']);
    $bell->call('markSeen')->assertForbidden();
    expect($generation->fresh()->seen_at)->toBeNull();
});

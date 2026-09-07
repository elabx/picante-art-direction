<?php

use App\Jobs\DownloadOutputJob;
use App\Jobs\PollGenerationJob;
use App\Jobs\RunGenerationJob;
use App\Livewire\PieceViewer;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\GenerationJob;
use App\Models\GenerationOutput;
use App\Models\InputUpload;
use App\Models\Piece;
use App\Models\Pipeline;
use App\Models\PipelineField;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

afterEach(fn () => Livewire::flushState());

function viewerPipeline(Campaign $campaign, string $kind): Pipeline
{
    $pipeline = Pipeline::factory()->for($campaign)->create(['kind' => $kind, 'is_active' => true, 'readiness_errors' => [], 'input_schema' => ['properties' => []]]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'foto', 'input_type' => 'image', 'role' => 'image', 'required' => true]);
    if ($kind === 'editor') {
        PipelineField::factory()->for($pipeline)->create(['name' => 'q', 'input_type' => 'string', 'role' => 'prompt', 'required' => true]);
    }

    return $pipeline;
}

it('opens authorized media and explains missing command pipelines', function (): void {
    Queue::fake();
    [$editor, , $campaign] = editorInCampaign();
    $piece = Piece::factory()->for(Generation::factory()->for($campaign))->create();
    $viewer = Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id);
    $uuid = $viewer->get('editRequestId');
    $viewer->assertSee('1024 × 1024')->assertSee(route('media.piece', $piece), false)
        ->assertSee('Esta campaña no tiene editor configurado.')->assertSee('Esta campaña no tiene upscaler configurado.')
        ->assertActionDisabled('upscale')->set('instruction', 'quita la caja')->call('applyEdit')->assertHasErrors('generation')->assertSet('editRequestId', $uuid);
    expect(Generation::where('kind', 'edit')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('submits edits and upscales with stable replay IDs and fresh IDs for subsequent commands', function (string $kind): void {
    Queue::fake();
    [$editor, , $campaign] = editorInCampaign();
    $piece = Piece::factory()->for(Generation::factory()->for($campaign))->create(['width' => 3840, 'height' => 2160]);
    viewerPipeline($campaign, $kind === 'edit' ? 'editor' : 'upscaler');
    $viewer = Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)->set('instruction', 'quita la caja');
    $property = $kind === 'edit' ? 'editRequestId' : 'upscaleRequestId';
    $uuid = $viewer->get($property);
    $original = $viewer->snapshot;
    $submit = function () use ($viewer, $kind): void {
        $kind === 'edit' ? $viewer->call('applyEdit')->assertNotified('Edición enviada.') : $viewer->callAction('upscale');
    };
    if ($kind === 'upscale') {
        $viewer->assertSee('Esta versión ya alcanza el tamaño de entrega.')->assertActionEnabled('upscale')
            ->mountAction('upscale')->assertActionMounted('upscale');
        expect($viewer->instance()->getMountedAction()->getModalDescription())->toBe('Se generará una versión con 3.840 píxeles en el lado mayor, conservando la proporción.');
        $viewer->unmountAction();
    }
    $submit();
    expect($viewer->get($property))->not->toBe($uuid);
    expect(Generation::where('kind', $kind)->sole()->request_id)->toBe($uuid);
    $viewer->snapshot = $original;
    $submit();
    expect(Generation::where('kind', $kind)->count())->toBe(1);
    $submit();
    expect(Generation::where('kind', $kind)->count())->toBe(2);
})->with(['edit', 'upscale']);

it('toggles shared selection and downloads through a fresh authorized route', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    $piece = Piece::factory()->for(Generation::factory()->for($campaign))->create();
    $viewer = Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)->call('toggleSelected');
    expect($piece->fresh()->selected)->toBeTrue();
    $viewer->call('toggleSelected')->call('download')->assertRedirect(route('media.piece', [$piece, 'download' => 1]));
    expect($piece->fresh()->selected)->toBeFalse();
});

it('rejects pieces in another tenant even with membership there', function (): void {
    [$editor] = editorInCampaign();
    $foreign = Piece::factory()->create();
    $editor->brands()->attach($foreign->campaign->brand_id);
    Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $foreign->id)->assertForbidden();
});

it('reauthorizes membership and role before updates and actions', function (string $change, string $action): void {
    Queue::fake();
    [$editor, $brand, $campaign] = editorInCampaign();
    $piece = Piece::factory()->for(Generation::factory()->for($campaign))->create();
    $viewer = Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id);
    if ($change === 'membership') {
        $brand->users()->detach($editor);
    } else {
        $editor->update(['role' => 'art_director']);
    }
    $viewer->call($action)->assertForbidden();
    expect($piece->fresh()->selected)->toBeFalse();
    Queue::assertNothingPushed();
})->with(['membership', 'role'])->with(['toggleSelected', 'download', 'applyEdit', '$refresh']);

it('switches only to new direct children of the currently viewed version', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    $generation = Generation::factory()->for($campaign)->create();
    $root = Piece::factory()->for($generation)->create();
    $old = Piece::factory()->for($generation)->create(['root_piece_id' => $root->id, 'parent_piece_id' => $root->id]);
    $viewer = Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $root->id)->call('refreshResults')->assertSet('pieceId', $root->id);
    $new = Piece::factory()->for($generation)->create(['root_piece_id' => $root->id, 'parent_piece_id' => $root->id, 'is_4k' => true]);
    $viewer->dispatch('jobs-updated')->assertSet('pieceId', $new->id)->assertSee('v03')->assertSee('4K');
    $viewer->call('selectVersion', $old->id);
    Piece::factory()->for($generation)->create(['root_piece_id' => $root->id, 'parent_piece_id' => $root->id]);
    $viewer->call('refreshResults')->assertSet('pieceId', $old->id);
    $foreign = Piece::factory()->create(['root_piece_id' => $root->id]);
    $viewer->call('selectVersion', $foreign->id)->assertForbidden();
});

it('renders escaped originating labels and only authorized upload previews', function (): void {
    [$editor, $brand, $campaign] = editorInCampaign();
    $own = InputUpload::factory()->for($brand)->for($editor)->create();
    $other = InputUpload::factory()->for($brand)->create();
    $fixed = InputUpload::factory()->for($brand)->create();
    $pipeline = Pipeline::factory()->for($campaign)->create();
    $pipeline->inputUploads()->attach($fixed);
    $generation = Generation::factory()->for($pipeline)->create(['execution_snapshot' => snapshot(['inputs' => ['q' => '<script>alert(1)</script>', 'own' => ['__upload' => $own->id], 'other' => ['__upload' => $other->id], 'fixed' => ['__upload' => $fixed->id]], 'labels' => ['q' => 'La escena']])]);
    $piece = Piece::factory()->for($generation)->create();
    Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)
        ->assertSee('La escena')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertSee(route('media.upload', $own), false)->assertSee(route('media.upload', $fixed), false)
        ->assertDontSee(route('media.upload', $other), false)->assertDontSee($piece->source_url, false);
});

it('keeps historical viewing available while deleted campaigns reject new commands', function (): void {
    Queue::fake();
    [$editor, , $campaign] = editorInCampaign();
    $piece = Piece::factory()->for(Generation::factory()->for($campaign))->create();
    viewerPipeline($campaign, 'editor');
    $campaign->delete();
    Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)->assertSee('1024 × 1024')->set('instruction', 'x')->call('applyEdit')->assertHasErrors('generation');
    expect(Generation::where('kind', 'edit')->count())->toBe(0);
});

it('polls running versions and requires confirmation before restarting a failed edit', function (): void {
    Queue::fake();
    fakeEngine();
    [$editor, , $campaign] = editorInCampaign();
    $piece = Piece::factory()->for(Generation::factory()->for($campaign))->create();
    $pipeline = viewerPipeline($campaign, 'editor');
    $failed = Generation::factory()->for($pipeline)->for($editor)->create(['kind' => 'edit', 'parent_piece_id' => $piece->id, 'status' => 'failed', 'retryable' => true, 'failure_reason' => 'submission_unknown']);
    $action = TestAction::make('restart')->arguments(['generationId' => $failed->id]);
    $viewer = Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)->assertSee('Generar de nuevo');
    $uuid = $viewer->get('restartRequestId');
    $viewer->mountAction($action)->assertActionMounted($action);
    expect($viewer->instance()->getMountedAction()->getModalHeading())->toBe('¿Iniciar una nueva generación?');
    expect($viewer->instance()->getMountedAction()->getModalDescription())->toStartWith('El trabajo anterior podría seguir en curso.');
    $viewer->unmountAction();
    expect(Generation::where('restarted_from_generation_id', $failed->id)->count())->toBe(0);
    $viewer->callAction($action)->assertSee('Aplicando…')->assertSee('wire:poll.3s', false);
    expect(Generation::where('restarted_from_generation_id', $failed->id)->sole()->request_id)->toBe($uuid);
    expect($viewer->get('restartRequestId'))->not->toBe($uuid);
});

it('recovers failed child work without paying for a new generation', function (string $reason, string $label): void {
    Queue::fake([RunGenerationJob::class, PollGenerationJob::class, DownloadOutputJob::class]);
    fakeEngine();
    [$editor, , $campaign] = editorInCampaign();
    $piece = Piece::factory()->for(Generation::factory()->for($campaign))->create();
    $generation = Generation::factory()->for($campaign)->for($editor)->create(['kind' => 'edit', 'parent_piece_id' => $piece->id, 'status' => 'failed', 'retryable' => true, 'failure_reason' => $reason]);
    $job = GenerationJob::factory()->for($generation)->create(['normalized_status' => $reason === 'download_failed' ? 'completed' : 'pending']);
    if ($reason === 'download_failed') {
        $output = GenerationOutput::factory()->for($generation)->for($job, 'job')->create(['status' => 'failed', 'attempts' => 3]);
    }
    Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)->assertSee($label)->call('checkStatus', $generation->id)->assertHasNoErrors();
    expect(Generation::where('kind', 'edit')->count())->toBe(1);
    if ($reason === 'download_failed') {
        expect($output->fresh()->status->value)->toBe('pending');
        Queue::assertPushed(DownloadOutputJob::class);
    } else {
        Queue::assertPushed(PollGenerationJob::class);
    }
    Queue::assertNotPushed(RunGenerationJob::class);
})->with([['poll_timeout', 'Comprobar estado'], ['download_failed', 'Reintentar descarga']]);

it('rejects recovery and restart IDs outside the viewed version chain', function (bool $restart): void {
    Queue::fake();
    [$editor, , $campaign] = editorInCampaign();
    $piece = Piece::factory()->for(Generation::factory()->for($campaign))->create();
    $other = Piece::factory()->for(Generation::factory()->for($campaign))->create();
    $failed = Generation::factory()->for($campaign)->for($editor)->create(['kind' => 'edit', 'parent_piece_id' => $other->id, 'status' => 'failed', 'retryable' => true, 'failure_reason' => 'download_failed']);
    $viewer = Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id);
    if ($restart) {
        $viewer->mountAction(TestAction::make('restart')->arguments(['generationId' => $failed->id]))->assertNotFound();
    } else {
        $viewer->call('checkStatus', $failed->id)->assertNotFound();
    }
    expect(Generation::where('restarted_from_generation_id', $failed->id)->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([false, true]);

it('rejects a stored foreign root before rendering its media', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    $foreign = Piece::factory()->create();
    $piece = Piece::factory()->for(Generation::factory()->for($campaign))->create(['root_piece_id' => $foreign->id]);
    Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)->assertForbidden();
});

it('refuses edits with an empty instruction and retains the request UUID', function (): void {
    Queue::fake();
    [$editor, , $campaign] = editorInCampaign();
    $piece = Piece::factory()->for(Generation::factory()->for($campaign))->create();
    viewerPipeline($campaign, 'editor');
    $viewer = Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id);
    $uuid = $viewer->get('editRequestId');
    $viewer->call('applyEdit')->assertHasErrors()->assertSet('editRequestId', $uuid);
    expect(Generation::where('kind', 'edit')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('keeps viewer identity and command IDs locked against client mutation', function (string $property, mixed $value): void {
    [$editor] = editorInCampaign();
    expect(fn () => Livewire::actingAs($editor)->test(PieceViewer::class)->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([['pieceId', 999], ['brandId', 999], ['editRequestId', 'forged'], ['upscaleRequestId', 'forged'], ['restartRequestId', 'forged']]);

it('retains the viewed piece when command pipelines become inactive', function (): void {
    Queue::fake();
    [$editor, , $campaign] = editorInCampaign();
    $piece = Piece::factory()->for(Generation::factory()->for($campaign))->create();
    $edit = viewerPipeline($campaign, 'editor');
    $upscale = viewerPipeline($campaign, 'upscaler');
    $viewer = Livewire::actingAs($editor)->test(PieceViewer::class)->dispatch('open-piece', pieceId: $piece->id)->set('instruction', 'quita la caja');
    $edit->update(['is_active' => false]);
    $upscale->update(['is_active' => false]);
    $viewer->dispatch('jobs-updated')->assertSet('pieceId', $piece->id)->assertSet('instruction', 'quita la caja')
        ->assertSee('Esta campaña no tiene editor configurado.')->assertSee('Esta campaña no tiene upscaler configurado.')
        ->assertActionDisabled('upscale')->call('applyEdit')->assertHasErrors('generation');
    expect(Generation::whereIn('kind', ['edit', 'upscale'])->count())->toBe(0);
    Queue::assertNothingPushed();
});

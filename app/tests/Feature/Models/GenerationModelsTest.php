<?php

use App\Enums\GenerationStatus;
use App\Enums\PieceKind;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\GenerationJob;
use App\Models\GenerationOutput;
use App\Models\InputUpload;
use App\Models\Piece;
use App\Models\Pipeline;
use Illuminate\Database\QueryException;

it('builds a version chain from root through edits and upscales', function () {
    $campaign = Campaign::factory()->create();
    $gen = Generation::factory()->for($campaign)->create();
    $root = Piece::factory()->for($gen)->for($campaign)->create(['kind' => PieceKind::Original]);
    $edit = Piece::factory()->for($gen)->for($campaign)->create(['kind' => PieceKind::Edit, 'parent_piece_id' => $root->id, 'root_piece_id' => $root->rootId()]);
    $up = Piece::factory()->for($gen)->for($campaign)->create(['kind' => PieceKind::Upscale, 'parent_piece_id' => $edit->id, 'root_piece_id' => $edit->rootId()]);
    $edit2 = Piece::factory()->for($gen)->for($campaign)->create(['kind' => PieceKind::Edit, 'parent_piece_id' => $up->id, 'root_piece_id' => $up->rootId()]);

    expect($up->root_piece_id)->toBe($root->id)
        ->and($edit2->versionChain()->pluck('id')->all())->toBe([$root->id, $edit->id, $up->id, $edit2->id]);
});

it('keeps output identity unique within a generation job', function () {
    $job = GenerationJob::factory()->create();
    $outputs = GenerationOutput::factory()->count(2)->for($job, 'job')->create();

    expect($outputs->pluck('generation_id')->all())->toBe([$job->generation_id, $job->generation_id])
        ->and($outputs->pluck('index')->all())->toBe([0, 1])
        ->and(fn () => GenerationOutput::factory()->for($job, 'job')->create(['index' => 0]))
        ->toThrow(QueryException::class);
});

it('does not allow a brand with uploads to be hard deleted', function () {
    $brand = Brand::factory()->create();
    InputUpload::factory()->for($brand)->create();

    try {
        $brand->delete();
    } catch (QueryException) {
        $this->assertModelExists($brand);

        return;
    }

    $this->fail('A brand with input uploads must not be deleted.');
});

it('builds a coherent default generation output and piece graph', function () {
    $piece = Piece::factory()->create();

    expect($piece->generation->campaign_id)->toBe($piece->campaign_id)
        ->and($piece->output->generation_id)->toBe($piece->generation_id)
        ->and($piece->output->job->generation_id)->toBe($piece->generation_id)
        ->and($piece->output->piece->id)->toBe($piece->id)
        ->and($piece->generation->pipeline->campaign_id)->toBe($piece->campaign_id);
});

it('uses a supplied pipeline campaign for a generation', function () {
    $pipeline = Pipeline::factory()->create();
    $generation = Generation::factory()->for($pipeline)->create();

    expect($generation->campaign_id)->toBe($pipeline->campaign_id);
});

it('uses a supplied output generation and campaign for a piece', function () {
    $output = GenerationOutput::factory()->create();
    $piece = Piece::factory()->for($output, 'output')->create();

    expect($piece->generation_id)->toBe($output->generation_id)
        ->and($piece->campaign_id)->toBe($output->generation->campaign_id);
});

it('finds non-terminal generations and identifies terminal jobs', function () {
    $pending = Generation::factory()->create(['status' => GenerationStatus::Pending]);
    $completed = Generation::factory()->create(['status' => GenerationStatus::Completed]);
    $completedJob = GenerationJob::factory()->create(['normalized_status' => 'completed']);
    $pendingJob = GenerationJob::factory()->create(['normalized_status' => 'pending']);

    expect(Generation::nonTerminal()->pluck('id')->all())->toContain($pending->id)
        ->not->toContain($completed->id)
        ->and($completedJob->isTerminal())->toBeTrue()
        ->and($pendingJob->isTerminal())->toBeFalse();
});

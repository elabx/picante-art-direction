<?php

use App\Models\Campaign;
use App\Models\Pipeline;
use App\Services\Pipelines\CampaignPipelineAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('assigns a ready app with an order and takes a campaign row lock', function (): void {
    $sqls = [];
    DB::listen(function ($query) use (&$sqls): void {
        $sqls[] = $query->sql;
    });
    $campaign = Campaign::factory()->create();
    $pipeline = Pipeline::factory()->generator()->ready()->create();

    app(CampaignPipelineAssignment::class)->assign($campaign, $pipeline, 4);

    expect($campaign->pipelines()->first()?->pivot->sort_order)->toBe(4)
        ->and(collect($sqls)->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'for update')))->toBeTrue();
});

it('rejects apps that are not ready or already assigned', function (): void {
    $campaign = Campaign::factory()->create();
    $notReady = Pipeline::factory()->generator()->create();
    $ready = Pipeline::factory()->generator()->ready()->create();
    $service = app(CampaignPipelineAssignment::class);

    expect(fn () => $service->assign($campaign, $notReady))->toThrow(ValidationException::class);
    $service->assign($campaign, $ready);
    expect(fn () => $service->assign($campaign, $ready))->toThrow(ValidationException::class)
        ->and($campaign->pipelines()->count())->toBe(1);
});

it('allows several generators but one editor and one upscaler per campaign', function (): void {
    $campaign = Campaign::factory()->create();
    $service = app(CampaignPipelineAssignment::class);

    $service->assign($campaign, Pipeline::factory()->generator()->ready()->create());
    $service->assign($campaign, Pipeline::factory()->generator()->ready()->create());
    $service->assign($campaign, Pipeline::factory()->editor()->ready()->create());
    $service->assign($campaign, Pipeline::factory()->upscaler()->ready()->create());

    try {
        $service->assign($campaign, Pipeline::factory()->editor()->ready()->create());
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['pipeline'])->toBe(['Ya hay un editor activo en esta campaña.']);
    }
    expect(fn () => $service->assign($campaign, Pipeline::factory()->upscaler()->ready()->create()))->toThrow(ValidationException::class)
        ->and($campaign->pipelines()->count())->toBe(4);
});

it('allows the same app in several campaigns', function (): void {
    $pipeline = Pipeline::factory()->editor()->ready()->create();
    $service = app(CampaignPipelineAssignment::class);

    $service->assign(Campaign::factory()->create(), $pipeline);
    $service->assign(Campaign::factory()->create(), $pipeline);

    expect($pipeline->campaigns()->count())->toBe(2);
});

it('removes an assignment and clears the default generator', function (): void {
    $campaign = Campaign::factory()->create();
    $pipeline = Pipeline::factory()->generator()->ready()->create();
    $service = app(CampaignPipelineAssignment::class);
    $service->assign($campaign, $pipeline);
    $campaign->update(['default_pipeline_id' => $pipeline->id]);

    $service->remove($campaign, $pipeline);

    expect($campaign->pipelines()->count())->toBe(0)
        ->and($campaign->fresh()->default_pipeline_id)->toBeNull()
        ->and(Pipeline::query()->whereKey($pipeline->id)->exists())->toBeTrue();
});

it('reorders only assigned apps', function (): void {
    $campaign = Campaign::factory()->create();
    $pipeline = Pipeline::factory()->generator()->ready()->create();
    $service = app(CampaignPipelineAssignment::class);
    $service->assign($campaign, $pipeline, 1);

    $service->reorder($campaign, $pipeline, 7);
    expect($campaign->pipelines()->first()?->pivot->sort_order)->toBe(7);

    expect(fn () => $service->reorder($campaign, Pipeline::factory()->ready()->create(), 1))->toThrow(ValidationException::class);
});

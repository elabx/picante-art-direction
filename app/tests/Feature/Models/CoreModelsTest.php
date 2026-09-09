<?php

use App\Enums\FieldRole;
use App\Enums\FieldVisibility;
use App\Enums\InputType;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\InputUpload;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('links editors to brands and exposes ready pipelines by kind through the pivot', function () {
    $brand = Brand::factory()->create(['krea_api_key' => 'test-secret-key']);
    $editor = User::factory()->editor()->create();
    $brand->users()->attach($editor);
    $campaign = Campaign::factory()->for($brand)->create();
    $secondGenerator = attachPipeline($campaign, Pipeline::factory()->generator()->ready()->create(), 2);
    $firstGenerator = attachPipeline($campaign, Pipeline::factory()->generator()->ready()->create(), 1);
    attachPipeline($campaign, Pipeline::factory()->generator()->create());
    attachPipeline($campaign, Pipeline::factory()->editor()->create());
    attachPipeline($campaign, Pipeline::factory()->upscaler()->create());
    $editorPipeline = attachPipeline($campaign, Pipeline::factory()->editor()->ready()->create());
    $upscaler = attachPipeline($campaign, Pipeline::factory()->upscaler()->ready()->create());
    Pipeline::factory()->generator()->ready()->create();

    expect($editor->fresh()->brands)->toHaveCount(1)
        ->and($editor->fresh()->isArtDirector())->toBeFalse()
        ->and($campaign->activeGenerators()->pluck('pipelines.id')->all())->toBe([$firstGenerator->id, $secondGenerator->id])
        ->and($campaign->activeEditor()?->id)->toBe($editorPipeline->id)
        ->and($campaign->activeUpscaler()?->id)->toBe($upscaler->id)
        ->and($campaign->pipelines()->count())->toBe(7)
        ->and($firstGenerator->campaigns()->pluck('campaigns.id')->all())->toBe([$campaign->id])
        ->and($brand->resolveKreaKeySource())->toBe('brand')
        ->and(DB::table('brands')->where('id', $brand->id)->value('krea_api_key'))->not->toBe('test-secret-key')
        ->and($brand->toArray())->not->toHaveKey('krea_api_key');
});

it('returns non-stale pipeline fields in stable configuration order', function () {
    $pipeline = Pipeline::factory()->create(['is_ready' => true, 'readiness_errors' => []]);
    $laterField = PipelineField::factory()->for($pipeline)->create(['name' => 'later', 'sort_order' => 2]);
    $firstSameOrderField = PipelineField::factory()->for($pipeline)->create(['name' => 'first', 'sort_order' => 1]);
    $secondSameOrderField = PipelineField::factory()->for($pipeline)->create(['name' => 'second', 'sort_order' => 1]);
    PipelineField::factory()->for($pipeline)->create(['name' => 'stale', 'stale' => true]);

    expect($pipeline->activeFields()->pluck('id')->all())->toBe([
        $firstSameOrderField->id,
        $secondSameOrderField->id,
        $laterField->id,
    ])
        ->and($pipeline->isReady())->toBeTrue()
        ->and($firstSameOrderField->fresh()->input_type)->toBe(InputType::String)
        ->and($firstSameOrderField->fresh()->visibility)->toBe(FieldVisibility::Visible)
        ->and($firstSameOrderField->fresh()->role)->toBe(FieldRole::None)
        ->and($firstSameOrderField->fresh()->needs_configuration)->toBeFalse();
});

it('refuses to delete a catalog app that a campaign still uses', function () {
    $campaign = Campaign::factory()->create();
    $pipeline = attachPipeline($campaign, Pipeline::factory()->create());

    expect(fn () => $pipeline->delete())->toThrow(QueryException::class);
    $this->assertModelExists($pipeline);
});

it('shares catalog apps across campaigns with independent stable ordering', function () {
    $campaign = Campaign::factory()->create();
    $otherCampaign = Campaign::factory()->create();
    $first = Pipeline::factory()->ready()->create();
    $second = Pipeline::factory()->ready()->create();
    attachPipeline($campaign, $second, 1);
    attachPipeline($campaign, $first, 1);
    attachPipeline($otherCampaign, $first, 2);
    attachPipeline($otherCampaign, $second, 0);

    expect($campaign->activeGenerators()->pluck('pipelines.id')->all())->toBe([$first->id, $second->id])
        ->and($otherCampaign->activeGenerators()->pluck('pipelines.id')->all())->toBe([$second->id, $first->id])
        ->and($first->campaigns()->count())->toBe(2);
});

it('stores catalog uploads without a brand', function () {
    $upload = InputUpload::factory()->catalog()->create()->refresh();

    expect($upload->brand_id)->toBeNull()
        ->and($upload->brand)->toBeNull()
        ->and($upload->storage_path)->toStartWith('catalog/');
});

it('rejects duplicate provider references within an engine', function () {
    Pipeline::factory()->create(['provider_ref' => 'shared-app']);

    expect(fn () => Pipeline::factory()->create(['provider_ref' => 'shared-app']))->toThrow(QueryException::class);
});

it('does not allow a referenced brand to be hard deleted', function () {
    $brand = Brand::factory()->create();
    Campaign::factory()->for($brand)->create();

    try {
        $brand->delete();
    } catch (QueryException) {
        $this->assertModelExists($brand);

        return;
    }

    $this->fail('A brand with campaigns must not be deleted.');
});

it('removes memberships when an unreferenced brand is deleted', function () {
    $brand = Brand::factory()->create();
    $editor = User::factory()->editor()->create();
    $brand->users()->attach($editor);

    $brand->delete();

    $this->assertDatabaseMissing('brand_user', [
        'brand_id' => $brand->id,
        'user_id' => $editor->id,
    ]);
    $this->assertModelMissing($brand);
});

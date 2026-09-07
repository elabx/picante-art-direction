<?php

use App\Enums\FieldRole;
use App\Enums\FieldVisibility;
use App\Enums\InputType;
use App\Enums\PipelineKind;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('links editors to brands and exposes active pipelines by kind', function () {
    $brand = Brand::factory()->create(['krea_api_key' => 'test-secret-key']);
    $editor = User::factory()->editor()->create();
    $brand->users()->attach($editor);
    $campaign = Campaign::factory()->for($brand)->create();
    $secondGenerator = Pipeline::factory()->for($campaign)->create([
        'kind' => PipelineKind::Generator,
        'is_active' => true,
        'sort_order' => 2,
    ]);
    $firstGenerator = Pipeline::factory()->for($campaign)->create([
        'kind' => PipelineKind::Generator,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    Pipeline::factory()->for($campaign)->create(['kind' => PipelineKind::Editor, 'is_active' => true]);
    Pipeline::factory()->for($campaign)->create(['kind' => PipelineKind::Upscaler, 'is_active' => false]);

    expect($editor->fresh()->brands)->toHaveCount(1)
        ->and($editor->fresh()->isArtDirector())->toBeFalse()
        ->and($campaign->activeGenerators()->pluck('id')->all())->toBe([$firstGenerator->id, $secondGenerator->id])
        ->and($campaign->activeEditor())->not->toBeNull()
        ->and($campaign->activeUpscaler())->toBeNull()
        ->and($brand->resolveKreaKeySource())->toBe('brand')
        ->and(DB::table('brands')->where('id', $brand->id)->value('krea_api_key'))->not->toBe('test-secret-key')
        ->and($brand->toArray())->not->toHaveKey('krea_api_key');
});

it('returns non-stale pipeline fields in stable configuration order', function () {
    $pipeline = Pipeline::factory()->create(['is_active' => true, 'readiness_errors' => []]);
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

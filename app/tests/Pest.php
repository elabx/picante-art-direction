<?php

use App\Engines\FakeEngine;
use App\Enums\FieldRole;
use App\Enums\InputType;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use Database\Factories\GenerationFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function fakeEngine(): FakeEngine
{
    return app()->instance(FakeEngine::class, new FakeEngine);
}

/** @param array<string, mixed> $overrides */
function snapshot(array $overrides = []): array
{
    return GenerationFactory::snapshot($overrides);
}

function attachPipeline(Campaign $campaign, Pipeline $pipeline, int $sortOrder = 0): Pipeline
{
    $campaign->pipelines()->attach($pipeline->id, ['sort_order' => $sortOrder]);

    return $pipeline;
}

function readyGenerator(Campaign $campaign): Pipeline
{
    $pipeline = Pipeline::factory()->generator()->ready()->create([
        'input_schema' => ['properties' => []],
        'config_revision' => 3,
    ]);

    PipelineField::factory()->for($pipeline)->create([
        'name' => 'describe_la_escena',
        'input_type' => InputType::String,
        'role' => FieldRole::Prompt,
        'required' => true,
    ]);

    attachPipeline($campaign, $pipeline);

    return $pipeline->refresh();
}

/** @return array{User, Brand, Campaign} */
function editorInCampaign(): array
{
    $editor = User::factory()->editor()->create();
    $brand = Brand::factory()->create();
    $brand->users()->attach($editor);
    $campaign = Campaign::factory()->for($brand)->create();
    \Pest\Laravel\actingAs($editor);
    Filament::setTenant($brand);

    return [$editor, $brand, $campaign];
}

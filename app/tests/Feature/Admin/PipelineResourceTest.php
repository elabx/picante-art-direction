<?php

use App\Filament\Admin\Resources\Pipelines\Pages\EditPipeline;
use App\Filament\Admin\Resources\Pipelines\RelationManagers\FieldsRelationManager;
use App\Models\Campaign;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use App\Services\Media\InputUploadService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('finalizes fixed images and preserves shared references when replacing and unsetting them', function (): void {
    Storage::fake('inputs');
    Storage::disk('inputs')->put('tmp/base.png', file_get_contents(base_path('tests/Fixtures/images/tiny.png')));
    $this->actingAs(User::factory()->artDirector()->create());
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    $field = PipelineField::factory()->for($pipeline)->create(['name' => 'base', 'input_type' => 'image', 'visibility' => 'hidden', 'required' => true, 'needs_configuration' => true]);
    $component = Livewire::test(FieldsRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class]);
    $component->callAction(TestAction::make('edit')->table($field), data: ['has_fixed_value' => true, 'fixed_value' => ['tmp/base.png']])->assertHasNoActionErrors();
    $field->refresh();
    expect($field->fixed_value)->toHaveKey('__upload')->and($field->needs_configuration)->toBeFalse()
        ->and($pipeline->inputUploads()->count())->toBe(1)->and($pipeline->fresh()->config_revision)->toBe(2);
    $original = $field->fixed_value;
    $shared = PipelineField::factory()->for($pipeline)->create(['input_type' => 'image', 'has_fixed_value' => true, 'fixed_value' => $original]);
    $component->callAction(TestAction::make('edit')->table($field), data: ['label_override' => 'Base'])->assertHasNoActionErrors();
    expect($field->fresh()->fixed_value)->toBe($original);
    Storage::disk('inputs')->put('tmp/other.png', file_get_contents(base_path('tests/Fixtures/images/tiny.png')));
    $component->callAction(TestAction::make('edit')->table($field), data: ['fixed_value' => ['tmp/other.png']])->assertHasNoActionErrors();
    expect($field->fresh()->fixed_value)->not->toBe($original)->and($pipeline->inputUploads()->count())->toBe(2);
    $component->callAction(TestAction::make('edit')->table($shared), data: ['has_fixed_value' => false])->assertHasNoActionErrors();
    expect($pipeline->inputUploads()->count())->toBe(1)->and($pipeline->fresh()->config_revision)->toBe(5);
});

it('invalidates active defaults and increments fresh revision once without accepting forged schema fields', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $pipeline = readyGenerator($campaign);
    $campaign->update(['default_pipeline_id' => $pipeline->id]);
    $field = $pipeline->fields()->sole();
    $component = Livewire::test(FieldsRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class]);
    $pipeline->update(['config_revision' => 10]);
    $component->callAction(TestAction::make('edit')->table($field), data: ['has_fixed_value' => true, 'fixed_value' => 'hello', 'name' => 'forged', 'required' => false, 'source_schema' => ['type' => 'boolean']])->assertHasNoActionErrors();
    expect($field->fresh()->name)->toBe('describe_la_escena')->and($field->fresh()->required)->toBeTrue()
        ->and($field->fresh()->source_schema)->toBe(['type' => 'string'])
        ->and($pipeline->fresh()->config_revision)->toBe(11)->and($pipeline->fresh()->is_ready)->toBeFalse()
        ->and($pipeline->fresh()->readiness_errors)->not->toBeEmpty()->and($campaign->fresh()->default_pipeline_id)->toBeNull();
});

it('parses valid scalar JSON fixed values strictly and keeps other text', function (string $input, mixed $expected): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    $field = PipelineField::factory()->for($pipeline)->create();
    Livewire::test(FieldsRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
        ->callAction(TestAction::make('edit')->table($field), data: ['has_fixed_value' => true, 'fixed_value' => $input])->assertHasNoActionErrors();
    expect($field->fresh()->fixed_value)->toBe($expected);
})->with([['null', null], ['false', false], ['0', 0], ['1.5', 1.5], ['"word"', 'word'], ['ordinary text', 'ordinary text']]);

it('updates schema and automatically enables valid apps without exposing raw schema state', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $pipeline = Pipeline::factory()->create(['input_schema' => ['private-marker' => true]]);
    fakeEngine()->withSchema($pipeline->provider_ref, ['properties' => ['prompt' => ['type' => 'string']]]);
    $component = Livewire::test(EditPipeline::class, ['record' => $pipeline->id])->assertDontSee('private-marker');
    $component->callAction('refresh')->assertNotified();
    expect($pipeline->fresh()->is_ready)->toBeTrue()->and($pipeline->fields()->count())->toBe(1);
    $component->set('data.provider_ref', 'forged')->set('data.input_schema', ['forged' => true])->call('save');
    expect($pipeline->fresh()->provider_ref)->toBe($pipeline->provider_ref)->and($pipeline->fresh()->input_schema)->not->toHaveKey('forged');
});

it('denies field mutation and pipeline header calls after role revocation', function (): void {
    $user = User::factory()->artDirector()->create();
    $this->actingAs($user);
    $pipeline = Pipeline::factory()->create();
    $field = PipelineField::factory()->for($pipeline)->create();
    $fields = Livewire::test(FieldsRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class]);
    $page = Livewire::test(EditPipeline::class, ['record' => $pipeline->id]);
    $fields->mountAction(TestAction::make('edit')->table($field));
    $user->update(['role' => 'editor']);
    $fields->call('callMountedAction')->assertForbidden();
    $page->call('mountAction', 'refresh')->assertForbidden();
    expect($pipeline->fresh()->config_revision)->toBe(1);
});

it('preserves fixed JSON types when reopening and saving without editing the value', function (mixed $value): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    $field = PipelineField::factory()->for($pipeline)->create(['has_fixed_value' => true, 'fixed_value' => $value]);
    Livewire::test(FieldsRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
        ->callAction(TestAction::make('edit')->table($field), data: ['help_text' => 'Nueva ayuda'])->assertHasNoActionErrors();
    expect($field->fresh()->fixed_value)->toBe($value);
})->with([[null], [false], [0], [1.5], ['true'], ['null'], ['"quoted"'], [['a' => [1, false]]]]);

it('rejects another pipelines image path without changing the field or revision', function (): void {
    Storage::fake('inputs');
    $this->actingAs($user = User::factory()->artDirector()->create());
    $pipeline = Pipeline::factory()->create(['input_schema' => ['properties' => []]]);
    $field = PipelineField::factory()->for($pipeline)->create(['input_type' => 'image']);
    Storage::disk('inputs')->put('tmp/foreign.png', file_get_contents(base_path('tests/Fixtures/images/tiny.png')));
    $foreign = app(InputUploadService::class)->finalizeForCatalog('tmp/foreign.png', $user);
    Livewire::test(FieldsRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
        ->callAction(TestAction::make('edit')->table($field), data: ['has_fixed_value' => true, 'fixed_value' => [$foreign->storage_path]])
        ->assertHasActionErrors(['fixed_value']);
    expect($field->fresh()->has_fixed_value)->toBeFalse()->and($pipeline->inputUploads()->count())->toBe(0)
        ->and($pipeline->fresh()->config_revision)->toBe(1);
});

it('renders fields in nonstale order with Spanish read-only schema columns', function (): void {
    app()->setLocale('es');
    $this->actingAs(User::factory()->artDirector()->create());
    $pipeline = Pipeline::factory()->create();
    $stale = PipelineField::factory()->for($pipeline)->create(['name' => 'old', 'stale' => true, 'sort_order' => 0]);
    $current = PipelineField::factory()->for($pipeline)->create(['name' => 'current', 'sort_order' => 10, 'source_schema' => ['type' => 'string', 'description' => '<script>schema()</script>']]);
    Livewire::test(FieldsRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
        ->assertCanSeeTableRecords([$current, $stale], inOrder: true)
        ->assertTableColumnExists('required', fn ($column): bool => $column->getLabel() === 'Obligatorio')
        ->assertTableColumnExists('source_schema', fn ($column): bool => $column->getLabel() === 'Esquema de origen')
        ->assertDontSee('<script>schema()</script>', false);
});

use App\Filament\Admin\Resources\Pipelines\Pages\ListPipelines;

it('lists the catalog with readiness and campaign counts', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $used = readyGenerator($campaign);
    $idle = Pipeline::factory()->editor()->create(['label' => 'Editor libre']);

    Livewire::test(ListPipelines::class)
        ->assertCanSeeTableRecords([$used, $idle])
        ->assertTableColumnStateSet('campaigns_count', 1, $used)
        ->assertTableColumnStateSet('campaigns_count', 0, $idle)
        ->assertSee('Catálogo de apps');
});

it('creates a catalog entry, syncs its schema, and rejects a duplicate provider reference', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    fakeEngine()->withSchema('app-1', ['properties' => ['prompt' => ['type' => 'string']]]);

    Livewire::test(ListPipelines::class)
        ->callAction('create', data: ['kind' => 'generator', 'label' => 'Creador', 'provider_ref' => 'app-1'])
        ->assertHasNoActionErrors();
    $pipeline = Pipeline::query()->sole();
    expect($pipeline->fields()->count())->toBe(1)->and($pipeline->is_ready)->toBeTrue();

    Livewire::test(ListPipelines::class)
        ->callAction('create', data: ['kind' => 'editor', 'label' => 'Otro', 'provider_ref' => 'app-1'])
        ->assertHasActionErrors(['provider_ref']);
    expect(Pipeline::query()->count())->toBe(1);
});

it('leaves no catalog entry when the schema fetch fails', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    fakeEngine();

    Livewire::test(ListPipelines::class)
        ->callAction('create', data: ['kind' => 'generator', 'label' => 'Roto', 'provider_ref' => 'app-x'])
        ->assertHasActionErrors(['provider_ref']);
    expect(Pipeline::query()->count())->toBe(0);
});

it('deletes only unassigned catalog entries', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $used = readyGenerator($campaign);
    $idle = Pipeline::factory()->create();

    Livewire::test(ListPipelines::class)
        ->assertTableActionHidden('delete', $used)
        ->callTableAction('delete', $idle);
    expect(Pipeline::query()->whereKey($idle->id)->exists())->toBeFalse()
        ->and(Pipeline::query()->whereKey($used->id)->exists())->toBeTrue();
});

it('automatically disables an invalid app on schema update without manual readiness actions', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $pipeline = readyGenerator(Campaign::factory()->create());
    fakeEngine()->withSchema($pipeline->provider_ref, ['properties' => ['unsupported' => ['type' => 'object']]]);

    Livewire::test(EditPipeline::class, ['record' => $pipeline->id])
        ->assertActionDoesNotExist('markReady')->assertActionDoesNotExist('markNotReady')
        ->callAction('refresh')->assertNotified();
    expect($pipeline->fresh()->is_ready)->toBeFalse();
});

it('automatically restores availability after correcting a catalog field', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $pipeline = readyGenerator($campaign);
    $field = $pipeline->fields()->sole();
    $component = Livewire::test(FieldsRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class]);
    $component->callAction(TestAction::make('edit')->table($field), data: ['visibility' => 'hidden'])->assertHasNoActionErrors();
    expect($campaign->activeGenerators()->count())->toBe(0);

    $component->callAction(TestAction::make('edit')->table($field), data: ['visibility' => 'visible'])->assertHasNoActionErrors();
    expect($campaign->activeGenerators()->pluck('pipelines.id')->all())->toBe([$pipeline->id]);
});

it('does not offer deletion for apps assigned to archived campaigns', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $pipeline = readyGenerator($campaign);
    $campaign->delete();

    Livewire::test(ListPipelines::class)->assertTableActionHidden('delete', $pipeline);
});

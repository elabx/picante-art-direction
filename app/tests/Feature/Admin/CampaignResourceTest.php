<?php

use App\Filament\Admin\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Admin\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Admin\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Admin\Resources\Campaigns\RelationManagers\GenerationsRelationManager;
use App\Filament\Admin\Resources\Campaigns\RelationManagers\PipelinesRelationManager;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('creates a pipeline through its campaign and fetches schema outside an action transaction', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $level = DB::transactionLevel();
    fakeEngine()->withSchema('ver-1', ['type' => 'object', 'required' => ['describe_la_escena'], 'properties' => ['describe_la_escena' => ['type' => 'string']]], 'Creador')
        ->duringDescribe(fn () => expect(DB::transactionLevel())->toBe($level));
    $campaign = Campaign::factory()->create();

    Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class])
        ->callAction(TestAction::make('create')->table(), data: ['kind' => 'generator', 'label' => 'Creador', 'provider_ref' => 'ver-1', 'sort_order' => 1])
        ->assertHasNoActionErrors();

    $pipeline = $campaign->pipelines()->sole();
    expect($pipeline->fields)->toHaveCount(1)->and($pipeline->is_active)->toBeFalse()->and($pipeline->readiness_errors)->toBe([]);
});

it('surfaces safe provider validation and leaves no pipeline after failed schema creation', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    fakeEngine();
    $campaign = Campaign::factory()->create();
    Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class])
        ->callAction(TestAction::make('create')->table(), data: ['kind' => 'generator', 'label' => 'X', 'provider_ref' => 'missing', 'sort_order' => 1])
        ->assertHasActionErrors(['provider_ref']);
    expect($campaign->pipelines()->count())->toBe(0)->and(PipelineField::count())->toBe(0);
});

it('activates a ready editor and refuses a second active editor', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $pipelines = collect(range(1, 2))->map(function () use ($campaign): Pipeline {
        $pipeline = Pipeline::factory()->for($campaign)->create(['kind' => 'editor', 'input_schema' => ['properties' => []]]);
        PipelineField::factory()->for($pipeline)->create(['input_type' => 'image', 'role' => 'image']);
        PipelineField::factory()->for($pipeline)->create(['role' => 'prompt']);

        return $pipeline;
    });
    $component = Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class]);
    $component->callAction(TestAction::make('activate')->table($pipelines[0]));
    $component->callAction(TestAction::make('activate')->table($pipelines[1]))->assertNotified();
    expect($pipelines[0]->fresh()->is_active)->toBeTrue()->and($pipelines[1]->fresh()->is_active)->toBeFalse();
});

it('creates campaigns and keeps their brand immutable while validating the default generator', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $brand = Brand::factory()->create();
    Livewire::test(CreateCampaign::class)->fillForm(['brand_id' => $brand->id, 'name' => 'Verano', 'slug' => 'verano'])
        ->call('create')->assertHasNoFormErrors();
    $campaign = Campaign::query()->sole();
    $pipeline = readyGenerator($campaign);
    Livewire::test(EditCampaign::class, ['record' => $campaign->id])->fillForm(['default_pipeline_id' => $pipeline->id])
        ->call('save')->assertHasNoFormErrors();
    expect($campaign->fresh()->default_pipeline_id)->toBe($pipeline->id);
    Livewire::test(EditCampaign::class, ['record' => $campaign->id])->set('data.brand_id', Brand::factory()->create()->id)
        ->call('save')->assertHasFormErrors(['brand_id']);
    expect($campaign->fresh()->brand_id)->toBe($brand->id);
    $foreign = readyGenerator(Campaign::factory()->create());
    Livewire::test(EditCampaign::class, ['record' => $campaign->id])->fillForm(['default_pipeline_id' => $foreign->id])
        ->call('save')->assertHasFormErrors(['default_pipeline_id']);
    expect($campaign->fresh()->default_pipeline_id)->toBe($pipeline->id);
});

it('soft deletes campaigns and excludes them from the normal list', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    Livewire::test(EditCampaign::class, ['record' => $campaign->id])->callAction('delete');
    expect($campaign->fresh()->trashed())->toBeTrue();
    Livewire::test(ListCampaigns::class)->assertCanNotSeeTableRecords([$campaign]);
});

it('shows only safe generation history with a status filter and no write actions', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $generation = Generation::factory()->for($campaign)->create(['status' => 'failed', 'error_message' => '<script>bad()</script>', 'execution_snapshot' => snapshot(['secret_marker' => 'snapshot-private-marker'])]);
    $pending = Generation::factory()->for($campaign)->create();
    Livewire::test(GenerationsRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class])
        ->assertCanSeeTableRecords([$generation, $pending])->filterTable('status', 'failed')->assertCanNotSeeTableRecords([$pending])
        ->assertDontSee('snapshot-private-marker')->assertDontSee('<script>bad()</script>', false)
        ->assertActionDoesNotExist(TestAction::make('create')->table())->assertActionDoesNotExist(TestAction::make('edit')->table($generation));
});

it('denies direct admin and manager calls after role revocation', function (): void {
    $user = User::factory()->artDirector()->create();
    $this->actingAs($user);
    $campaign = Campaign::factory()->create();
    $component = Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class]);
    $user->update(['role' => 'editor']);
    $component->call('mountAction', 'create', [], ['table' => true])->assertForbidden();
    $this->get('/admin/campaigns')->assertForbidden();
    expect($campaign->pipelines()->count())->toBe(0);
});

it('retains historical campaign access for accepted generations after soft deletion', function (): void {
    $generation = Generation::factory()->create(['status' => 'submitted']);
    $campaign = $generation->campaign;
    $campaign->delete();
    expect($generation->fresh()->campaign?->id)->toBe($campaign->id);
});

it('refreshes a pipeline from its campaign and deactivates an invalid default', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $pipeline = readyGenerator($campaign);
    $campaign->update(['default_pipeline_id' => $pipeline->id]);
    fakeEngine()->withSchema($pipeline->provider_ref, ['properties' => ['new' => ['type' => 'object']]]);
    Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class])
        ->callAction(TestAction::make('refresh')->table($pipeline))->assertNotified();
    expect($pipeline->fresh()->is_active)->toBeFalse()->and($campaign->fresh()->default_pipeline_id)->toBeNull();
});

it('rejects malformed provider references before describing a schema', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    fakeEngine()->duringDescribe(fn () => test()->fail('Malformed provider reference reached the engine.'));
    $campaign = Campaign::factory()->create();
    Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class])
        ->callAction(TestAction::make('create')->table(), data: ['kind' => 'generator', 'label' => 'X', 'provider_ref' => '<script>secret</script>', 'sort_order' => 0])
        ->assertHasActionErrors(['provider_ref']);
    expect($campaign->pipelines()->count())->toBe(0);
});

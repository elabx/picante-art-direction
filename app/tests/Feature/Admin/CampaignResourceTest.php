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
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

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
    $component->call('mountAction', 'assign', [], ['table' => true])->assertForbidden();
    $this->get('/admin/campaigns')->assertForbidden();
    expect($campaign->pipelines()->count())->toBe(0);
});

it('retains historical campaign access for accepted generations after soft deletion', function (): void {
    $generation = Generation::factory()->create(['status' => 'submitted']);
    $campaign = $generation->campaign;
    $campaign->delete();
    expect($generation->fresh()->campaign?->id)->toBe($campaign->id);
});

it('assigns a ready catalog app with an order and lists only unassigned ready apps', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $ready = Pipeline::factory()->generator()->ready()->create(['label' => 'Creador']);
    $notReady = Pipeline::factory()->generator()->create(['label' => 'Pendiente']);
    $assigned = readyGenerator($campaign);

    $component = Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class]);
    $component->mountAction(TestAction::make('assign')->table())
        ->assertSchemaComponentExists('pipeline_id')
        ->assertActionDataSet(['sort_order' => 0]);
    $options = $component->instance()->assignableOptions();
    expect(array_keys($options))->toBe([$ready->id]);

    $component->setActionData(['pipeline_id' => $ready->id, 'sort_order' => 2])->callMountedAction()->assertHasNoActionErrors()->assertNotified();
    expect($campaign->pipelines()->pluck('pipelines.id')->all())->toBe([$assigned->id, $ready->id]);
});

it('surfaces assignment rule violations on the select', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    attachPipeline($campaign, Pipeline::factory()->editor()->ready()->create());
    $second = Pipeline::factory()->editor()->ready()->create();

    Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class])
        ->callAction(TestAction::make('assign')->table(), data: ['pipeline_id' => $second->id, 'sort_order' => 0])
        ->assertHasActionErrors(['pipeline_id']);
    expect($campaign->pipelines()->count())->toBe(1);
});

it('reorders and removes assignments without deleting the catalog entry', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $pipeline = readyGenerator($campaign);
    $campaign->update(['default_pipeline_id' => $pipeline->id]);
    $component = Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class]);

    $component->callAction(TestAction::make('reorder')->table($pipeline), data: ['sort_order' => 5])->assertHasNoActionErrors();
    expect($campaign->pipelines()->first()?->pivot->sort_order)->toBe(5);
    $component->assertTableColumnStateSet('pivot.sort_order', 5, $pipeline);

    $component->callAction(TestAction::make('remove')->table($pipeline))->assertNotified();
    expect($campaign->pipelines()->count())->toBe(0)
        ->and($campaign->fresh()->default_pipeline_id)->toBeNull()
        ->and(Pipeline::query()->whereKey($pipeline->id)->exists())->toBeTrue();
});

it('does not offer create, edit fields, or refresh from the campaign', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $campaign = Campaign::factory()->create();
    $pipeline = readyGenerator($campaign);

    Livewire::test(PipelinesRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => EditCampaign::class])
        ->assertActionDoesNotExist(TestAction::make('create')->table())
        ->assertTableActionDoesNotExist('refresh')
        ->assertTableActionExists('openCatalog')
        ->assertSee('Apps');
});

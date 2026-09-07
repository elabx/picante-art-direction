<?php

use App\Filament\App\Pages\Campaigns;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\Piece;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('lists only the current brand campaigns with completed series and piece counts', function (): void {
    $editor = User::factory()->editor()->create();
    $brand = Brand::factory()->create();
    $brand->users()->attach($editor);
    $campaign = Campaign::factory()->for($brand)->create(['name' => 'Aliados']);
    Generation::factory()->for($campaign)->create(['kind' => 'series', 'status' => 'completed']);
    Generation::factory()->for($campaign)->create(['kind' => 'series', 'status' => 'pending']);
    $piece = Piece::factory()->create();
    $piece->forceFill(['campaign_id' => $campaign->id])->save();
    Campaign::factory()->create(['name' => 'Ajena']);

    $this->actingAs($editor);
    Filament::setTenant($brand);

    Livewire::test(Campaigns::class, ['tenant' => $brand])
        ->assertCanSeeTableRecords([$campaign])
        ->assertSee('Aliados')
        ->assertDontSee('Ajena')
        ->assertSee('1');
});

it('uses the campaign media route for covers and opens the campaign URL', function (): void {
    $editor = User::factory()->editor()->create();
    $brand = Brand::factory()->create();
    $brand->users()->attach($editor);
    $campaign = Campaign::factory()->for($brand)->create(['cover_path' => 'covers/aliados.png', 'slug' => 'aliados']);

    $this->actingAs($editor);
    Filament::setTenant($brand);

    Livewire::test(Campaigns::class, ['tenant' => $brand])
        ->assertSee(route('media.cover', $campaign), false)
        ->assertSee("/app/{$brand->slug}/campaigns/aliados", false)
        ->assertSee('onerror=', false);
});

it('does not list soft-deleted campaigns', function (): void {
    $editor = User::factory()->editor()->create();
    $brand = Brand::factory()->create();
    $brand->users()->attach($editor);
    $deleted = Campaign::factory()->for($brand)->create(['name' => 'Archivada']);
    $deleted->delete();

    $this->actingAs($editor);
    Filament::setTenant($brand);

    Livewire::test(Campaigns::class, ['tenant' => $brand])
        ->assertDontSee('Archivada');
});

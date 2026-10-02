<?php

use App\Filament\Widgets\BrandSummaryWidget;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\User;
use Livewire\Livewire;

it('shows the brand summary on the studio dashboard', function (): void {
    $this->actingAs(User::factory()->artDirector()->create())
        ->get('/picante')
        ->assertOk()
        ->assertSee('Resumen de marcas');
});

it('lists every brand with its users and campaign count', function (): void {
    $artDirector = User::factory()->artDirector()->create();
    $skechers = Brand::factory()->create(['name' => 'Skechers']);
    $invierno = Brand::factory()->create(['name' => 'Invierno']);
    $ana = User::factory()->editor()->create(['name' => 'Ana Editora']);
    $luis = User::factory()->editor()->create(['name' => 'Luis Editor']);
    $skechers->users()->attach([$ana->id, $luis->id]);
    Campaign::factory()->count(2)->for($skechers)->create();

    Livewire::actingAs($artDirector)
        ->test(BrandSummaryWidget::class)
        ->assertCanSeeTableRecords([$skechers, $invierno])
        ->assertTableColumnStateSet('users.name', ['Ana Editora', 'Luis Editor'], $skechers)
        ->assertTableColumnStateSet('users_count', 2, $skechers)
        ->assertTableColumnStateSet('campaigns_count', 2, $skechers)
        ->assertTableColumnStateSet('users_count', 0, $invierno)
        ->assertSee('Sin usuarios');
});

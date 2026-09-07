<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\User;
use Database\Seeders\ArtDirectorSeeder;

it('lets art directors into admin only and editors into app only', function (): void {
    $artDirector = User::factory()->artDirector()->create();
    $editor = User::factory()->editor()->create();
    $brand = Brand::factory()->create();
    $brand->users()->attach($editor);

    $this->actingAs($artDirector)->get('/admin')->assertOk();
    $this->actingAs($editor)->get('/admin')->assertForbidden();
    $this->actingAs($editor)->get("/app/{$brand->slug}")->assertOk();
    $this->actingAs($artDirector)->get("/app/{$brand->slug}")->assertForbidden();
});

it('blocks an editor from a brand they do not belong to', function (): void {
    $editor = User::factory()->editor()->create();
    $mine = Brand::factory()->create();
    $mine->users()->attach($editor);
    $other = Brand::factory()->create();

    $this->actingAs($editor)->get("/app/{$other->slug}")->assertNotFound();
});

it('checks the current brand membership instead of a loaded relation', function (): void {
    $editor = User::factory()->editor()->create();
    $brand = Brand::factory()->create();
    $editor->load('brands');

    $brand->users()->attach($editor);

    expect($editor->canAccessTenant($brand))->toBeTrue();
});

it('upserts the art director seed user', function (): void {
    app(ArtDirectorSeeder::class)->run();
    app(ArtDirectorSeeder::class)->run();

    expect(User::query()->where('email', 'ad@picante.local')->count())->toBe(1)
        ->and(User::query()->where('email', 'ad@picante.local')->value('role'))->toBe(UserRole::ArtDirector);
});

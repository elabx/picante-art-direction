<?php

use App\Filament\App\Pages\Gallery;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\Piece;
use Livewire\Livewire;

it('filters the gallery by kind, 4k and selection', function (): void {
    [$editor, , $campaign] = editorInCampaign();
    $generation = Generation::factory()->for($campaign)->create();
    $original = Piece::factory()->for($generation)->for($campaign)->create(['kind' => 'original']);
    $edit = Piece::factory()->for($generation)->for($campaign)->create(['kind' => 'edit', 'selected' => true]);
    $upscale = Piece::factory()->for($generation)->for($campaign)->create(['kind' => 'upscale', 'is_4k' => true]);

    $gallery = Livewire::actingAs($editor)->test(Gallery::class, ['campaign' => $campaign->slug])
        ->assertCanSeeTableRecords([$original, $edit, $upscale]);

    $gallery->filterTable('kind', 'edit')
        ->assertCanSeeTableRecords([$edit])
        ->assertCanNotSeeTableRecords([$original, $upscale]);

    $gallery->filterTable('kind', '4k')
        ->assertCanSeeTableRecords([$upscale])
        ->assertCanNotSeeTableRecords([$original, $edit]);

    $gallery->filterTable('kind', 'selected')->assertCanSeeTableRecords([$edit]);
});

it('uses stable series and version labels, and exposes gallery actions', function (): void {
    [$editor, $brand, $campaign] = editorInCampaign();
    $firstGeneration = Generation::factory()->for($campaign)->create(['created_at' => now()->subMinute()]);
    $secondGeneration = Generation::factory()->for($campaign)->create(['created_at' => now()]);
    $original = Piece::factory()->for($secondGeneration)->for($campaign)->create(['kind' => 'original', 'width' => 1920, 'height' => 1080]);
    $firstEdit = Piece::factory()->for($secondGeneration)->for($campaign)->create(['kind' => 'edit', 'parent_piece_id' => $original->id, 'root_piece_id' => $original->id, 'created_at' => now()->addSecond()]);
    $secondEdit = Piece::factory()->for($secondGeneration)->for($campaign)->create(['kind' => 'upscale', 'parent_piece_id' => $firstEdit->id, 'root_piece_id' => $original->id, 'created_at' => now()->addSeconds(2)]);

    Livewire::actingAs($editor)->test(Gallery::class, ['campaign' => $campaign->slug])
        ->assertCanSeeTableRecords([$original, $firstEdit, $secondEdit])
        ->assertSee('S2')
        ->assertSee('v2')
        ->assertSee('v3')
        ->assertSee('1920 × 1080')
        ->assertSee(route('media.piece', $original), false)
        ->assertSee(route('media.piece', [$original, 'download' => 1]), false)
        ->assertSee("/app/{$brand->slug}/campaigns/{$campaign->slug}", false);

    expect($firstGeneration->id)->not->toBe($secondGeneration->id);
});

it('does not expose foreign or soft deleted campaigns in the gallery', function (): void {
    [$editor, $brand] = editorInCampaign();
    $foreignBrand = Brand::factory()->create();
    $foreign = Campaign::factory()->for($foreignBrand)->create();
    $deleted = Campaign::factory()->for($brand)->create();
    $deleted->delete();

    $this->actingAs($editor)->get("/app/{$brand->slug}/campaigns/{$foreign->slug}/gallery")->assertNotFound();
    $this->actingAs($editor)->get("/app/{$brand->slug}/campaigns/{$deleted->slug}/gallery")->assertNotFound();
});

it('rejects a gallery request after tenant membership is revoked', function (): void {
    [$editor, $brand, $campaign] = editorInCampaign();
    $brand->users()->detach($editor);

    Livewire::actingAs($editor)->test(Gallery::class, ['campaign' => $campaign->slug])->assertForbidden();
});

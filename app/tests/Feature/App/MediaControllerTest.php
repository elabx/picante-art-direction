<?php

use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Generation;
use App\Models\InputUpload;
use App\Models\Piece;
use App\Models\Pipeline;
use App\Models\User;
use App\Services\Media\SignedUrlProvider;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-07 12:17:23'));
    $this->mock(SignedUrlProvider::class)->shouldReceive('url')
        ->byDefault()
        ->andReturnUsing(fn (string $disk, string $path): string => "https://signed.example/{$disk}/{$path}");
});

it('redirects a current editor membership to a signed piece URL without caching the redirect', function (): void {
    $editor = User::factory()->editor()->create();
    $brand = Brand::factory()->create();
    $brand->users()->attach($editor);
    $piece = Piece::factory()->create(['storage_path' => 'pieces/hero.webp', 'width' => 1440, 'height' => 900]);
    $piece->campaign->update(['brand_id' => $brand->id]);

    $this->actingAs($editor)
        ->get(route('media.piece', $piece))
        ->assertRedirect('https://signed.example/pieces/pieces/hero.webp')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('does not grant a piece URL through a stale editor membership', function (): void {
    $editor = User::factory()->editor()->create();
    $brand = Brand::factory()->create();
    $brand->users()->attach($editor);
    $editor->load('brands');
    $brand->users()->detach($editor);
    $piece = Piece::factory()->create();
    $piece->campaign->update(['brand_id' => $brand->id]);

    $this->actingAs($editor)->get(route('media.piece', $piece))->assertForbidden();
});

it('allows an editor to download a piece from a soft-deleted campaign they belong to', function (): void {
    $editor = User::factory()->editor()->create();
    $brand = Brand::factory()->create();
    $brand->users()->attach($editor);
    $piece = Piece::factory()->create(['storage_path' => 'pieces/archive.png']);
    $piece->campaign->update(['brand_id' => $brand->id]);
    $piece->campaign->delete();

    $this->actingAs($editor)->get(route('media.piece', $piece))->assertRedirect('https://signed.example/pieces/pieces/archive.png');
});

it('forbids foreign pieces and allows art directors to access brand-owned media', function (): void {
    $editor = User::factory()->editor()->create();
    $foreignPiece = Piece::factory()->create();
    $brand = Brand::factory()->create(['logo_path' => 'brands/logo.png']);
    $director = User::factory()->artDirector()->create();

    $this->actingAs($editor)->get(route('media.piece', $foreignPiece))->assertForbidden();
    $this->actingAs($director)->get(route('media.logo', $brand))->assertRedirect('https://signed.example/pieces/brands/logo.png');
});

it('returns not found when a cover or logo has no stored path', function (): void {
    $editor = User::factory()->editor()->create();
    $brand = Brand::factory()->create();
    $brand->users()->attach($editor);
    $campaign = Campaign::factory()->for($brand)->create(['cover_path' => null]);

    $this->actingAs($editor)->get(route('media.cover', $campaign))->assertNotFound();
    $this->actingAs($editor)->get(route('media.logo', $brand))->assertNotFound();
});

it('only allows editors to access their own upload unless it is a fixed pipeline input for their brand', function (): void {
    $editor = User::factory()->editor()->create();
    $colleague = User::factory()->editor()->create();
    $brand = Brand::factory()->create();
    $brand->users()->attach($editor);
    $owned = InputUpload::factory()->for($brand)->for($editor)->create(['storage_path' => 'inputs/owned.png']);
    $colleagueUpload = InputUpload::factory()->for($brand)->for($colleague)->create(['storage_path' => 'inputs/colleague.png']);
    $pipeline = attachPipeline(Campaign::factory()->for($brand)->create(), Pipeline::factory()->create());
    $pipeline->inputUploads()->attach($colleagueUpload);

    $this->actingAs($editor)->get(route('media.upload', $owned))->assertRedirect('https://signed.example/inputs/inputs/owned.png');
    $this->actingAs($editor)->get(route('media.upload', $colleagueUpload))->assertRedirect('https://signed.example/inputs/inputs/colleague.png');
    $pipeline->inputUploads()->detach($colleagueUpload);
    $this->actingAs($editor)->get(route('media.upload', $colleagueUpload))->assertForbidden();
});

it('allows a catalog fixed input shared with an assigned campaign across brands', function (): void {
    $editor = User::factory()->editor()->create();
    $colleague = User::factory()->editor()->create();
    $uploadBrand = Brand::factory()->create();
    $pipelineBrand = Brand::factory()->create();
    $pipelineBrand->users()->attach($editor);
    $upload = InputUpload::factory()->for($uploadBrand)->for($colleague)->create(['storage_path' => 'inputs/cross-brand.png']);
    $pipeline = attachPipeline(Campaign::factory()->for($pipelineBrand)->create(), Pipeline::factory()->create());
    $pipeline->inputUploads()->attach($upload);

    $this->actingAs($editor)->get(route('media.upload', $upload))->assertRedirect();
});

it('uses the requested attachment disposition and the exact ten-minute expiry for downloads', function (): void {
    $editor = User::factory()->editor()->create();
    $brand = Brand::factory()->create();
    $brand->users()->attach($editor);
    $piece = Piece::factory()->create(['storage_path' => 'pieces/final.png', 'width' => 1200, 'height' => 800]);
    $piece->campaign->update(['brand_id' => $brand->id]);
    $this->mock(SignedUrlProvider::class)->shouldReceive('url')->once()
        ->with('pieces', 'pieces/final.png', Mockery::on(fn (DateTimeInterface $expiresAt): bool => $expiresAt->getTimestamp() === now()->timestamp + 600), true, 'pieza-'.$piece->id.'-1200x800.png')
        ->andReturn('https://signed.example/download');

    $this->actingAs($editor)->get(route('media.piece', ['piece' => $piece, 'download' => 1]))
        ->assertRedirect('https://signed.example/download');
});

it('serves a catalog fixed image to editors of any brand whose campaign uses the app and denies others', function (): void {
    [$editor, $brand, $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    $upload = InputUpload::factory()->catalog()->create(['finalized_at' => now()]);
    $pipeline->inputUploads()->attach($upload->id);
    Storage::fake('inputs');
    Storage::disk('inputs')->put($upload->storage_path, 'x');

    $this->get(route('media.upload', $upload))->assertRedirect();

    $stranger = User::factory()->editor()->create();
    $otherBrand = Brand::factory()->create();
    $otherBrand->users()->attach($stranger);
    $this->actingAs($stranger);
    Filament::setTenant($otherBrand);
    $this->get(route('media.upload', $upload))->assertForbidden();

    $this->actingAs(User::factory()->artDirector()->create());
    $this->get(route('media.upload', $upload))->assertRedirect();
});

it('keeps snapshot catalog images available after removing the app and its fixed input', function (): void {
    [$editor, $brand, $campaign] = editorInCampaign();
    $pipeline = readyGenerator($campaign);
    $upload = InputUpload::factory()->catalog()->create();
    $generation = Generation::factory()->for($campaign)->for($pipeline)->create([
        'execution_snapshot' => snapshot(['inputs' => ['reference' => ['__upload' => $upload->id]]]),
    ]);
    $generation->inputUploads()->attach($upload);
    $campaign->pipelines()->detach($pipeline);

    $this->get(route('media.upload', $upload))->assertRedirect();
    $this->actingAs(User::factory()->editor()->create())->get(route('media.upload', $upload))->assertForbidden();
    $this->actingAs(User::factory()->artDirector()->create())->get(route('media.upload', $upload))->assertRedirect();
});

it('serves logos and covers with a trailing file name under the same authorization', function (): void {
    $editor = User::factory()->editor()->create();
    $outsider = User::factory()->editor()->create();
    $brand = Brand::factory()->create(['logo_path' => 'brands/logo.png']);
    $brand->users()->attach($editor);
    $campaign = Campaign::factory()->for($brand)->create(['cover_path' => 'covers/cover.png']);

    $this->actingAs($editor)->get(route('media.logo', [$brand, 'filename' => 'logo.png']))
        ->assertRedirect('https://signed.example/pieces/brands/logo.png');
    $this->actingAs($editor)->get(route('media.cover', [$campaign, 'filename' => 'cover.png']))
        ->assertRedirect('https://signed.example/pieces/covers/cover.png');
    $this->actingAs($outsider)->get(route('media.logo', [$brand, 'filename' => 'logo.png']))->assertForbidden();
});

<?php

use App\Filament\Admin\Resources\Brands\Pages\CreateBrand;
use App\Filament\Admin\Resources\Brands\Pages\EditBrand;
use App\Filament\Admin\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Admin\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Admin\Resources\Pipelines\Pages\EditPipeline;
use App\Filament\Admin\Resources\Pipelines\RelationManagers\FieldsRelationManager;
use App\Filament\Forms\Components\PrivateFileUpload;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Pipeline;
use App\Models\PipelineField;
use App\Models\User;
use App\Services\Media\SignedUrlProvider;
use App\Services\Pipelines\PipelineFormBuilder;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('signs private upload previews for exactly ten minutes without rounding', function (): void {
    $this->travelTo(Carbon::parse('2026-09-07 12:17:23'));
    Storage::fake('pieces')->put('covers/own.png', 'image');
    $record = Campaign::factory()->create(['cover_path' => 'covers/own.png']);
    $this->mock(SignedUrlProvider::class)->shouldReceive('url')->once()
        ->with('pieces', 'covers/own.png', Mockery::on(fn (DateTimeInterface $expiry): bool => $expiry->getTimestamp() === now()->timestamp + 600))
        ->andReturn('https://signed.example/preview');
    $field = Schema::make()->record($record)->components([
        PrivateFileUpload::make('cover_path')->disk('pieces'),
    ])->getComponents()[0];
    expect($field->getUploadedFile('covers/own.png', null)['url'])->toBe('https://signed.example/preview');
});

it('does not fall back to a public URL if private signing fails', function (): void {
    Storage::fake('pieces')->put('covers/own.png', 'image');
    $record = Campaign::factory()->create(['cover_path' => 'covers/own.png']);
    $this->mock(SignedUrlProvider::class)->shouldReceive('url')->once()->andThrow(new RuntimeException('Unavailable'));
    $field = Schema::make()->record($record)->components([PrivateFileUpload::make('cover_path')->disk('pieces')])->getComponents()[0];
    expect($field->getUploadedFile('covers/own.png', null))->toBeNull();
});

it('rejects forged cover and logo paths in preview and save', function (string $model, string $page, string $attribute, string $path): void {
    Storage::fake('pieces')->put('foreign.png', 'private image');
    $this->actingAs(User::factory()->artDirector()->create());
    $model::factory()->create([$attribute => 'foreign.png']);
    $record = $model::factory()->create([$attribute => null]);
    $component = Livewire::test($page, ['record' => $record->id])->set('data.'.$attribute, ['forged' => $path]);
    $field = $component->instance()->form->getFlatFields()[$attribute];
    expect($field->getUploadedFiles())->toBe(['forged' => null]);
    $component->call('save')->assertHasFormErrors([$attribute]);
    expect($record->fresh()->getAttribute($attribute))->toBeNull();
})->with([
    [Campaign::class, EditCampaign::class, 'cover_path', 'foreign.png'],
    [Brand::class, EditBrand::class, 'logo_path', 'foreign.png'],
    [Campaign::class, EditCampaign::class, 'cover_path', 'covers/../foreign.png'],
    [Brand::class, EditBrand::class, 'logo_path', 'brands/../foreign.png'],
    [Campaign::class, EditCampaign::class, 'cover_path', 'covers/./foreign.png'],
    [Brand::class, EditBrand::class, 'logo_path', 'brands/\\foreign.png'],
    [Campaign::class, EditCampaign::class, 'cover_path', '/foreign.png'],
    [Brand::class, EditBrand::class, 'logo_path', "brands/\0foreign.png"],
]);

it('accepts new image uploads and retains or clears only the record owned file', function (string $model, string $page, string $attribute, string $directory): void {
    Storage::fake('pieces');
    $this->actingAs(User::factory()->artDirector()->create());
    $record = $model::factory()->create([$attribute => null]);
    Livewire::test($page, ['record' => $record->id])->fillForm([
        $attribute => UploadedFile::fake()->image('new.png'),
    ])->call('save')->assertHasNoFormErrors();
    $path = $record->fresh()->getAttribute($attribute);
    expect($path)->toStartWith($directory.'/');
    Storage::disk('pieces')->assertExists($path);
    Livewire::test($page, ['record' => $record->id])->call('save')->assertHasNoFormErrors();
    expect($record->fresh()->getAttribute($attribute))->toBe($path);
    Livewire::test($page, ['record' => $record->id])->set('data.'.$attribute, [])->call('save')->assertHasNoFormErrors();
    expect($record->fresh()->getAttribute($attribute))->toBeNull();
})->with([[Campaign::class, EditCampaign::class, 'cover_path', 'covers'], [Brand::class, EditBrand::class, 'logo_path', 'brands']]);

it('rejects existing paths on cover and logo create forms', function (string $page, string $attribute): void {
    Storage::fake('pieces')->put('foreign.png', 'private image');
    $this->actingAs(User::factory()->artDirector()->create());
    Livewire::test($page)->fillForm(['name' => 'Nueva', 'slug' => 'nueva', 'brand_id' => Brand::factory()->create()->id])
        ->set('data.'.$attribute, ['forged' => 'foreign.png'])->call('create')->assertHasFormErrors([$attribute]);
})->with([[CreateCampaign::class, 'cover_path'], [CreateBrand::class, 'logo_path']]);

it('uses the exact private signer in each upload consumer', function (string $consumer): void {
    $this->travelTo(Carbon::parse('2026-09-07 12:17:23'));
    $this->actingAs($user = User::factory()->artDirector()->create());
    $disk = in_array($consumer, ['cover', 'logo'], true) ? 'pieces' : 'inputs';
    $path = $disk === 'pieces' ? 'owned.png' : 'tmp/owned.png';
    Storage::fake($disk)->put($path, 'private image');
    $this->mock(SignedUrlProvider::class)->shouldReceive('url')->once()
        ->with($disk, $path, Mockery::on(fn (DateTimeInterface $expiry): bool => $expiry->getTimestamp() === now()->timestamp + 600))
        ->andReturn('https://signed.example/exact-preview');
    if ($consumer === 'cover') {
        $record = Campaign::factory()->create(['cover_path' => $path]);
        $field = Livewire::test(EditCampaign::class, ['record' => $record->id])->instance()->form->getFlatFields()['cover_path'];
    } elseif ($consumer === 'logo') {
        $record = Brand::factory()->create(['logo_path' => $path]);
        $field = Livewire::test(EditBrand::class, ['record' => $record->id])->instance()->form->getFlatFields()['logo_path'];
    } else {
        $pipeline = Pipeline::factory()->create();
        $record = PipelineField::factory()->for($pipeline)->create(['input_type' => 'image']);
        if ($consumer === 'builder') {
            $field = Schema::make()->components(app(PipelineFormBuilder::class)->components($pipeline))->getComponents()[0];
        } else {
            $component = Livewire::test(FieldsRelationManager::class, [
                'ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class,
            ])->mountAction(TestAction::make('edit')->table($record))
                ->set('mountedActions.0.data.has_fixed_value', true);
            $instance = $component->instance();
            $field = $instance->getSchema($instance->getMountedActionSchemaName())->getComponentByStatePath('fixed_value');
        }
    }
    expect($field->getUploadedFile($path, null)['url'])->toBe('https://signed.example/exact-preview');
})->with(['cover', 'logo', 'builder', 'fixed']);

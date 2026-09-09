<?php

use App\Engines\FakeEngine;
use App\Filament\Admin\Resources\Brands\Pages\CreateBrand;
use App\Filament\Admin\Resources\Brands\Pages\EditBrand;
use App\Models\Brand;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('creates a brand with an encrypted key that is never shown back', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());

    Livewire::test(CreateBrand::class)
        ->fillForm([
            'name' => 'Santander',
            'slug' => 'santander',
            'krea_api_key' => 'k-test-secret',
            'use_studio_key' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $brand = Brand::query()->sole();

    expect($brand->krea_api_key)->toBe('k-test-secret')
        ->and(DB::table('brands')->value('krea_api_key'))->not->toContain('k-test-secret');

    Livewire::test(EditBrand::class, ['record' => $brand->id])
        ->assertFormSet(['krea_api_key' => null]);
});

it('does not overwrite a manually chosen slug when the name changes', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());

    Livewire::test(CreateBrand::class)
        ->fillForm(['name' => 'Original', 'slug' => 'marca-manual'])
        ->set('data.name', 'Nombre actualizado')
        ->assertFormSet(['slug' => 'marca-manual']);
});

it('preserves a blank key replacement and uses the studio key only when selected', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $brand = Brand::factory()->create(['krea_api_key' => 'k-old-secret']);

    Livewire::test(EditBrand::class, ['record' => $brand->id])
        ->fillForm(['name' => $brand->name, 'slug' => $brand->slug, 'use_studio_key' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($brand->fresh()->krea_api_key)->toBe('k-old-secret');

    Livewire::test(EditBrand::class, ['record' => $brand->id])
        ->fillForm([
            'name' => $brand->name,
            'slug' => $brand->slug,
            'krea_api_key' => 'k-replacement-secret',
            'use_studio_key' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($brand->fresh()->krea_api_key)->toBe('k-replacement-secret');

    Livewire::test(EditBrand::class, ['record' => $brand->id])
        ->fillForm(['name' => $brand->name, 'slug' => $brand->slug, 'use_studio_key' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($brand->fresh()->krea_api_key)->toBeNull()
        ->and($brand->fresh()->resolveKreaKeySource())->toBe('studio');
});

it('tests a brand connection through the configured fake engine', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $brand = Brand::factory()->create();
    $engine = fakeEngine();

    Livewire::test(EditBrand::class, ['record' => $brand->id])
        ->callAction('ping')
        ->assertNotified('Conexión correcta.');

    expect($engine)->toBeInstanceOf(FakeEngine::class)
        ->and($engine->pingCalls)->toBe(1);
});

it('shows a mapped danger notification when the studio key is unavailable', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    config(['media.krea.key' => null]);
    $brand = Brand::factory()->create();

    Livewire::test(EditBrand::class, ['record' => $brand->id])
        ->callAction('ping')
        ->assertNotified(
            Notification::make()->danger()->title('Clave de acceso inválida o faltante.'),
        );
});

it('renders Spanish labels for brand fields under the Spanish locale', function (): void {
    app()->setLocale('es');
    $this->actingAs(User::factory()->artDirector()->create());

    Livewire::test(CreateBrand::class)
        ->assertSchemaComponentExists('name', checkComponentUsing: fn (TextInput $component): bool => $component->getLabel() === 'Nombre')
        ->assertSchemaComponentExists('slug', checkComponentUsing: fn (TextInput $component): bool => $component->getLabel() === 'Slug')
        ->assertSchemaComponentExists('logo_path', checkComponentUsing: fn ($component): bool => $component->getLabel() === 'Logotipo')
        ->assertSchemaComponentExists('krea_api_key', checkComponentUsing: fn (TextInput $component): bool => $component->getLabel() === 'Clave de API de Krea')
        ->assertSchemaComponentExists('use_studio_key', checkComponentUsing: fn (Toggle $component): bool => $component->getLabel() === 'Usar la clave del estudio');
});

it('denies editors direct access to brand resource forms', function (): void {
    $this->actingAs(User::factory()->editor()->create());

    $this->get('/picante/brands')->assertForbidden();
});

it('denies a direct create call after an art director role is revoked', function (): void {
    $operator = User::factory()->artDirector()->create();
    $this->actingAs($operator);
    $component = Livewire::test(CreateBrand::class);

    $operator->update(['role' => 'editor']);

    $component->call('create')->assertForbidden();
});

it('denies direct server execution of the brand connection action after role revocation', function (): void {
    $operator = User::factory()->artDirector()->create();
    $this->actingAs($operator);
    $brand = Brand::factory()->create();
    $engine = fakeEngine();
    $component = Livewire::test(EditBrand::class, ['record' => $brand->id]);

    $operator->update(['role' => 'editor']);

    $component->call('mountAction', 'ping')->assertForbidden();

    expect($engine->pingCalls)->toBe(0);
});

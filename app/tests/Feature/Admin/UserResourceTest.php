<?php

use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\Brand;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('assigns brands to an editor', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $brand = Brand::factory()->create();

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Ana',
            'email' => 'ana@example.test',
            'password' => 'secret123',
            'role' => 'editor',
            'brands' => [$brand->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $editor = User::query()->where('email', 'ana@example.test')->sole();

    expect($editor->brands)->toHaveCount(1)
        ->and(Hash::check('secret123', $editor->password))->toBeTrue();
});

it('preserves an existing password when an editor is saved with a blank password', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $editor = User::factory()->editor()->create();
    $passwordHash = $editor->password;

    Livewire::test(EditUser::class, ['record' => $editor->id])
        ->fillForm([
            'name' => 'Ana Editora',
            'email' => $editor->email,
            'role' => 'editor',
            'brands' => [],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($editor->fresh()->password)->toBe($passwordHash);
});

it('preserves existing brand memberships when an editor becomes an art director', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $brand = Brand::factory()->create();
    $editor = User::factory()->editor()->create();
    $editor->brands()->attach($brand);

    Livewire::test(EditUser::class, ['record' => $editor->id])
        ->fillForm([
            'name' => $editor->name,
            'email' => $editor->email,
            'role' => 'art_director',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($editor->fresh()->brands->modelKeys())->toBe([$brand->id]);
});

it('renders Spanish user field labels and role badges under the Spanish locale', function (): void {
    app()->setLocale('es');
    $this->actingAs(User::factory()->artDirector()->create());
    $artDirector = User::factory()->artDirector()->create();
    $editor = User::factory()->editor()->create();

    Livewire::test(CreateUser::class)
        ->assertSchemaComponentExists('name', checkComponentUsing: fn (TextInput $component): bool => $component->getLabel() === 'Nombre')
        ->assertSchemaComponentExists('email', checkComponentUsing: fn (TextInput $component): bool => $component->getLabel() === 'Correo electrónico')
        ->assertSchemaComponentExists('password', checkComponentUsing: fn (TextInput $component): bool => $component->getLabel() === 'Contraseña')
        ->assertSchemaComponentExists('role', checkComponentUsing: fn (Select $component): bool => $component->getLabel() === 'Rol')
        ->assertSchemaComponentExists('brands', checkComponentUsing: fn (Select $component): bool => $component->getLabel() === 'Marcas');

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$artDirector, $editor])
        ->assertTableColumnFormattedStateSet('role', 'Director de arte', $artDirector)
        ->assertTableColumnFormattedStateSet('role', 'Editor', $editor);
});

it('ignores forged brand assignments for an art director', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());
    $brand = Brand::factory()->create();

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Dirección',
            'email' => 'direccion@example.test',
            'password' => 'secret123',
            'role' => 'art_director',
            'brands' => [$brand->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('email', 'direccion@example.test')->sole()->brands)->toHaveCount(0);
});

it('rejects an invalid role', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Ana',
            'email' => 'ana@example.test',
            'password' => 'secret123',
            'role' => 'administrator',
        ])
        ->call('create')
        ->assertHasFormErrors(['role' => 'in']);
});

it('rejects an unknown brand for an editor', function (): void {
    $this->actingAs(User::factory()->artDirector()->create());

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Ana',
            'email' => 'ana@example.test',
            'password' => 'secret123',
            'role' => 'editor',
        ])
        ->set('data.brands', [9999])
        ->call('create')
        ->assertHasErrors(['brands.0' => 'exists']);
});

it('denies a direct user create call after an art director role is revoked', function (): void {
    $operator = User::factory()->artDirector()->create();
    $this->actingAs($operator);
    $component = Livewire::test(CreateUser::class);

    $operator->update(['role' => 'editor']);

    $component->call('create')->assertForbidden();
});

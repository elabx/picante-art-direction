<?php

use App\Filament\App\Pages\Settings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('updates the current profile and rejects a taken email', function (): void {
    [$editor] = editorInCampaign();
    User::factory()->create(['email' => 'taken@x.com']);

    Livewire::actingAs($editor)->test(Settings::class)
        ->fillForm(['name' => 'Ana R.', 'email' => 'taken@x.com'])
        ->call('save')
        ->assertHasFormErrors(['email']);

    Livewire::actingAs($editor)->test(Settings::class)
        ->fillForm(['name' => 'Ana R.', 'email' => $editor->email])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($editor->fresh()->name)->toBe('Ana R.');
});

it('validates and hashes a changed password while preserving a blank password', function (): void {
    [$editor] = editorInCampaign();
    $originalHash = $editor->password;

    Livewire::actingAs($editor)->test(Settings::class)
        ->fillForm(['name' => $editor->name, 'email' => $editor->email, 'password' => 'secret-one', 'password_confirmation' => 'different'])
        ->call('save')
        ->assertHasFormErrors(['password']);

    Livewire::actingAs($editor)->test(Settings::class)
        ->fillForm(['name' => $editor->name, 'email' => $editor->email])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($editor->fresh()->password)->toBe($originalHash);

    Livewire::actingAs($editor)->test(Settings::class)
        ->fillForm(['name' => $editor->name, 'email' => $editor->email, 'password' => 'secret-one', 'password_confirmation' => 'secret-one'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('secret-one', (string) $editor->fresh()->password))->toBeTrue();
});

it('only saves the authenticated editor and keeps role input out of the form state', function (): void {
    [$editor] = editorInCampaign();
    $other = User::factory()->editor()->create(['name' => 'Otra persona']);

    Livewire::actingAs($editor)->test(Settings::class)
        ->fillForm(['name' => 'Ana R.', 'email' => $editor->email, 'role' => 'art_director', 'user_id' => $other->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($editor->fresh()->name)->toBe('Ana R.')
        ->and($editor->fresh()->role->value)->toBe('editor')
        ->and($other->fresh()->name)->toBe('Otra persona');
});

it('logs out through the Filament guard and redirects to the panel login', function (): void {
    [$editor] = editorInCampaign();
    $csrfToken = session()->token();

    Livewire::actingAs($editor)->test(Settings::class)
        ->call('logout')
        ->assertRedirect(Filament::getLoginUrl());

    expect(auth()->check())->toBeFalse()
        ->and(session()->token())->not->toBe($csrfToken);
});

<?php

use App\Models\User;

it('renders Spanish resource headings and create actions', function (string $path, string $heading, string $action): void {
    $this->actingAs(User::factory()->artDirector()->create())
        ->get($path)
        ->assertOk()
        ->assertSeeText($heading)
        ->assertSeeText($action)
        ->assertDontSeeText('Brands')
        ->assertDontSeeText('Users');
})->with([
    'brands' => ['/admin/brands', 'Marcas', 'Crear marca'],
    'users' => ['/admin/users', 'Usuarios', 'Crear usuario'],
]);

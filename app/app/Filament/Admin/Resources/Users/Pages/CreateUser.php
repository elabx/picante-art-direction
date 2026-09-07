<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Validator;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function beforeValidate(): void
    {
        if (($this->data['role'] ?? null) !== UserRole::Editor->value) {
            return;
        }

        Validator::make($this->data, [
            'brands' => ['nullable', 'array'],
            'brands.*' => ['exists:brands,id'],
        ])->validate();
    }
}

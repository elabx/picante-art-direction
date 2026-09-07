<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Validator;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

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

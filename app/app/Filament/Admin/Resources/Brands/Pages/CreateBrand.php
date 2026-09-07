<?php

namespace App\Filament\Admin\Resources\Brands\Pages;

use App\Filament\Admin\Resources\Brands\BrandResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBrand extends CreateRecord
{
    protected static string $resource = BrandResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($this->data['use_studio_key'] ?? true) === true) {
            $data['krea_api_key'] = null;
        }

        return $data;
    }
}

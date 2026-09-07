<?php

namespace App\Filament\Admin\Resources\Brands\Pages;

use App\Engines\EngineResolver;
use App\Engines\FakeEngine;
use App\Engines\Krea\KreaEngine;
use App\Engines\KreaException;
use App\Filament\Admin\Resources\Brands\BrandResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ping')
                ->label('Probar conexión')
                ->authorize('update', $this->getRecord())
                ->action(function (): void {
                    try {
                        $engine = app(EngineResolver::class)->forBrand($this->getRecord());

                        if ($engine instanceof KreaEngine || $engine instanceof FakeEngine) {
                            $engine->ping();
                        }

                        Notification::make()->success()->title('Conexión correcta.')->send();
                    } catch (KreaException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                    }
                }),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['krea_api_key'] = null;
        $data['use_studio_key'] = blank($this->getRecord()->krea_api_key);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($this->data['use_studio_key'] ?? false) === true) {
            $data['krea_api_key'] = null;
        }

        return $data;
    }
}

<?php

namespace App\Filament\App\Pages;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;

class Settings extends Page
{
    protected static ?string $slug = 'ajustes';

    protected static ?string $navigationLabel = 'Ajustes';

    protected string $view = 'filament.app.pages.settings';

    #[Locked]
    public int $brandId;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->brandId = $this->tenant()->id;
        $this->form->fill($this->profileData($this->user()));
    }

    public function hydrate(): void
    {
        $this->tenant();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->user())
            ->statePath('data')
            ->components([
                TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                TextInput::make('email')->label('Correo electrónico')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('password')->label('Contraseña')->password()->confirmed()->dehydrated(fn (?string $state): bool => filled($state)),
                TextInput::make('password_confirmation')->label('Confirmar contraseña')->password()->requiredWith('password')->dehydrated(false),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Sesión')
                ->headerActions([
                    Action::make('logout')
                        ->label('Cerrar sesión')
                        ->action(fn (): mixed => $this->logout()),
                ])
                ->schema([
                    Text::make('Generaciones · próximamente'),
                    Text::make('Equipo · próximamente'),
                ]),
        ]);
    }

    public function save(): void
    {
        $this->tenant();
        $user = $this->user();
        $user->fill($this->form->getState());
        $user->save();
        $this->form->fill($this->profileData($user->fresh() ?? $user));

        Notification::make()->success()->title('Cambios guardados.')->send();
    }

    public function logout(): mixed
    {
        $this->tenant();
        Filament::auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        return $this->redirect(Filament::getLoginUrl(), navigate: true);
    }

    /** @return array{name: string, email: string} */
    private function profileData(User $user): array
    {
        return ['name' => $user->name, 'email' => $user->email];
    }

    private function user(): User
    {
        $user = auth()->user()?->fresh();
        abort_unless($user instanceof User && $user->role === UserRole::Editor, 403);

        return $user;
    }

    private function tenant(): Brand
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Brand && $this->user()->brands()->whereKey($tenant->id)->exists(), 403);
        abort_if(isset($this->brandId) && $this->brandId !== $tenant->id, 403);

        return $tenant;
    }
}

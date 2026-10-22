<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * Account security — the signed-in person's two-factor recovery codes
 * (master directive, Parts 7-8).
 *
 * The sign-in challenge has always accepted a recovery code, and nothing in
 * the dashboard ever showed one: an owner who lost their phone was locked
 * out with no way back that did not need a developer. The codes are shown
 * here only after the password is typed again, and can be replaced, which
 * invalidates the old set. They are never written to a log or the activity
 * record.
 */
class AccountSecurity extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.account-security';

    /** @var list<string>|null shown only after the password is confirmed */
    public ?array $codes = null;

    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_system');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.security.nav');
    }

    public function getTitle(): string
    {
        return __('studio.security.nav');
    }

    /*
     * Every signed-in panel user: EnsureTwoFactorEnabled already refuses the
     * panel to anybody without a confirmed second factor, so there is no one
     * here without codes to show.
     */
    public static function canAccess(): bool
    {
        return auth()->check();
    }

    private function passwordField(): TextInput
    {
        return TextInput::make('password')
            ->label(__('studio.security.password'))
            ->password()
            ->revealable()
            ->autocomplete('current-password')
            ->required()
            ->currentPassword();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('show')
                ->label(__('studio.security.show'))
                ->icon('heroicon-o-eye')
                ->schema([$this->passwordField()])
                ->modalDescription(__('studio.security.confirm_hint'))
                ->action(function (): void {
                    $this->codes = auth()->user()->recoveryCodes();
                }),

            Action::make('regenerate')
                ->label(__('studio.security.regenerate'))
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->schema([$this->passwordField()])
                ->modalDescription(__('studio.security.regenerate_hint'))
                ->action(function (): void {
                    app(GenerateNewRecoveryCodes::class)(auth()->user());
                    $this->codes = auth()->user()->fresh()->recoveryCodes();
                    Notification::make()->title(__('studio.security.regenerated'))->success()->send();
                }),
        ];
    }
}

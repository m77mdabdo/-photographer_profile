<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Support\ResourceDefaults;
use App\Filament\Support\AddressesRecordsById;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Support\Str;

/**
 * The two people who can sign in.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THIS RESOURCE DOES NOT TOUCH AUTHENTICATION.
 *
 * No password field, no two-factor controls, no "reset" button. Fortify owns
 * credentials and MFA, and the enrolment flow requires the user's own
 * authenticator app — an admin screen that could set either would be a way to
 * take over an account from inside the panel, and a second place for the
 * password rules to drift from Fortify's.
 *
 * What it does own is who exists and what role they have, which is the part
 * the application decides. Creating a user here sends them through Fortify's
 * own password-reset flow to choose their own.
 * ───────────────────────────────────────────────────────────────────────────
 */
class UserResource extends Resource
{
    /** Edit URLs carry the id, not the slug. See the trait. */
    use AddressesRecordsById;

    protected static ?string $model = User::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 2;


    public static function getNavigationGroup(): ?string
    {
        return __('studio.nav.group_people');
    }

    public static function getNavigationLabel(): string
    {
        return __('studio.nav.users');
    }

    /** Only an Owner manages people. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->role === UserRole::Owner;
    }


    /**
     * The noun Filament puts in its own sentences.
     *
     * Task 34 F1. Without these, Filament derives "Article", "articles" and
     * "Create article" from the CLASS NAME — in English, whatever language
     * the panel is in. Found by PanelArabicRenderTest: fourteen screens were
     * showing English nouns in an Arabic panel, in breadcrumbs, empty states
     * and every modal heading.
     */
    public static function getModelLabel(): string
    {
        return __('studio.model.user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('studio.model.user_plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('studio.field.name'))->required()->maxLength(255),

            TextInput::make('email')
                ->label(__('studio.users.email'))
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),

            Select::make('role')
                ->label(__('studio.users.role'))
                ->options(fn (): array => collect(UserRole::cases())
                    ->mapWithKeys(fn (UserRole $r): array => [$r->value => $r->label()])->all())
                ->required()
                ->native(false)
                ->helperText(__('studio.users.role_hint'))

                /*
                 * ───────────────────────────────────────────────────────────
                 * THE LAST OWNER CANNOT BE DEMOTED.
                 *
                 * UserPolicy::delete() already refuses to delete the last
                 * Owner — but that branch turned out to be unreachable, since
                 * only an Owner can delete an Owner, so deleting the ONLY
                 * Owner is always deleting yourself and the self rule catches
                 * it first.
                 *
                 * The reachable version of the same accident is this select.
                 * An Owner setting their own role to Editor leaves the studio
                 * with zero Owners — and granting the role back requires an
                 * Owner, so nobody can ever administer the panel again. There
                 * is no undo and no screen that would do it.
                 * ───────────────────────────────────────────────────────────
                 */
                ->rule(fn (?User $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                    if ($record === null || $record->role !== UserRole::Owner || $value === UserRole::Owner->value) {
                        return;
                    }

                    if (User::query()->where('role', UserRole::Owner)->count() <= 1) {
                        $fail(__('studio.users.last_owner'));
                    }
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('studio.field.name'))->searchable()->sortable()->weight('semibold'),
                TextColumn::make('email')->label(__('studio.users.email'))->searchable(),

                TextColumn::make('role')
                    ->label(__('studio.users.role'))
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state): string => $state->label())
                    ->color(fn (UserRole $state): string => $state === UserRole::Owner ? 'primary' : 'gray'),

                /*
                 * Whether a CONFIRMED second factor exists — the thing
                 * EnsureTwoFactorEnabled actually requires. Shown, never
                 * editable: an admin who could clear it could lock somebody
                 * out or walk past the guard.
                 */
                IconColumn::make('two_factor_confirmed_at')
                    ->label(__('studio.users.two_factor'))
                    ->boolean()
                    ->getStateUsing(fn (User $record): bool => $record->two_factor_confirmed_at !== null),

                TextColumn::make('last_login_at')
                    ->label(__('studio.users.last_seen'))
                    ->dateTime('j M Y, H:i')
                    ->placeholder(__('studio.users.never'))
                    ->toggleable(),
            ])
            /*
             * Two questions get asked of a four-person user list: who can do
             * what, and who has not finished setting up their second factor.
             * Both are one click now rather than a read of every row.
             */
            ->filters([
                SelectFilter::make('role')
                    ->label(__('studio.users.role'))
                    ->options(fn (): array => collect(UserRole::cases())
                        ->mapWithKeys(fn (UserRole $r): array => [$r->value => $r->label()])
                        ->all()),

                TernaryFilter::make('two_factor_confirmed_at')
                    ->label(__('studio.users.two_factor'))
                    ->placeholder(__('studio.filter.all'))
                    ->trueLabel(__('studio.users.two_factor_on'))
                    ->falseLabel(__('studio.users.two_factor_off'))
                    ->nullable(),
            ])
            /*
             * Edit carries its own ->using(): a password is hashed and a role
             * is checked against "not the last Owner" in self::write(), never
             * mass-assigned. So the canonical row is composed around that one
             * rather than replacing it.
             *
             * The refusals — not yourself, not the last Owner — are shown
             * rather than hidden, which is now true of every resource because
             * rowActionsFor() does it rather than each resource remembering.
             */
            ->recordActions(ResourceDefaults::rowActionsFor(
                User::class,
                edit: EditAction::make()
                    ->using(fn (User $record, array $data): User => self::write($record, $data)),
            ))
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): User => self::add($data)),
            ])
            ->defaultSort('name');
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     * `role` IS NOT FILLABLE, AND MASS ASSIGNMENT DROPPED IT IN SILENCE.
     *
     * The users table has `role varchar(16) NOT NULL DEFAULT 'editor'`, and
     * User::$fillable is name, email, password — not role. So before this
     * method existed, the panel's role select was decoration: creating an
     * Owner created an Editor, and promoting an Editor did nothing, both with
     * a green "Saved" toast. A privilege change that appears to have happened
     * and has not is worse than one that fails.
     *
     * Not in RecordWriter's list, because role is not guarded for RecordWriter's
     * reason: slug and status are guarded so a stray assignment cannot publish
     * or rename, and role is guarded so a stray assignment cannot make
     * somebody an Owner. It is assigned here, inside a resource whose
     * canViewAny() is Owner-only and whose policy says the same, and nowhere
     * else.
     * ───────────────────────────────────────────────────────────────────────
     */
    private static function write(User $record, array $data): User
    {
        if (array_key_exists('role', $data)) {
            $record->role = UserRole::from($data['role']);

            unset($data['role']);
        }

        $record->fill($data)->save();

        return $record;
    }

    /**
     * A new user gets a random password they never learn.
     *
     * They set their own through Fortify's reset flow, so no password ever
     * exists in two places and nothing has to be communicated out of band.
     *
     * This was written on ManageUsers as mutateFormDataBeforeCreate() — which
     * a CreateAction never calls. The password column is NOT NULL, so the
     * first attempt to add somebody would have been a SQL error rather than a
     * quiet one; the role would have been quiet.
     */
    private static function add(array $data): User
    {
        $user = new User();

        $user->password = bcrypt(Str::random(48));

        return self::write($user, $data);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}

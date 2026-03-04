<?php

namespace App\Filament\Resources\Staff\Schemas;

use App\Support\RoleScenarioCatalog;
use App\Support\UserNotificationPreferences;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class StaffForm
{
    public static function configure(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        $marketSelect = Forms\Components\Select::make('market_id')
            ->label('Рынок')
            ->relationship('market', 'name')
            ->searchable()
            ->preload()
            ->required()
            ->default(fn () => $user?->isSuperAdmin()
                ? session('filament.admin.selected_market_id')
                : $user?->market_id)
            ->visible(fn () => (bool) $user && $user->isSuperAdmin())
            ->dehydrated(true);

        $marketHidden = Forms\Components\Hidden::make('market_id')
            ->default(fn () => $user?->market_id)
            ->visible(fn () => ! ((bool) $user && $user->isSuperAdmin()))
            ->dehydrated(true);

        $nameEmail = [];

        if (class_exists(\Filament\Forms\Components\Grid::class)) {
            $nameEmail[] = \Filament\Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Имя / ФИО')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
            ]);
        } else {
            $nameEmail[] = Forms\Components\TextInput::make('name')
                ->label('Имя / ФИО')
                ->required()
                ->maxLength(255);

            $nameEmail[] = Forms\Components\TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true);
        }

        $passwordPair = [];

        if (class_exists(\Filament\Forms\Components\Grid::class)) {
            $passwordPair[] = \Filament\Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null),

                Forms\Components\TextInput::make('password_confirmation')
                    ->label('Подтверждение пароля')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation, $get) => $operation === 'create' || filled($get('password')))
                    ->same('password')
                    ->dehydrated(false),
            ])
                ->visible(fn (string $operation): bool => $operation === 'create');
        } else {
            $passwordPair[] = Forms\Components\TextInput::make('password')
                ->label('Пароль')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                ->visible(fn (string $operation): bool => $operation === 'create');

            $passwordPair[] = Forms\Components\TextInput::make('password_confirmation')
                ->label('Подтверждение пароля')
                ->password()
                ->revealable()
                ->required(fn (string $operation, $get) => $operation === 'create' || filled($get('password')))
                ->same('password')
                ->dehydrated(false)
                ->visible(fn (string $operation): bool => $operation === 'create');
        }

        return $schema->components([
            $marketSelect,
            $marketHidden,

            ...$nameEmail,

            Forms\Components\TextInput::make('telegram_chat_id')
                ->label('Telegram (chat_id)')
                ->placeholder('например: 123456789')
                ->helperText('Нужен для доставки уведомлений в Telegram.')
                ->maxLength(32)
                ->regex('/^-?\d+$/')
                ->validationMessages([
                    'regex' => 'Используйте только цифры и, при необходимости, знак "-" в начале.',
                ])
                ->dehydrateStateUsing(fn ($state) => filled($state) ? trim((string) $state) : null)
                ->visible(fn (string $operation): bool => $operation === 'create'),

            Forms\Components\Placeholder::make('telegram_chat_id_help')
                ->label('Как получить chat_id')
                ->content(new HtmlString(
                    '<div class="text-sm text-gray-500 space-y-2">'
                    . '<a href="https://t.me/userinfobot" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium ring-1 ring-gray-300 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-white/5">Открыть @userinfobot</a>'
                    . '<div>1) Откройте бота и отправьте команду <code>/start</code>.</div>'
                    . '<div>2) Скопируйте значение <code>chat_id</code> из ответа.</div>'
                    . '<div>3) Вставьте <code>chat_id</code> в поле выше и сохраните пользователя.</div>'
                    . '</div>'
                ))
                ->visible(false),

            ...$passwordPair,

            Forms\Components\Select::make('roles')
                ->label('Роли')
                ->multiple()
                ->preload()
                ->searchable()
                ->relationship(
                    name: 'roles',
                    titleAttribute: 'name',
                    modifyQueryUsing: function ($query) use ($user) {
                        // сотрудникам не показываем "арендатор" (это другой тип пользователя)
                        $query->where('name', '!=', 'merchant');

                        // market-admin не должен видеть/назначать super-admin
                        if (! $user || ! $user->isSuperAdmin()) {
                            $query->where('name', '!=', 'super-admin');
                        }

                        return $query;
                    },
                )
                ->getOptionLabelFromRecordUsing(function ($record) {
                    $name = (string) ($record->name ?? '');

                    if ($name === '') {
                        return '—';
                    }

                    // Нормализуем на всякий случай (если роль вдруг будет с пробелами/underscore)
                    $slug = Str::of($name)
                        ->trim()
                        ->lower()
                        ->replace('_', '-')
                        ->replace(' ', '-')
                        ->replace('--', '-')
                        ->toString();

                    $key = "roles.{$slug}";
                    $translated = __($key);

                    if ($translated !== $key) {
                        return $translated;
                    }

                    return RoleScenarioCatalog::labelForSlug($slug, $name);
                })
                ->helperText('Роли определяют доступ сотрудника и типовые сценарии уведомлений.'),

            Forms\Components\Placeholder::make('roles_profile_hint')
                ->hiddenLabel()
                ->content(function ($get): HtmlString {
                    $roleIds = array_values(array_filter(
                        (array) ($get('roles') ?? []),
                        static fn ($value): bool => is_numeric($value)
                    ));

                    if ($roleIds === []) {
                        return new HtmlString(
                            '<div class="text-sm text-gray-500">Выберите роль, чтобы увидеть описание профиля и рекомендуемые сценарии уведомлений.</div>'
                        );
                    }

                    $roleNames = Role::query()
                        ->whereIn('id', $roleIds)
                        ->orderBy('name')
                        ->pluck('name')
                        ->all();

                    if ($roleNames === []) {
                        return new HtmlString('<div class="text-sm text-gray-500">Роли не выбраны.</div>');
                    }

                    $rows = [];
                    foreach ($roleNames as $roleName) {
                        $slug = Str::of((string) $roleName)
                            ->trim()
                            ->lower()
                            ->replace('_', '-')
                            ->replace(' ', '-')
                            ->replace('--', '-')
                            ->toString();

                        $label = e(RoleScenarioCatalog::labelForSlug($slug, (string) $roleName));
                        $description = e(RoleScenarioCatalog::descriptionForSlug($slug) ?? 'Кастомная роль без преднастроенного профиля.');
                        $topics = e(RoleScenarioCatalog::topicSummaryForSlug($slug));

                        $rows[] = '<div class="text-sm leading-6">'
                            . '<strong>' . $label . '</strong>'
                            . '<div class="text-gray-500">' . $description . '</div>'
                            . '<div class="text-gray-500">Сценарии уведомлений: ' . $topics . '</div>'
                            . '</div>';
                    }

                    return new HtmlString(implode('<div class="h-2"></div>', $rows));
                })
                ->visible(false),

            \Filament\Schemas\Components\Section::make('Уведомления')
                ->description('Для super-admin и market-admin личные настройки доступны всегда. Для остальных можно назначить правила централизованно.')
                ->schema([
                    Forms\Components\Toggle::make('notification_preferences.self_manage')
                        ->label('Разрешить личные настройки')
                        ->helperText('Пользователь сможет сам менять свои каналы и события в кабинете.')
                        ->default(false),

                    Forms\Components\CheckboxList::make('notification_preferences.channels')
                        ->label('Каналы доставки (назначение администратором)')
                        ->options(UserNotificationPreferences::channelLabels())
                        ->columns(3)
                        ->helperText('Если пусто, применяются стандартные каналы по доступным контактам.')
                        ->columnSpanFull(),

                    Forms\Components\CheckboxList::make('notification_preferences.topics')
                        ->label('События (назначение администратором)')
                        ->options(UserNotificationPreferences::topicLabels())
                        ->default(UserNotificationPreferences::TOPICS)
                        ->columns(2)
                        ->helperText('Если пусто, пользователь не получает уведомления по темам.')
                        ->columnSpanFull(),
                ])
                ->columns(1)
                ->collapsible()
                ->visible(fn (string $operation): bool => $operation === 'create'
                    && (bool) $user
                    && ($user->isSuperAdmin() || $user->isMarketAdmin())),
        ]);
    }
}

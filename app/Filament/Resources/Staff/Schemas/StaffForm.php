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
        $resolveMarketName = function (mixed $marketId): string {
            if (! is_numeric($marketId)) {
                return '—';
            }

            $marketName = \App\Models\Market::query()
                ->whereKey((int) $marketId)
                ->value('name');

            $marketName = trim((string) $marketName);

            return $marketName !== '' ? $marketName : '—';
        };

        $resolveRoleSummary = function (mixed $rawRoleIds): array {
            $roleIds = array_values(array_filter(
                (array) $rawRoleIds,
                static fn ($value): bool => is_numeric($value),
            ));

            if ($roleIds === []) {
                return [
                    'value' => '—',
                    'note' => 'Роли не назначены',
                ];
            }

            $roleNames = Role::query()
                ->whereIn('id', $roleIds)
                ->orderBy('name')
                ->pluck('name')
                ->all();

            if ($roleNames === []) {
                return [
                    'value' => '—',
                    'note' => 'Роли не найдены',
                ];
            }

            $labels = [];

            foreach ($roleNames as $roleName) {
                $slug = Str::of((string) $roleName)
                    ->trim()
                    ->lower()
                    ->replace('_', '-')
                    ->replace(' ', '-')
                    ->replace('--', '-')
                    ->toString();

                $labels[] = RoleScenarioCatalog::labelForSlug($slug, (string) $roleName);
            }

            $visibleLabels = array_slice($labels, 0, 2);
            $overflowCount = count($labels) - count($visibleLabels);
            $value = implode(', ', $visibleLabels);

            if ($overflowCount > 0) {
                $value .= ' +' . $overflowCount;
            }

            return [
                'value' => $value !== '' ? $value : '—',
                'note' => count($labels) === 1
                    ? '1 активная роль'
                    : count($labels) . ' активные роли',
            ];
        };

        $summaryMetric = function (string $label, string $value, ?string $note = null): HtmlString {
            $noteHtml = $note !== null && trim($note) !== ''
                ? '<div class="staff-edit-summary__note">' . e($note) . '</div>'
                : '';

            return new HtmlString(
                '<div class="staff-edit-summary__metric">'
                . '<div class="staff-edit-summary__label">' . e($label) . '</div>'
                . '<div class="staff-edit-summary__value">' . e($value !== '' ? $value : '—') . '</div>'
                . $noteHtml
                . '</div>'
            );
        };

        return $schema->components([
            \Filament\Schemas\Components\Grid::make(12)->schema([
                \Filament\Schemas\Components\Section::make('Карточка сотрудника')
                    ->description('Краткая сводка по рынку, контактам и ролям. Пароль меняется отдельной кнопкой сверху.')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(4)->schema([
                            Forms\Components\Placeholder::make('staff_summary_market')
                                ->hiddenLabel()
                                ->content(fn ($get): HtmlString => $summaryMetric(
                                    'Рынок',
                                    $resolveMarketName($get('market_id')),
                                    'Привязка сотрудника'
                                )),
                            Forms\Components\Placeholder::make('staff_summary_identity')
                                ->hiddenLabel()
                                ->content(function ($get) use ($summaryMetric): HtmlString {
                                    $name = trim((string) ($get('name') ?? ''));
                                    $email = trim((string) ($get('email') ?? ''));

                                    return $summaryMetric(
                                        'Сотрудник',
                                        $name !== '' ? $name : '—',
                                        $email !== '' ? $email : 'Email не задан',
                                    );
                                }),
                            Forms\Components\Placeholder::make('staff_summary_roles')
                                ->hiddenLabel()
                                ->content(function ($get) use ($resolveRoleSummary, $summaryMetric): HtmlString {
                                    $summary = $resolveRoleSummary($get('roles'));

                                    return $summaryMetric(
                                        'Роли',
                                        (string) ($summary['value'] ?? '—'),
                                        (string) ($summary['note'] ?? 'Роли не назначены'),
                                    );
                                }),
                            Forms\Components\Placeholder::make('staff_summary_password')
                                ->hiddenLabel()
                                ->content(fn (): HtmlString => $summaryMetric(
                                    'Пароль',
                                    'Изменяется сверху',
                                    'Отдельная кнопка в шапке'
                                )),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'staff-edit-summary']),

                \Filament\Schemas\Components\Section::make('Основные данные')
                    ->description('Рынок, имя и логин сотрудника.')
                    ->schema([
                        $marketSelect,
                        $marketHidden,

                        ...$nameEmail,
                    ])
                    ->columns(12)
                    ->columnSpan(['default' => 12, 'xl' => 7])
                    ->extraAttributes(['class' => 'staff-edit-main']),

                \Filament\Schemas\Components\Section::make('Роли и доступ')
                    ->description('Роли определяют доступ сотрудника и типовые сценарии уведомлений.')
                    ->schema([
                        Forms\Components\Select::make('roles')
                            ->label('Роли')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->relationship(
                                name: 'roles',
                                titleAttribute: 'name',
                                modifyQueryUsing: function ($query) use ($user) {
                                    // Сотрудникам не показываем "арендатор" (это другой тип пользователя)
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

                                    $rows[] = '<div class="staff-edit-role-hint__item">'
                                        . '<strong class="staff-edit-role-hint__title">' . $label . '</strong>'
                                        . '<div class="staff-edit-role-hint__copy">' . $description . '</div>'
                                        . '<div class="text-gray-500">Сценарии уведомлений: ' . $topics . '</div>'
                                        . '</div>';
                                }

                                return new HtmlString(
                                    '<div class="staff-edit-role-hint">' . implode('', $rows) . '</div>'
                                );
                            }),
                    ])
                    ->columns(1)
                    ->columnSpan(['default' => 12, 'xl' => 5])
                    ->extraAttributes(['class' => 'staff-edit-main staff-edit-access']),

                \Filament\Schemas\Components\Section::make('Telegram')
                    ->description('Одноразовый chat_id заполняется только при создании сотрудника.')
                    ->schema([
                        Forms\Components\TextInput::make('telegram_chat_id')
                            ->label('Telegram (chat_id)')
                            ->placeholder('например: 123456789')
                            ->helperText('Нужен для доставки уведомлений в Telegram.')
                            ->maxLength(32)
                            ->regex('/^-?\\d+$/')
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
                    ])
                    ->columnSpan(['default' => 12, 'xl' => 6])
                    ->extraAttributes(['class' => 'staff-edit-telegram'])
                    ->visible(fn (string $operation): bool => $operation === 'create'),

                \Filament\Schemas\Components\Section::make('Безопасность')
                    ->description('Пароль задается только при создании сотрудника.')
                    ->schema($passwordPair)
                    ->columnSpan(['default' => 12, 'xl' => 6])
                    ->extraAttributes(['class' => 'staff-edit-security'])
                    ->visible(fn (string $operation): bool => $operation === 'create'),

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
                            ->default(UserNotificationPreferences::defaultTopicsForRoleNames([]))
                            ->columns(2)
                            ->helperText('Если пусто, пользователь не получает уведомления по темам.')
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->collapsible()
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'staff-edit-notifications'])
                    ->visible(fn (string $operation): bool => $operation === 'create'
                        && (bool) $user
                        && ($user->isSuperAdmin() || $user->isMarketAdmin())),
            ]),
        ]);
    }
}

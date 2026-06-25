<?php
# app/Filament/Resources/Staff/Schemas/StaffForm.php

namespace App\Filament\Resources\Staff\Schemas;

use App\Filament\Resources\Staff\StaffResource;
use App\Models\User;
use App\Support\RoleScenarioCatalog;
use App\Support\UserNotificationPreferences;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
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
            ->dehydrated(true)
            ->columnSpan(['default' => 12, 'lg' => 4]);

        $marketHidden = Forms\Components\Hidden::make('market_id')
            ->default(fn () => $user?->market_id)
            ->visible(fn () => ! ((bool) $user && $user->isSuperAdmin()))
            ->dehydrated(true);

        $passwordPair = [
            Grid::make(2)->schema([
                Forms\Components\TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->autocomplete('new-password')
                    ->dehydrated(fn ($state) => filled($state))
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : null),

                Forms\Components\TextInput::make('password_confirmation')
                    ->label('Подтверждение')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation, $get) => $operation === 'create' || filled($get('password')))
                    ->same('password')
                    ->validationMessages([
                        'same' => 'Пароль и подтверждение не совпадают.',
                    ])
                    ->autocomplete('new-password')
                    ->dehydrated(false),
            ]),
        ];

        return $schema->components([
            Tabs::make('staff_profile_tabs')
                ->tabs([
                    Tab::make('Профиль')
                        ->schema([
            Section::make('Основные данные')
                ->description('Имя, email и привязка к рынку')
                ->schema([
                    $marketSelect,
                    $marketHidden,

                    Forms\Components\TextInput::make('name')
                        ->label('Имя / ФИО')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Иванов Иван Иванович')
                        ->columnSpan(['default' => 12, 'lg' => 4]),

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->placeholder('user@example.com')
                        ->autocomplete('new-email')
                        ->columnSpan(['default' => 12, 'lg' => 4]),

                    Forms\Components\TextInput::make('phone')
                        ->label('Телефон')
                        ->tel()
                        ->maxLength(32)
                        ->nullable()
                        ->placeholder('+7 900 000-00-00')
                        ->helperText('Необязательный номер для связи с сотрудником.')
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? trim((string) $state) : null)
                        ->columnSpan(['default' => 12, 'lg' => 3]),

                    Forms\Components\TextInput::make('job_title')
                        ->label('Должность')
                        ->maxLength(255)
                        ->nullable()
                        ->placeholder('Управляющий, маркетолог, бухгалтер')
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? trim((string) $state) : null)
                        ->columnSpan(['default' => 12, 'lg' => 3]),

                    Forms\Components\TextInput::make('department')
                        ->label('Отдел')
                        ->maxLength(255)
                        ->nullable()
                        ->placeholder('Администрация, финансы, маркетинг')
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? trim((string) $state) : null)
                        ->columnSpan(['default' => 12, 'lg' => 3]),

                    Forms\Components\DatePicker::make('birth_date')
                        ->label('Дата рождения')
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->helperText('Нужна для напоминаний о днях рождения сотрудников.')
                        ->columnSpan(['default' => 12, 'lg' => 3]),
                ])
                ->columns(12),

                        ]),

                    Tab::make('Доступ')
                        ->visible(fn (string $operation, ?User $record = null): bool => StaffResource::canViewStaffAccessTab(
                            $record,
                            $user,
                            $operation,
                        ))
                        ->schema([
            Section::make('Доступ')
                ->description('Роли определяют права сотрудника в системе')
                ->schema([
                    Forms\Components\Select::make('roles')
                        ->label('Роли')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->placeholder('Выберите одну или несколько ролей')
                        ->disabled(fn (): bool => ! StaffResource::canManageStaffAccess($user))
                        ->dehydrated(fn (): bool => StaffResource::canManageStaffAccess($user))
                        ->relationship(
                            name: 'roles',
                            titleAttribute: 'name',
                            modifyQueryUsing: function ($query) use ($user) {
                                $query->where('name', '!=', 'merchant');

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
                        ->saveRelationshipsUsing(function (?User $record, $state) use ($user): void {
                            if (! $record || ! StaffResource::canManageStaffAccess($user)) {
                                return;
                            }

                            $record->roles()->sync($state ?? []);
                        })
                        ->columnSpan(['default' => 12, 'lg' => 6]),
                ])
                ->columns(12),

                        ]),

                    Tab::make('Связь и уведомления')
                        ->schema([
            Section::make('Telegram')
                ->description('Одноразовый chat_id заполняется только при создании сотрудника.')
                ->schema([
                    Forms\Components\TextInput::make('telegram_chat_id')
                        ->label('Telegram chat_id')
                        ->placeholder('123456789')
                        ->helperText('Цифровой ID чата для доставки уведомлений в Telegram.')
                        ->maxLength(32)
                        ->regex('/^-?\d+$/')
                        ->validationMessages([
                            'regex' => 'Используйте только цифры и, при необходимости, знак "-" в начале.',
                        ])
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? trim((string) $state) : null)
                        ->columnSpan(['default' => 12, 'lg' => 6]),
                ])
                ->visible(fn (string $operation): bool => $operation === 'create'),

            Section::make('Пароль')
                ->description('Минимум 8 символов')
                ->schema($passwordPair)
                ->visible(fn (string $operation): bool => $operation === 'create'),

            Section::make('Уведомления')
                ->description('Каналы и события для уведомлений сотрудника')
                ->schema([
                    Forms\Components\Toggle::make('notification_preferences.self_manage')
                        ->label('Личные настройки')
                        ->helperText('Пользователь сможет сам менять свои каналы и события в кабинете.')
                        ->default(false),

                    Grid::make(2)->schema([
                        Forms\Components\CheckboxList::make('notification_preferences.channels')
                            ->label('Каналы доставки')
                            ->options(UserNotificationPreferences::channelLabels())
                            ->columns(2)
                            ->helperText('Если пусто — стандартные каналы.'),

                        Forms\Components\CheckboxList::make('notification_preferences.topics')
                            ->label('События')
                            ->options(UserNotificationPreferences::topicLabels())
                            ->default(UserNotificationPreferences::defaultTopicsForRoleNames([]))
                            ->columns(1)
                            ->helperText('Если пусто — уведомления не приходят.'),
                    ]),
                ])
                ->collapsible()
                ->collapsed()
                ->visible(fn (string $operation): bool => $operation === 'create'
                    && (bool) $user
                    && ($user->isSuperAdmin() || $user->isMarketAdmin())),

                        ])
                        ->visible(fn (string $operation): bool => $operation === 'create'),

                    Tab::make('ИИ-агент')
                        ->schema([
            Section::make('ИИ-агент')
                ->description('Персональный профиль и знания агента по сотруднику. Видно только super-admin.')
                ->schema([
                    Tabs::make('staff_ai_agent_tabs')
                        ->tabs([
                            Tab::make('Профиль агента')
                                ->schema([
                                    Section::make('Рабочий контекст')
                                        ->relationship('aiProfile')
                                        ->schema([
                                            Forms\Components\Hidden::make('market_id')
                                                ->default(fn ($record) => $record?->market_id),

                                            Grid::make(2)->schema([
                                                Forms\Components\TextInput::make('preferred_name')
                                                    ->label('Как обращаться')
                                                    ->maxLength(255)
                                                    ->placeholder('Саша, Марина Николаевна, Иван')
                                                    ->helperText('Агент будет использовать это обращение вместо полного ФИО.'),

                                                Forms\Components\TextInput::make('job_title')
                                                    ->label('Должность из переписки')
                                                    ->maxLength(255),

                                                Forms\Components\TextInput::make('department')
                                                    ->label('Отдел из переписки')
                                                    ->maxLength(255),

                                                Forms\Components\DatePicker::make('birth_date')
                                                    ->label('Дата рождения')
                                                    ->native(false)
                                                    ->displayFormat('d.m.Y'),

                                                Forms\Components\Select::make('communication_status')
                                                    ->label('Готовность к общению')
                                                    ->options([
                                                        'available' => 'Можно общаться',
                                                        'do_not_disturb' => 'Временная пауза',
                                                    ])
                                                    ->default('available'),

                                                Forms\Components\DateTimePicker::make('communication_paused_until')
                                                    ->label('Пауза до')
                                                    ->native(false)
                                                    ->displayFormat('d.m.Y H:i'),

                                                Forms\Components\Select::make('onboarding_status')
                                                    ->label('Знакомство')
                                                    ->options([
                                                        'new' => 'Не начиналось',
                                                        'incomplete' => 'Не завершено',
                                                        'complete' => 'Завершено',
                                                    ])
                                                    ->default('new'),
                                            ]),

                                            Forms\Components\Textarea::make('responsibility_scope')
                                                ->label('Зона ответственности из переписки')
                                                ->rows(3)
                                                ->columnSpanFull(),

                                            Forms\Components\TagsInput::make('regular_tasks')
                                                ->label('Регулярные задачи')
                                                ->placeholder('Добавьте регулярную задачу')
                                                ->columnSpanFull(),

                                            Forms\Components\CheckboxList::make('preferred_contact_channels')
                                                ->label('Предпочитаемые каналы связи')
                                                ->options(UserNotificationPreferences::channelLabels())
                                                ->columns(3),

                                            Forms\Components\Textarea::make('profile_summary')
                                                ->label('Сводка агента')
                                                ->rows(5)
                                                ->disabled()
                                                ->dehydrated(false)
                                                ->columnSpanFull(),
                                        ])
                                        ->columns(2),
                                ]),

                            Tab::make('Отклонённые темы')
                                ->schema([
                                    View::make('filament.resources.staff.partials.ai-profile-topics')
                                        ->viewData(fn ($record): array => ['record' => $record])
                                        ->columnSpanFull(),
                                ]),

                            Tab::make('Справочник агента')
                                ->schema([
                                    View::make('filament.resources.staff.partials.ai-profile-knowledge')
                                        ->viewData(fn ($record): array => ['record' => $record])
                                        ->columnSpanFull(),
                                ]),
                        ])
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->visible(fn (string $operation): bool => $operation === 'edit'
                    && (bool) $user
                    && $user->isSuperAdmin()),
                        ])
                        ->visible(fn (string $operation): bool => $operation === 'edit'
                            && (bool) $user
                            && $user->isSuperAdmin()),
                ])
                ->columnSpanFull(),
        ]);
    }
}

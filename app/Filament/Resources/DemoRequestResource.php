<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DemoRequestResource\Pages;
use App\Models\DemoRequest;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class DemoRequestResource extends BaseResource
{
    protected static ?string $model = DemoRequest::class;

    protected static ?string $slug = 'demo-requests';

    protected static ?string $recordTitleAttribute = 'organization';

    protected static ?string $navigationLabel = 'Заявки на демо';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static ?int $navigationSort = 156;

    protected static ?string $modelLabel = 'Заявка на демо';

    protected static ?string $pluralModelLabel = 'Заявки на демо';

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
            'organization',
            'email',
            'phone',
            'city',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        $runbookFields = [
            Forms\Components\Placeholder::make('lead_processing_runbook')
                ->label('Регламент обработки')
                ->content(static fn (): HtmlString => static::renderLeadProcessingRunbook())
                ->columnSpanFull(),
        ];

        $statusFields = [
            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options(DemoRequest::statusOptions())
                ->required(),

            Forms\Components\DateTimePicker::make('processed_at')
                ->label('Обработано')
                ->seconds(false),
        ];

        $readOnlyFields = [
            Forms\Components\TextInput::make('request_type')
                ->label('Тип запроса')
                ->formatStateUsing(static fn (?string $state): string => DemoRequest::typeLabel((string) $state))
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('name')
                ->label('Имя')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('organization')
                ->label('Организация')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('email')
                ->label('Email')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('phone')
                ->label('Телефон')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('city')
                ->label('Город')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('market_format')
                ->label('Формат рынка')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('spaces_count')
                ->label('Количество мест')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\Textarea::make('message')
                ->label('Комментарий')
                ->rows(5)
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('source')
                ->label('Источник')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\DateTimePicker::make('created_at')
                ->label('Создано')
                ->seconds(false)
                ->disabled()
                ->dehydrated(false),
        ];

        if (class_exists(Forms\Components\Grid::class)) {
            return $schema->components([
                ...$runbookFields,
                Forms\Components\Grid::make(2)->components($statusFields),
                Forms\Components\Grid::make(2)->components($readOnlyFields),
            ]);
        }

        return $schema->components([
            ...$runbookFields,
            ...$statusFields,
            ...$readOnlyFields,
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Получена')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(static fn (?string $state): string => DemoRequest::statusColor($state))
                    ->formatStateUsing(static fn (?string $state): string => DemoRequest::statusLabel($state))
                    ->sortable(),

                TextColumn::make('request_type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(static fn (?string $state): string => DemoRequest::typeLabel((string) $state))
                    ->sortable(),

                TextColumn::make('organization')
                    ->label('Организация')
                    ->searchable()
                    ->sortable()
                    ->limit(36)
                    ->tooltip(static fn (?string $state): ?string => filled($state) ? $state : null),

                TextColumn::make('name')
                    ->label('Контакт')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('city')
                    ->label('Город')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('market_format')
                    ->label('Формат')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('spaces_count')
                    ->label('Мест')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('message')
                    ->label('Комментарий')
                    ->formatStateUsing(static fn (?string $state): string => filled($state) ? Str::limit($state, 80) : '—')
                    ->tooltip(static fn (?string $state): ?string => filled($state) ? $state : null)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('processed_at')
                    ->label('Обработано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(DemoRequest::statusOptions())
                    ->placeholder('Все'),

                SelectFilter::make('request_type')
                    ->label('Тип')
                    ->options([
                        DemoRequest::TYPE_DEMO => DemoRequest::typeLabel(DemoRequest::TYPE_DEMO),
                        DemoRequest::TYPE_PILOT => DemoRequest::typeLabel(DemoRequest::TYPE_PILOT),
                        DemoRequest::TYPE_FREE => DemoRequest::typeLabel(DemoRequest::TYPE_FREE),
                    ])
                    ->placeholder('Все'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (DemoRequest $record): string => static::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('Заявок пока нет')
            ->emptyStateDescription('Заявки появятся здесь после отправки формы на landing.');

        $actions = [];

        $quickStatusActions = static::quickStatusActions();
        $actionGroupClass = static::actionGroupClass();

        if ($quickStatusActions !== [] && $actionGroupClass !== null) {
            $actions[] = $actionGroupClass::make($quickStatusActions)
                ->label('Статус')
                ->icon('heroicon-o-check-circle')
                ->color('gray')
                ->tooltip('Быстро сменить статус');
        } else {
            array_push($actions, ...$quickStatusActions);
        }

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $actions[] = \Filament\Actions\EditAction::make()
                ->label('Открыть');
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $actions[] = \Filament\Tables\Actions\EditAction::make()
                ->label('Открыть');
        }

        return $actions === [] ? $table : $table->actions($actions);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDemoRequests::route('/'),
            'edit' => Pages\EditDemoRequest::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::canViewAny()) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return static::canAccessDemoRequests();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return static::canAccessDemoRequests();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function applyLeadStatus(DemoRequest $record, string $status): void
    {
        if (! array_key_exists($status, DemoRequest::statusOptions())) {
            abort(404);
        }

        if (! static::canEdit($record)) {
            abort(403);
        }

        $record->forceFill([
            'status' => $status,
            'processed_at' => $status === DemoRequest::STATUS_NEW
                ? null
                : ($record->processed_at ?: now()),
        ])->save();

        Notification::make()
            ->title('Статус заявки обновлён')
            ->body(DemoRequest::statusLabel($status))
            ->success()
            ->send();
    }

    /**
     * @return array<int, mixed>
     */
    private static function quickStatusActions(): array
    {
        $actionClass = static::actionClass();

        if ($actionClass === null) {
            return [];
        }

        return array_values(array_filter([
            static::makeQuickStatusAction(
                $actionClass,
                DemoRequest::STATUS_CONTACTED,
                'Связались',
                'heroicon-o-phone',
                'info',
            ),
            static::makeQuickStatusAction(
                $actionClass,
                DemoRequest::STATUS_QUALIFIED,
                'Квалифицирована',
                'heroicon-o-check-badge',
                'success',
            ),
            static::makeQuickStatusAction(
                $actionClass,
                DemoRequest::STATUS_REJECTED,
                'Не подходит',
                'heroicon-o-x-circle',
                'danger',
                true,
            ),
        ]));
    }

    private static function makeQuickStatusAction(
        string $actionClass,
        string $status,
        string $label,
        string $icon,
        string $color,
        bool $requiresConfirmation = false,
    ): mixed {
        $action = $actionClass::make('mark_' . $status)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (DemoRequest $record): bool => static::canEdit($record) && (string) $record->status !== $status)
            ->action(function (DemoRequest $record) use ($status): void {
                static::applyLeadStatus($record, $status);
            });

        if ($requiresConfirmation && method_exists($action, 'requiresConfirmation')) {
            $action
                ->requiresConfirmation()
                ->modalHeading('Отметить заявку как неподходящую?')
                ->modalDescription('Заявка останется в истории, но исчезнет из списка новых лидов.')
                ->modalSubmitActionLabel('Отметить');
        }

        return $action;
    }

    private static function actionClass(): ?string
    {
        if (class_exists(\Filament\Actions\Action::class)) {
            return \Filament\Actions\Action::class;
        }

        if (class_exists(\Filament\Tables\Actions\Action::class)) {
            return \Filament\Tables\Actions\Action::class;
        }

        return null;
    }

    private static function actionGroupClass(): ?string
    {
        if (class_exists(\Filament\Actions\ActionGroup::class)) {
            return \Filament\Actions\ActionGroup::class;
        }

        if (class_exists(\Filament\Tables\Actions\ActionGroup::class)) {
            return \Filament\Tables\Actions\ActionGroup::class;
        }

        return null;
    }

    private static function renderLeadProcessingRunbook(): HtmlString
    {
        return new HtmlString(<<<'HTML'
<div class="space-y-2 text-sm leading-6 text-gray-700 dark:text-gray-200">
    <p><strong>SLA:</strong> связаться в тот же рабочий день; в рабочее время - желательно в течение 2 часов.</p>
    <p><strong>Квалификация:</strong> город, формат объекта, количество мест, текущий учет, 1С, карта/PDF, договоры, долги и готовность к пилоту.</p>
    <p><strong>Статусы:</strong> «Связались» после первого контакта; «Квалифицирована» после согласованного демо/пилота; «Не подходит» для спама, дублей и нерелевантных заявок.</p>
    <p><strong>Prod:</strong> public demo flag включается только отдельным решением; общий пароль публично не публикуется.</p>
</div>
HTML);
    }

    private static function canAccessDemoRequests(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        $allowedUserIds = array_map(
            static fn (mixed $value): int => (int) $value,
            (array) config('saas_progress.access.allowed_user_ids', []),
        );
        $allowedEmails = array_map(
            static fn (mixed $value): string => mb_strtolower(trim((string) $value), 'UTF-8'),
            (array) config('saas_progress.access.allowed_user_emails', []),
        );

        $email = mb_strtolower(trim((string) ($user->email ?? '')), 'UTF-8');

        return in_array((int) $user->id, $allowedUserIds, true)
            || ($email !== '' && in_array($email, $allowedEmails, true));
    }
}

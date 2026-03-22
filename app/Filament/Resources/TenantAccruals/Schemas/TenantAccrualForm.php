<?php
# app/Filament/Resources/TenantAccruals/Schemas/TenantAccrualForm.php

namespace App\Filament\Resources\TenantAccruals\Schemas;

use App\Models\TenantAccrual;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class TenantAccrualForm
{
    public static function configure(Schema $schema): Schema
    {
        $readOnly = fn (): bool => true;

        return $schema->components([
            Section::make('Сводка')
                ->description('Ключевой контекст начисления без технического шума.')
                ->columns(6)
                ->columnSpanFull()
                ->extraAttributes(['class' => 'accrual-summary-section'])
                ->schema([
                    static::display('summary_period', 'period', 'Период начисления', fn ($value): string => $value?->format('m.Y') ?: '—'),
                    static::display('summary_tenant', 'tenant.name', 'Арендатор'),
                    static::display('summary_space', 'marketSpace.number', 'Место'),
                    static::display('summary_contract', 'tenantContract.number', 'Договор'),
                    static::displayMoney('summary_total_with_vat', 'total_with_vat', 'Итого к оплате'),
                    static::display('summary_source', 'source', 'Источник'),
                ]),

            Section::make('Начисление')
                ->description('Основные финансовые показатели и расчетная база.')
                ->columns(4)
                ->columnSpanFull()
                ->extraAttributes(['class' => 'accrual-finance-section'])
                ->schema([
                    static::displayNumber('finance_rent_rate', 'rent_rate', 'Ставка'),
                    static::displayNumber('finance_area_sqm', 'area_sqm', 'Площадь, м²'),
                    static::displayMoney('finance_rent_amount', 'rent_amount', 'Аренда'),
                    static::displayMoney('finance_management_fee', 'management_fee', 'Управление'),
                    static::displayMoney('finance_utilities_amount', 'utilities_amount', 'Коммунальные услуги'),
                    static::displayMoney('finance_electricity_amount', 'electricity_amount', 'Электроэнергия'),

                    static::displayMoney('finance_cash_amount', 'cash_amount', 'Наличные'),
                    static::displayMoney('finance_total_no_vat', 'total_no_vat', 'Итого без НДС'),
                    static::displayNumber('finance_vat_rate', 'vat_rate', 'НДС, ставка'),
                    static::displayMoney('finance_total_with_vat', 'total_with_vat', 'Итого к оплате'),
                ]),

            Section::make('Контекст и привязка')
                ->description('Источник строки и диагностическая связка с договором.')
                ->columns(3)
                ->columnSpanFull()
                ->extraAttributes(['class' => 'accrual-context-section'])
                ->schema([
                    static::display('context_location', 'marketSpace.location.name', 'Локация'),
                    static::display('context_source_place_code', 'source_place_code', 'Код места из файла'),
                    static::display('context_source_place_name', 'source_place_name', 'Название отдела'),

                    static::display('context_activity_type', 'activity_type', 'Вид деятельности'),
                    static::display('context_contract_external_id', 'contract_external_id', 'ID договора 1С'),
                    static::display('context_contract_link_status', 'contract_link_status', 'Связь с договором', function ($value): string {
                        return match ($value) {
                            TenantAccrual::CONTRACT_LINK_STATUS_EXACT => 'Точное совпадение',
                            TenantAccrual::CONTRACT_LINK_STATUS_RESOLVED => 'Разрешено по контексту',
                            TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS => 'Неоднозначно',
                            TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED => 'Без договора',
                            default => filled($value) ? (string) $value : '—',
                        };
                    }),

                    static::display('context_contract_link_source', 'contract_link_source', 'Источник связки'),

                    Placeholder::make('contract_link_note')
                        ->label('Примечание по связке')
                        ->columnSpanFull()
                        ->content(function (?TenantAccrual $record): HtmlString {
                            $text = trim((string) ($record?->contract_link_note ?? ''));

                            if ($text === '') {
                                return new HtmlString('—');
                            }

                            return new HtmlString(nl2br(e($text)));
                        }),
                ]),

            Section::make('Комментарии')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Placeholder::make('discount_note')
                        ->label('Скидки / доп. соглашения (из файла)')
                        ->content(function (?TenantAccrual $record): HtmlString {
                            $text = trim((string) ($record?->discount_note ?? ''));

                            if ($text === '') {
                                return new HtmlString('—');
                            }

                            return new HtmlString(nl2br(e($text)));
                        }),

                    Textarea::make('notes')
                        ->label('Примечания (внутренние)')
                        ->helperText('Это поле редактируется вручную и не перезаписывается импортом.')
                        ->rows(4),
                ]),

            Section::make('Источник и служебные поля')
                ->collapsed()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('status')
                        ->label('Статус')
                        ->disabled($readOnly)
                        ->dehydrated(false),

                    TextInput::make('source_file')
                        ->label('Файл')
                        ->disabled($readOnly)
                        ->dehydrated(false),

                    TextInput::make('source_row_number')
                        ->label('Строка в файле')
                        ->numeric()
                        ->disabled($readOnly)
                        ->dehydrated(false),

                    TextInput::make('source_row_hash')
                        ->label('Hash строки')
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->columnSpan(2),

                    DateTimePicker::make('imported_at')
                        ->label('Импортировано')
                        ->disabled($readOnly)
                        ->dehydrated(false),

                    Textarea::make('payload')
                        ->label('Payload (сырой ряд)')
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->rows(10)
                        ->columnSpanFull()
                        ->formatStateUsing(function ($state) {
                            if (is_array($state)) {
                                return json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                            }

                            if (is_string($state) && $state !== '') {
                                $decoded = json_decode($state, true);

                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                                }
                            }

                            return $state;
                        }),
                ]),
        ]);
    }

    private static function display(string $key, string $path, string $label, ?callable $formatter = null): Placeholder
    {
        return Placeholder::make($key)
            ->label($label)
            ->content(function (?TenantAccrual $record) use ($formatter, $path): HtmlString | string {
                $value = data_get($record, $path);

                if ($formatter) {
                    return $formatter($value, $record);
                }

                return static::formatText($value);
            });
    }

    private static function displayMoney(string $key, string $path, string $label): Placeholder
    {
        return static::display($key, $path, $label, fn ($value): string => static::formatNumber($value, 2));
    }

    private static function displayNumber(string $key, string $path, string $label): Placeholder
    {
        return static::display($key, $path, $label, fn ($value): string => static::formatNumber($value, 2));
    }

    private static function formatText(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : '—';
    }

    private static function formatNumber(mixed $value, int $decimals): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format((float) $value, $decimals, ',', ' ');
    }
}

<?php
# app/Filament/Resources/TenantAccruals/Schemas/TenantAccrualForm.php

namespace App\Filament\Resources\TenantAccruals\Schemas;

use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class TenantAccrualForm
{
    public static function configure(Schema $schema): Schema
    {
        // Начисления — источник: импорт. В интерфейсе делаем карточку “для чтения”,
        // оставляя возможность точечно править только заметки (notes).
        $readOnly = fn (): bool => true;

        return $schema->components([
            Section::make('Общее')
                ->columns(3)
                ->schema([
                    TextInput::make('period')
                        ->label('Период')
                        ->disabled($readOnly)
                        ->dehydrated(false),

                    TextInput::make('tenant.name')
                        ->label('Арендатор')
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('marketSpace.number')
                        ->label('Место')
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('marketSpace.location.name')
                        ->label('Локация')
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('source_place_code')
                        ->label('Код места из файла')
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('source_place_name')
                        ->label('Название отдела')
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('activity_type')
                        ->label('Вид деятельности')
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('area_sqm')
                        ->label('Площадь, м²')
                        ->numeric()
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('days')
                        ->label('Дней')
                        ->numeric()
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),
                ]),

            Section::make('Начисления')
                ->columns(3)
                ->schema([
                    TextInput::make('rent_rate')
                        ->label('Ставка')
                        ->numeric()
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('rent_amount')
                        ->label('Аренда')
                        ->numeric()
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('management_fee')
                        ->label('Управление')
                        ->numeric()
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('utilities_amount')
                        ->label('Коммунальные услуги')
                        ->numeric()
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('electricity_amount')
                        ->label('Электроэнергия')
                        ->numeric()
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('cash_amount')
                        ->label('Наличные')
                        ->numeric()
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),
                ]),

            Section::make('Итоги и НДС')
                ->columns(3)
                ->schema([
                    TextInput::make('total_no_vat')
                        ->label('Итого без НДС')
                        ->numeric()
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('vat_rate')
                        ->label('НДС, ставка')
                        ->numeric()
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),

                    TextInput::make('total_with_vat')
                        ->label('Итого к оплате')
                        ->numeric()
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->placeholder('—'),
                ]),

            Section::make('Скидки и комментарии')
                ->columns(2)
                ->schema([
                    Textarea::make('discount_note')
                        ->label('Скидки / доп. соглашения (из файла)')
                        ->disabled($readOnly)
                        ->dehydrated(false)
                        ->rows(3)
                        ->columnSpanFull()
                        ->placeholder('—'),

                    Textarea::make('notes')
                        ->label('Примечания (внутренние)')
                        ->helperText('Это поле редактируется вручную и не перезаписывается импортом.')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Section::make('Источник и служебные поля')
                ->collapsed()
                ->columns(3)
                ->schema([
                    TextInput::make('status')
                        ->label('Статус')
                        ->disabled($readOnly)
                        ->dehydrated(false),

                    TextInput::make('source')
                        ->label('Источник')
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
                                // на случай, если пришло строкой
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
}

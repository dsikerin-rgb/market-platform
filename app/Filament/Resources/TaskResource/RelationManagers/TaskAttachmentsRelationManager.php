<?php
# app/Filament/Resources/TaskResource/RelationManagers/TaskAttachmentsRelationManager.php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\RelationManagers;

use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TaskAttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    // Как в Битрикс24: вкладка “Файлы”
    protected static ?string $title = 'Файлы';

    protected static ?string $recordTitleAttribute = 'original_name';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\FileUpload::make('file_path')
                ->label('Файл')
                ->directory('task-attachments')
                ->preserveFilenames()
                ->downloadable()
                ->openable()
                ->required(),

            // Показать пользователю имя файла, но не сохранять из формы (сохранит модель в booted()).
            Forms\Components\TextInput::make('original_name')
                ->label('Название')
                ->placeholder('Имя файла (заполним автоматически)')
                ->maxLength(255)
                ->disabled()
                ->dehydrated(false)
                ->helperText('Заполняется автоматически из имени загруженного файла.'),
        ]);
    }

    public function table(Table $table): Table
    {
        // -------------------------
        // Header action: “Загрузить файл”
        // -------------------------
        $headerActions = [];

        $create = null;
        if (class_exists(\Filament\Tables\Actions\CreateAction::class)) {
            $create = \Filament\Tables\Actions\CreateAction::make();
        } elseif (class_exists(\Filament\Actions\CreateAction::class)) {
            $create = \Filament\Actions\CreateAction::make();
        }

        if ($create) {
            $create
                ->label('Загрузить файл')
                ->icon('heroicon-o-arrow-up-tray');

            if (method_exists($create, 'modalHeading')) {
                $create->modalHeading('Загрузка файла');
            }
            if (method_exists($create, 'modalSubmitActionLabel')) {
                $create->modalSubmitActionLabel('Загрузить');
            }
            if (method_exists($create, 'slideOver')) {
                $create->slideOver();
            }

            // Подстрахуем original_name (если где-то нужен), но основное заполнение делает модель.
            if (method_exists($create, 'mutateFormDataUsing')) {
                $create->mutateFormDataUsing(function (array $data): array {
                    $path = $data['file_path'] ?? null;

                    if (filled($path) && empty($data['original_name'])) {
                        $data['original_name'] = basename((string) $path);
                    }

                    return $data;
                });
            }

            $headerActions[] = $create;
        }

        // -------------------------
        // Row actions: icon-only (как в Б24)
        // -------------------------
        $rowActions = [];

        $download = null;
        if (class_exists(\Filament\Tables\Actions\Action::class)) {
            $download = \Filament\Tables\Actions\Action::make('download');
        } elseif (class_exists(\Filament\Actions\Action::class)) {
            $download = \Filament\Actions\Action::make('download');
        }

        if ($download) {
            $download
                ->label('') // только иконка
                ->icon('heroicon-o-arrow-down-tray')
                ->tooltip('Скачать')
                ->url(function ($record): ?string {
                    $path = $record->file_path ?? null;

                    return filled($path) ? Storage::url((string) $path) : null;
                })
                ->openUrlInNewTab()
                ->visible(fn ($record): bool => filled($record?->file_path));

            if (method_exists($download, 'iconButton')) {
                $download->iconButton();
            }

            $rowActions[] = $download;
        }

        $delete = null;
        if (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $delete = \Filament\Tables\Actions\DeleteAction::make();
        } elseif (class_exists(\Filament\Actions\DeleteAction::class)) {
            $delete = \Filament\Actions\DeleteAction::make();
        }

        if ($delete) {
            $delete
                ->label('') // только иконка
                ->icon('heroicon-o-trash')
                ->tooltip('Удалить')
                ->requiresConfirmation();

            if (method_exists($delete, 'iconButton')) {
                $delete->iconButton();
            }

            $rowActions[] = $delete;
        }

        // -------------------------
        // Columns
        // -------------------------
        $fileName = TextColumn::make('original_name')
            ->label('Файл')
            ->formatStateUsing(function (?string $state, $record): string {
                $name = filled($state)
                    ? (string) $state
                    : (filled($record?->file_path) ? basename((string) $record->file_path) : '—');

                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $base = $ext ? Str::replaceLast('.' . $ext, '', $name) : $name;

                $base = Str::limit($base, 60);

                return $ext ? ($base . '.' . $ext) : $base;
            })
            ->url(function ($record): ?string {
                $path = $record->file_path ?? null;

                return filled($path) ? Storage::url((string) $path) : null;
            });

        if (method_exists($fileName, 'openUrlInNewTab')) {
            $fileName->openUrlInNewTab();
        }
        if (method_exists($fileName, 'wrap')) {
            $fileName->wrap();
        }

        $size = TextColumn::make('file_path')
            ->label('Размер')
            ->formatStateUsing(function ($state, $record): string {
                $path = $record->file_path ?? null;

                if (! filled($path)) {
                    return '—';
                }

                try {
                    $bytes = Storage::size((string) $path);
                } catch (\Throwable) {
                    return '—';
                }

                $kb = 1024;
                $mb = 1024 * 1024;

                if ($bytes >= $mb) {
                    return number_format($bytes / $mb, 1, '.', '') . ' МБ';
                }

                if ($bytes >= $kb) {
                    return number_format($bytes / $kb, 0, '.', '') . ' КБ';
                }

                return (string) $bytes . ' Б';
            });

        $addedAt = TextColumn::make('created_at')
            ->label('Добавлен')
            ->dateTime('d.m.Y H:i')
            ->sortable();

        $table = $table
            ->columns([$fileName, $size, $addedAt])
            ->headerActions($headerActions)
            ->emptyStateActions($headerActions)
            ->defaultSort('created_at', 'desc');

        if (! empty($rowActions)) {
            $table = $table->actions($rowActions);
        }

        // Чтобы строка не была “кликабельной” как запись
        if (method_exists($table, 'recordUrl')) {
            $table->recordUrl(null);
        }
        if (method_exists($table, 'recordAction')) {
            $table->recordAction(null);
        }

        return $table;
    }

    public function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        /** @var Builder $query */
        $query = $this->getRelationship()->getQuery();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        $owner = $this->getOwnerRecord();

        if ($user->market_id && $owner && (int) $owner->market_id === (int) $user->market_id) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }
}

<?php
# app/Filament/Resources/TaskResource/RelationManagers/TaskAttachmentsRelationManager.php

namespace App\Filament\Resources\TaskResource\RelationManagers;

use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class TaskAttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Вложения';

    protected static ?string $recordTitleAttribute = 'original_name';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\FileUpload::make('file_path')
                ->label('Файл')
                ->directory('task-attachments')
                ->preserveFilenames()
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        $headerActions = [];

        if (class_exists(\Filament\Actions\CreateAction::class)) {
            $headerActions[] = \Filament\Actions\CreateAction::make()->label('Добавить файл');
        } elseif (class_exists(\Filament\Tables\Actions\CreateAction::class)) {
            $headerActions[] = \Filament\Tables\Actions\CreateAction::make()->label('Добавить файл');
        }

        $rowActions = [];

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $rowActions[] = \Filament\Actions\DeleteAction::make()->label('Удалить');
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $rowActions[] = \Filament\Tables\Actions\DeleteAction::make()->label('Удалить');
        }

        $table = $table
            ->columns([
                TextColumn::make('original_name')
                    ->label('Файл')
                    ->formatStateUsing(fn (?string $state) => $state ?: '—')
                    ->url(function ($record): ?string {
                        $path = $record->file_path ?? null;

                        return $path ? Storage::url($path) : null;
                    }),

                TextColumn::make('created_at')
                    ->label('Добавлен')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions($headerActions)
            ->emptyStateActions($headerActions);

        if (! empty($rowActions)) {
            $table = $table->actions($rowActions);
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

        if ($user->market_id && $owner && $owner->market_id === $user->market_id) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }
}

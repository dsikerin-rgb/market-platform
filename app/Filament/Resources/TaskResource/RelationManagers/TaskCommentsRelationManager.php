<?php
# app/Filament/Resources/TaskResource/RelationManagers/TaskCommentsRelationManager.php

namespace App\Filament\Resources\TaskResource\RelationManagers;

use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskCommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Комментарии';

    protected static ?string $recordTitleAttribute = 'body';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Hidden::make('author_user_id')
                ->default(fn () => Filament::auth()->id())
                ->dehydrated(true),

            Forms\Components\Textarea::make('body')
                ->label('Комментарий')
                ->rows(3)
                ->required()
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        $headerActions = [];

        if (class_exists(\Filament\Actions\CreateAction::class)) {
            $headerActions[] = \Filament\Actions\CreateAction::make()->label('Добавить комментарий');
        } elseif (class_exists(\Filament\Tables\Actions\CreateAction::class)) {
            $headerActions[] = \Filament\Tables\Actions\CreateAction::make()->label('Добавить комментарий');
        }

        $rowActions = [];

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $rowActions[] = \Filament\Actions\EditAction::make()->label('Редактировать');
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $rowActions[] = \Filament\Tables\Actions\EditAction::make()->label('Редактировать');
        }

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $rowActions[] = \Filament\Actions\DeleteAction::make()->label('Удалить');
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $rowActions[] = \Filament\Tables\Actions\DeleteAction::make()->label('Удалить');
        }

        $table = $table
            ->columns([
                TextColumn::make('author.name')
                    ->label('Автор')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('body')
                    ->label('Комментарий')
                    ->limit(80),

                TextColumn::make('created_at')
                    ->label('Создан')
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

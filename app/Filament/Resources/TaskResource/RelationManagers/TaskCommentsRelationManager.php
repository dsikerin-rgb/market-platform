<?php
# app/Filament/Resources/TaskResource/RelationManagers/TaskCommentsRelationManager.php

declare(strict_types=1);

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

    // Как в Битрикс24: это именно “Чат задачи”
    protected static ?string $title = 'Чат задачи';

    protected static ?string $recordTitleAttribute = 'body';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Hidden::make('author_user_id')
                ->default(fn () => Filament::auth()->id())
                ->dehydrated(true),

            Forms\Components\Textarea::make('body')
                ->label('Сообщение')
                ->placeholder('Написать сообщение…')
                ->rows(4)
                ->required()
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        // -------------------------
        // Header action: “Написать”
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
                ->label('Написать')
                ->icon('heroicon-o-paper-airplane');

            if (method_exists($create, 'modalHeading')) {
                $create->modalHeading('Новое сообщение');
            }
            if (method_exists($create, 'modalSubmitActionLabel')) {
                $create->modalSubmitActionLabel('Отправить');
            }
            if (method_exists($create, 'slideOver')) {
                $create->slideOver();
            }

            $headerActions[] = $create;
        }

        // -------------------------
        // Row actions: icon-only (как в Б24)
        // -------------------------
        $rowActions = [];

        $edit = null;
        if (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $edit = \Filament\Tables\Actions\EditAction::make();
        } elseif (class_exists(\Filament\Actions\EditAction::class)) {
            $edit = \Filament\Actions\EditAction::make();
        }

        if ($edit) {
            // В некоторых версиях пустая строка может считаться “не задано” и подставится дефолтный label.
            // Поэтому ставим пробел: визуально текста нет, но label считается заданным.
            $edit
                ->label(' ')
                ->icon('heroicon-o-pencil-square')
                ->tooltip('Редактировать')
                ->visible(function ($record): bool {
                    $user = Filament::auth()->user();

                    if (! $user || ! $record) {
                        return false;
                    }

                    if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                        return true;
                    }

                    return (int) ($record->author_user_id ?? 0) === (int) $user->id;
                });

            // Пытаемся максимально “icon-only” во всех версиях
            if (method_exists($edit, 'iconButton')) {
                $edit->iconButton();
            }
            if (method_exists($edit, 'hiddenLabel')) {
                $edit->hiddenLabel();
            }
            if (method_exists($edit, 'modalHeading')) {
                $edit->modalHeading('Редактировать сообщение');
            }
            if (method_exists($edit, 'modalSubmitActionLabel')) {
                $edit->modalSubmitActionLabel('Сохранить');
            }
            if (method_exists($edit, 'slideOver')) {
                $edit->slideOver();
            }

            $rowActions[] = $edit;
        }

        $delete = null;
        if (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $delete = \Filament\Tables\Actions\DeleteAction::make();
        } elseif (class_exists(\Filament\Actions\DeleteAction::class)) {
            $delete = \Filament\Actions\DeleteAction::make();
        }

        if ($delete) {
            $delete
                ->label(' ')
                ->icon('heroicon-o-trash')
                ->tooltip('Удалить')
                ->requiresConfirmation()
                ->visible(function ($record): bool {
                    $user = Filament::auth()->user();

                    if (! $user || ! $record) {
                        return false;
                    }

                    if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                        return true;
                    }

                    return (int) ($record->author_user_id ?? 0) === (int) $user->id;
                });

            if (method_exists($delete, 'iconButton')) {
                $delete->iconButton();
            }
            if (method_exists($delete, 'hiddenLabel')) {
                $delete->hiddenLabel();
            }

            $rowActions[] = $delete;
        }

        // -------------------------
        // “Пузырь” сообщения
        // -------------------------
        $bubble = TextColumn::make('body')
            ->label('')
            ->formatStateUsing(function ($state, $record): string {
                $userId = (int) (Filament::auth()->id() ?? 0);
                $authorId = (int) ($record->author_user_id ?? 0);

                $isMine = $userId !== 0 && $authorId === $userId;

                $author = $record->author?->name;
                $author = filled($author) ? (string) $author : ('Пользователь #' . $authorId);

                $time = $record->created_at ? $record->created_at->format('d.m.Y H:i') : '';

                $body = filled($state) ? (string) $state : '—';

                $bodyHtml = nl2br(e($body));
                $meta = e($author) . (filled($time) ? (' • ' . e($time)) : '');

                $align = $isMine ? 'justify-end' : 'justify-start';
                $bubbleBg = $isMine
                    ? 'bg-primary-600 text-white'
                    : 'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-gray-100';
                $metaText = $isMine ? 'text-primary-100' : 'text-gray-500 dark:text-gray-400';

                return <<<HTML
<div class="w-full flex {$align}">
    <div class="max-w-[85%] rounded-2xl px-3 py-2 {$bubbleBg}">
        <div class="text-xs {$metaText} mb-1">{$meta}</div>
        <div class="text-sm leading-relaxed break-words">{$bodyHtml}</div>
    </div>
</div>
HTML;
            });

        if (method_exists($bubble, 'html')) {
            $bubble->html();
        }

        // -------------------------
        // Table: режим “чата”
        // -------------------------
        $table = $table
            ->columns([$bubble])
            ->headerActions($headerActions)
            ->emptyStateActions($headerActions)
            ->defaultSort('created_at', 'desc');

        if (method_exists($table, 'poll')) {
            $table->poll('10s');
        }

        // Опционально: чтобы не плодить горизонтальный скролл в узкой правой колонке.
        // Если захочешь обратно dropdown “10/25/50…” — верни paginated([..]).
        if (method_exists($table, 'paginated')) {
            try {
                $table->paginated(false);
            } catch (\Throwable) {
                // тихо игнорируем несовместимые сигнатуры
            }
        }

        if (! empty($rowActions)) {
            $table = $table->actions($rowActions);

            // Сжимаем колонку с actions, чтобы не провоцировать horizontal scroll
            if (method_exists($table, 'actionsColumnLabel')) {
                $table->actionsColumnLabel('');
            }
            if (method_exists($table, 'actionsColumnWidth')) {
                $table->actionsColumnWidth('56px');
            }
        }

        // Чтобы строка не была кликабельной как запись
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
        $query = $this->getRelationship()->getQuery()->with('author');

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

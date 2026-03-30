<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\TenantContractResource;
use App\Models\TenantContract;
use App\Services\TenantContracts\ContractDocumentClassifier;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    protected static ?string $title = 'Договоры';

    protected static ?string $recordTitleAttribute = 'number';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('Договор')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('marketSpace.number')
                    ->label('Место')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->url(fn ($record): ?string => $record?->marketSpace && MarketSpaceResource::canEdit($record->marketSpace)
                        ? MarketSpaceResource::getUrl('edit', ['record' => $record->marketSpace])
                        : null),

                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state): string => $this->statusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => $this->statusColor($state))
                    ->sortable(),

                TextColumn::make('starts_at')
                    ->label('Период')
                    ->state(fn (?TenantContract $record): string => $this->periodLabel($record))
                    ->tooltip(fn (?TenantContract $record): ?string => $this->periodTooltip($record)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'draft' => 'Черновик',
                        'active' => 'Активен',
                        'paused' => 'Приостановлен',
                        'terminated' => 'Расторгнут',
                        'archived' => 'Архив',
                    ]),

                TernaryFilter::make('has_space')
                    ->label('Привязка к месту')
                    ->trueLabel('Только с местом')
                    ->falseLabel('Только без места')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('market_space_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('market_space_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только неактивные')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('is_active', true),
                        false: fn (Builder $query): Builder => $query->where('is_active', false),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->striped()
            ->headerActions([])
            ->recordActions([
                static::openAction(),
            ])
            ->bulkActions([])
            ->paginated([10, 25, 50])
            ->recordUrl(function ($record): ?string {
                return $record && TenantContractResource::canEdit($record)
                    ? TenantContractResource::getUrl('edit', ['record' => $record])
                    : null;
            });
    }

    public function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        /** @var Builder $query */
        $query = $this->getRelationship()->getQuery()->with(['marketSpace']);

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    private function statusLabel(?string $state): string
    {
        return match ($state) {
            'draft' => 'Черновик',
            'active' => 'Активен',
            'paused' => 'Приостановлен',
            'terminated' => 'Расторгнут',
            'archived' => 'Архив',
            null, '' => '—',
            default => (string) $state,
        };
    }

    private function statusColor(?string $state): string
    {
        return match ($state) {
            'active' => 'success',
            'paused' => 'warning',
            'terminated' => 'danger',
            'draft', 'archived', null, '' => 'gray',
            default => 'gray',
        };
    }

    private function periodLabel(?TenantContract $record): string
    {
        if (! $record) {
            return '—';
        }

        $start = $this->primaryPeriodStart($record);
        $end = $this->formatDate($record->ends_at);

        if ($start === '—') {
            return '—';
        }

        if ($end === '—') {
            return $start;
        }

        return $start.' - '.$end;
    }

    private function periodTooltip(?TenantContract $record): ?string
    {
        if (! $record) {
            return null;
        }

        $documentDate = $this->documentDateFromNumber($record);
        if ($documentDate !== null) {
            return 'Дата из номера договора: '.$this->formatDate($documentDate);
        }

        $signed = $this->formatDate($record->signed_at);

        return $signed === '—' ? null : 'Подписан: '.$signed;
    }

    private function formatDate(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('d.m.Y');
        }

        if (! filled($value)) {
            return '—';
        }

        try {
            return Carbon::parse((string) $value)->format('d.m.Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function primaryPeriodStart(TenantContract $record): string
    {
        $documentDate = $this->documentDateFromNumber($record);

        if ($documentDate !== null) {
            return $this->formatDate($documentDate);
        }

        $signed = $this->formatDate($record->signed_at);
        if ($signed !== '—') {
            return $signed;
        }

        return $this->formatDate($record->starts_at);
    }

    private function documentDateFromNumber(TenantContract $record): ?string
    {
        $classified = app(ContractDocumentClassifier::class)->classify((string) ($record->number ?? ''));

        return filled($classified['document_date'] ?? null)
            ? (string) $classified['document_date']
            : null;
    }

    private static function openAction()
    {
        if (class_exists(\Filament\Tables\Actions\Action::class)) {
            return \Filament\Tables\Actions\Action::make('open')
                ->label('')
                ->tooltip('Открыть')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->iconButton()
                ->url(fn ($record): string => TenantContractResource::getUrl('edit', ['record' => $record]));
        }

        return \Filament\Actions\Action::make('open')
            ->label('')
            ->tooltip('Открыть')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->iconButton()
            ->url(fn ($record): string => TenantContractResource::getUrl('edit', ['record' => $record]));
    }
}

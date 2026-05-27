<?php
# app/Filament/Resources/MarketSpaceResource/Pages/CreateMarketSpace.php

declare(strict_types=1);

namespace App\Filament\Resources\MarketSpaceResource\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\Pages\BaseCreateRecord;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class CreateMarketSpace extends BaseCreateRecord
{
    protected static string $resource = MarketSpaceResource::class;

    protected static ?string $title = 'Создать торговое место';

    #[Locked]
    public ?int $pendingShapeId = null;

    #[Locked]
    public ?string $returnUrl = null;

    public function mount(): void
    {
        $this->pendingShapeId = $this->resolveQueryInt('shape_id');
        $this->returnUrl = $this->normalizeReturnUrl(request()->query('return_url'));
        $this->storeSelectedMarketIdInSession($this->resolveQueryInt('market_id'));

        parent::mount();

        $this->prefillFormFromQuery();

        if ($this->pendingShapeId !== null) {
            Notification::make()
                ->title('После сохранения место будет сразу привязано к выбранной разметке.')
                ->info()
                ->send();
        }
    }

    public function canCreateAnother(): bool
    {
        if ($this->pendingShapeId !== null) {
            return false;
        }

        return parent::canCreateAnother();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->assertUniqueNumberWithinMarket($data);
        $this->assertMaintenanceSpaceIsNotGrouped($data);

        if ($this->pendingShapeId !== null) {
            $this->resolvePendingShapeForMarket(isset($data['market_id']) ? (int) $data['market_id'] : null);
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $record = parent::handleRecordCreation($data);

        $this->bindPendingShapeToRecord($record);

        return $record;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        if ($this->pendingShapeId !== null) {
            return 'Торговое место создано и привязано к разметке';
        }

        return 'Торговое место создано';
    }

    protected function getRedirectUrl(): string
    {
        if (filled($this->returnUrl)) {
            return $this->returnUrl;
        }

        return parent::getRedirectUrl();
    }

    private function bindPendingShapeToRecord(Model $record): void
    {
        if ($this->pendingShapeId === null) {
            return;
        }

        if (! $record instanceof MarketSpace) {
            $this->failCreateAndBind('Не удалось определить созданное торговое место.');
        }

        $shape = $this->resolvePendingShapeForMarket((int) $record->market_id);
        $shape->market_space_id = (int) $record->getKey();
        $shape->save();
    }

    private function resolvePendingShapeForMarket(?int $marketId): MarketSpaceMapShape
    {
        if ($this->pendingShapeId === null) {
            $this->failCreateAndBind('Не передан идентификатор разметки для привязки.');
        }

        $shape = MarketSpaceMapShape::query()->find($this->pendingShapeId);

        if (! $shape instanceof MarketSpaceMapShape) {
            $this->failCreateAndBind('Выбранная разметка не найдена. Вернитесь на карту и попробуйте снова.');
        }

        if ($marketId !== null && $marketId > 0 && (int) $shape->market_id !== $marketId) {
            $this->failCreateAndBind('Разметка относится к другому рынку. Создание остановлено.');
        }

        if ($shape->market_space_id !== null && (int) $shape->market_space_id > 0) {
            $this->failCreateAndBind('Эта разметка уже привязана к торговому месту. Создание нового места остановлено.');
        }

        return $shape;
    }

    private function assertUniqueNumberWithinMarket(array $data): void
    {
        $marketId = isset($data['market_id']) ? (int) $data['market_id'] : 0;
        $number = isset($data['number']) ? trim((string) $data['number']) : '';

        if ($marketId <= 0 || $number === '') {
            return;
        }

        $exists = MarketSpace::query()
            ->where('market_id', $marketId)
            ->whereRaw('LOWER(number) = ?', [mb_strtolower($number)])
            ->exists();

        if (! $exists) {
            return;
        }

        Notification::make()
            ->title('Место с таким номером уже существует')
            ->body('Не создавайте дубль. Вернитесь на карту и привяжите существующее место к выбранной разметке.')
            ->danger()
            ->send();

        throw ValidationException::withMessages([
            'data.number' => 'Место с таким номером уже существует. Вернитесь на карту и привяжите существующее место.',
        ]);
    }

    private function prefillFormFromQuery(): void
    {
        $prefill = [];

        $marketId = $this->resolveQueryInt('market_id');
        if ($marketId !== null) {
            $prefill['market_id'] = $marketId;
        }

        $number = trim((string) request()->query('number', ''));
        if ($number !== '') {
            $prefill['number'] = $number;
        }

        $role = (string) request()->query('space_group_role', '');
        if (in_array($role, MarketSpace::SPACE_GROUP_ROLES, true)) {
            $prefill['space_group_role'] = $role;
        }

        $parentId = $this->resolveQueryInt('space_group_parent_id');
        if ($parentId !== null) {
            $prefill['space_group_parent_id'] = $parentId;
        }

        $slot = trim((string) request()->query('space_group_slot', ''));
        if ($slot !== '') {
            $prefill['space_group_slot'] = $slot;
        }

        if ($prefill === []) {
            return;
        }

        $this->form->fill(array_merge($this->data ?? [], $prefill));
    }

    private function resolveQueryInt(string $key): ?int
    {
        $value = request()->query($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function storeSelectedMarketIdInSession(?int $marketId): void
    {
        if ($marketId === null) {
            return;
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        session(["filament_{$panelId}_market_id" => $marketId]);
    }

    private function normalizeReturnUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '/')) {
            if (str_starts_with($value, '//')) {
                return null;
            }

            return $value;
        }

        $parsed = parse_url($value);
        if (! is_array($parsed)) {
            return null;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $valueHost = $parsed['host'] ?? null;

        if (! is_string($appHost) || ! is_string($valueHost) || mb_strtolower($appHost) !== mb_strtolower($valueHost)) {
            return null;
        }

        $path = isset($parsed['path']) && is_string($parsed['path']) ? $parsed['path'] : '/';
        $query = isset($parsed['query']) && is_string($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) && is_string($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return $path . $query . $fragment;
    }

    private function failCreateAndBind(string $message): never
    {
        Notification::make()
            ->title('Не удалось создать место и привязать разметку')
            ->body($message)
            ->danger()
            ->send();

        throw ValidationException::withMessages([
            'data.market_id' => $message,
        ]);
    }

    private function assertMaintenanceSpaceIsNotGrouped(array $data): void
    {
        $status = trim((string) ($data['status'] ?? 'vacant'));
        $role = trim((string) ($data['space_group_role'] ?? MarketSpace::SPACE_GROUP_ROLE_NONE));

        if ($status !== 'maintenance') {
            return;
        }

        if ($role !== MarketSpace::SPACE_GROUP_ROLE_NONE) {
            throw ValidationException::withMessages([
                'space_group_role' => 'Служебное место не может входить в группу и не может быть parent-группой.',
            ]);
        }
    }
}

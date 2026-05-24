<?php

declare(strict_types=1);

namespace App\Services\MarketMap;

use App\Domain\Operations\OperationType;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Service для исправления effective_date у уже применённой пары tenant_switch + space_review.
 *
 * Ключевые особенности:
 * - Использует DB::table() для обновления операций (избегает boot-обработчика Operation model)
 * - Сохраняет время операций, меняет только календарную дату
 * - Работает с effective_at в UTC
 * - Все guard-checks выполняются до начала транзакции
 * - Audit operation создаётся через DB::table()->insertGetId()
 */
final class OperationEffectiveDateFixer
{
    /**
     * Исправляет effective_date для linked tenant_switch + space_review.
     *
     * @param  int  $spaceReviewOperationId  ID applied SPACE_REVIEW операции
     * @param  int  $tenantSwitchOperationId  ID linked TENANT_SWITCH операции
     * @param  string  $newEffectiveDate  Новая дата в формате Y-m-d
     * @param  string  $reason  Причина исправления (5-1000 символов)
     * @param  int|null  $actorUserId  ID пользователя, выполняющего исправление
     * @return array{ok: bool, message: string, fixed_operation_ids?: array{tenant_switch: int, space_review: int}}
     */
    public function fixEffectiveDate(
        int $spaceReviewOperationId,
        int $tenantSwitchOperationId,
        string $newEffectiveDate,
        string $reason,
        ?int $actorUserId = null
    ): array {
        try {
            // 1. Проверить существование и статус операций
            $spaceReview = Operation::query()
                ->whereKey($spaceReviewOperationId)
                ->first();

            if (! $spaceReview) {
                return [
                    'ok' => false,
                    'message' => 'Ревизия места (space_review) не найдена.',
                ];
            }

            $tenantSwitch = Operation::query()
                ->whereKey($tenantSwitchOperationId)
                ->first();

            if (! $tenantSwitch) {
                return [
                    'ok' => false,
                    'message' => 'Смена арендатора (tenant_switch) не найдена.',
                ];
            }

            // 2. Проверить, что обе операции относятся к одному market
            if ((int) $spaceReview->market_id !== (int) $tenantSwitch->market_id) {
                return [
                    'ok' => false,
                    'message' => 'Операции относятся к разным рынкам.',
                ];
            }

            $marketId = (int) $spaceReview->market_id;
            $market = Market::query()->whereKey($marketId)->first();

            if (! $market) {
                return [
                    'ok' => false,
                    'message' => 'Рынок не найден.',
                ];
            }

            $marketTz = $market->timezone ?: (string) config('app.timezone', 'UTC');

            // 3. Проверить типы операций
            if ($spaceReview->type !== OperationType::SPACE_REVIEW) {
                return [
                    'ok' => false,
                    'message' => 'Первая операция не является SPACE_REVIEW.',
                ];
            }

            if ($tenantSwitch->type !== OperationType::TENANT_SWITCH) {
                return [
                    'ok' => false,
                    'message' => 'Вторая операция не является TENANT_SWITCH.',
                ];
            }

            // 4. Проверить статус applied
            if ($spaceReview->status !== 'applied') {
                return [
                    'ok' => false,
                    'message' => 'SPACE_REVIEW должен иметь статус "applied".',
                ];
            }

            if ($tenantSwitch->status !== 'applied') {
                return [
                    'ok' => false,
                    'message' => 'TENANT_SWITCH должен иметь статус "applied".',
                ];
            }

            // 5. Проверить, что space_review имеет decision = matched
            $spaceReviewPayload = is_array($spaceReview->payload) ? $spaceReview->payload : [];
            $decision = (string) ($spaceReviewPayload['decision'] ?? '');

            if ($decision !== 'matched') {
                return [
                    'ok' => false,
                    'message' => 'SPACE_REVIEW должен иметь decision "matched".',
                ];
            }

            // 6. Проверить linked Entity_id (обе операции к одному месту)
            $spaceReviewEntityId = (int) ($spaceReview->entity_id ?? 0);
            $tenantSwitchEntityId = (int) ($tenantSwitch->entity_id ?? 0);

            if ($spaceReviewEntityId <= 0 || $tenantSwitchEntityId <= 0) {
                return [
                    'ok' => false,
                    'message' => 'Операции должны быть привязаны к market_space (entity_id).',
                ];
            }

            if ($spaceReviewEntityId !== $tenantSwitchEntityId) {
                return [
                    'ok' => false,
                    'message' => 'Операции должны относиться к одному торговому месту (entity_id).',
                ];
            }

            // 7. Проверить, что tenant_switch создан до space_review
            if ((int) $tenantSwitch->id >= (int) $spaceReview->id) {
                return [
                    'ok' => false,
                    'message' => 'TENANT_SWITCH должен быть создан до SPACE_REVIEW (id tenant_switch < id space_review).',
                ];
            }

            // 8. Проверить, что созданы одним пользователем
            if ((int) $tenantSwitch->created_by !== (int) $spaceReview->created_by) {
                return [
                    'ok' => false,
                    'message' => 'Операции должны быть созданы одним пользователем.',
                ];
            }

            // 9. Проверить причину
            $reason = trim($reason);
            if ($reason === '') {
                return [
                    'ok' => false,
                    'message' => 'Причина исправления не может быть пустой.',
                ];
            }

            if (mb_strlen($reason, 'UTF-8') < 5) {
                return [
                    'ok' => false,
                    'message' => 'Причина должна содержать минимум 5 символов.',
                ];
            }

            // 10. Проверить формат даты
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $newEffectiveDate)) {
                return [
                    'ok' => false,
                    'message' => 'Некорректный формат даты. Используйте YYYY-MM-DD.',
                ];
            }

            // 11. Проверить, что дата не в будущем
            try {
                $newDateCarbon = CarbonImmutable::createFromFormat('Y-m-d', $newEffectiveDate, $marketTz);
            } catch (\Throwable) {
                return [
                    'ok' => false,
                    'message' => 'Некорректная дата.',
                ];
            }

            $nowUtc = CarbonImmutable::now('UTC');
            if ($newDateCarbon->startOfDay()->gt($nowUtc)) {
                return [
                    'ok' => false,
                    'message' => 'Новая дата не может быть в будущем.',
                ];
            }

            // 12. Получить старые effective_at (уже в UTC)
            if (! $tenantSwitch->effective_at || ! $spaceReview->effective_at) {
                return [
                    'ok' => false,
                    'message' => 'Операции не имеют effective_at.',
                ];
            }

            $oldTenantSwitchEffectiveAt = CarbonImmutable::parse($tenantSwitch->effective_at, 'UTC');
            $oldSpaceReviewEffectiveAt = CarbonImmutable::parse($spaceReview->effective_at, 'UTC');

            // 13. Вычислить новые effective_at: новая дата + старое время
            $newTenantSwitchEffectiveAt = $oldTenantSwitchEffectiveAt->setDate(
                (int) $newDateCarbon->format('Y'),
                (int) $newDateCarbon->format('m'),
                (int) $newDateCarbon->format('d')
            );

            $newSpaceReviewEffectiveAt = $oldSpaceReviewEffectiveAt->setDate(
                (int) $newDateCarbon->format('Y'),
                (int) $newDateCarbon->format('m'),
                (int) $newDateCarbon->format('d')
            );

            // 14. Вычислить effective_month (первое число месяца)
            $newEffectiveMonth = $newDateCarbon->startOfMonth();

            // === ВСЕ ПРОВЕРКИ ЗАВЕРШЕНЫ, ОТКРЫВАЕМ ТРАНЗАКЦИЮ ===
            DB::beginTransaction();

            // 15. Обновить операции через DB::table (без boot-обработчика)
            DB::table('operations')
                ->where('id', $tenantSwitch->id)
                ->update([
                    'effective_at' => $newTenantSwitchEffectiveAt->format('Y-m-d H:i:s'),
                    'effective_month' => $newEffectiveMonth->format('Y-m-d'),
                    'updated_at' => now('UTC'),
                ]);

            DB::table('operations')
                ->where('id', $spaceReview->id)
                ->update([
                    'effective_at' => $newSpaceReviewEffectiveAt->format('Y-m-d H:i:s'),
                    'effective_month' => $newEffectiveMonth->format('Y-m-d'),
                    'updated_at' => now('UTC'),
                ]);

            // 16. Обновить market_spaces.map_reviewed_at, если место matched
            $space = MarketSpace::query()
                ->where('market_id', $marketId)
                ->whereKey($spaceReviewEntityId)
                ->first();

            if ($space && $space->map_review_status === 'matched') {
                DB::table('market_spaces')
                    ->where('id', $space->id)
                    ->update([
                        'map_reviewed_at' => $newSpaceReviewEffectiveAt->format('Y-m-d H:i:s'),
                        'updated_at' => now('UTC'),
                    ]);
            }

            // 17. Создать audit operation через DB::table (обходим boot-processor)
            $auditPayload = [
                'market_space_id' => $spaceReviewEntityId,
                'decision' => 'matched',
                'audit_type' => 'effective_date_corrected',
                'corrected_operation_ids' => [(int) $tenantSwitch->id, (int) $spaceReview->id],
                'old_effective_at' => [
                    'tenant_switch' => $oldTenantSwitchEffectiveAt->toIso8601String(),
                    'space_review' => $oldSpaceReviewEffectiveAt->toIso8601String(),
                ],
                'new_effective_at' => [
                    'tenant_switch' => $newTenantSwitchEffectiveAt->toIso8601String(),
                    'space_review' => $newSpaceReviewEffectiveAt->toIso8601String(),
                ],
                'reason' => $reason,
            ];

            $auditOperationId = DB::table('operations')->insertGetId([
                'market_id' => $marketId,
                'entity_type' => 'market_space',
                'entity_id' => $spaceReviewEntityId,
                'type' => OperationType::SPACE_REVIEW,
                'effective_at' => $newSpaceReviewEffectiveAt->format('Y-m-d H:i:s'),
                'effective_month' => $newEffectiveMonth->format('Y-m-d'),
                'status' => 'applied',
                'payload' => json_encode($auditPayload, JSON_UNESCAPED_UNICODE),
                'comment' => "Исправление даты: {$reason}",
                'created_by' => $actorUserId ?? auth()->id() ?? null,
                'created_at' => now('UTC')->format('Y-m-d H:i:s'),
                'updated_at' => now('UTC')->format('Y-m-d H:i:s'),
            ]);

            DB::commit();

            return [
                'ok' => true,
                'message' => 'Дата успешно исправлена.',
                'fixed_operation_ids' => [
                    'tenant_switch' => (int) $tenantSwitch->id,
                    'space_review' => (int) $spaceReview->id,
                ],
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            return [
                'ok' => false,
                'message' => 'Произошла ошибка при исправлении даты: ' . $e->getMessage(),
            ];
        }
    }
}

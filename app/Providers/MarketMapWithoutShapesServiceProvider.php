<?php
# app/Providers/MarketMapWithoutShapesServiceProvider.php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Support\MarketSpaces\MarketSpaceShapePolicy;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class MarketMapWithoutShapesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerWithoutShapesEndpoint();
        $this->injectWithoutShapesReviewFix();
    }

    private function registerWithoutShapesEndpoint(): void
    {
        Route::middleware(['web', 'panel:admin', FilamentAuthenticate::class])
            ->get('/admin/market-map/without-shapes-all', function (Request $request) {
                $this->ensureCanUseMapReview();
                $marketId = $this->resolveCurrentMarketId();

                if ($marketId <= 0 || ! Schema::hasTable('market_spaces')) {
                    return response()->json([
                        'ok' => true,
                        'items' => [],
                        'meta' => [
                            'without_shapes' => true,
                            'count' => 0,
                            'total_count' => 0,
                        ],
                    ]);
                }

                $validated = $request->validate([
                    'q' => ['nullable', 'string', 'max:120'],
                    'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
                ]);

                $limit = (int) ($validated['limit'] ?? 50);
                $search = trim((string) ($validated['q'] ?? ''));

                $query = MarketSpace::query()
                    ->with(['tenant:id,name,short_name'])
                    ->where('market_id', $marketId);

                if (Schema::hasColumn('market_spaces', 'is_active')) {
                    $query->where('is_active', true);
                }

                MarketSpaceShapePolicy::scopeRequiresOwnMapShape($query);

                if (Schema::hasTable('market_space_map_shapes')) {
                    $query->whereDoesntHave('mapShapes', function ($shapeQuery): void {
                        $this->scopeUsableShape($shapeQuery);
                    });
                }

                if ($search !== '') {
                    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';

                    $query->where(function ($spaceQuery) use ($like): void {
                        $spaceQuery
                            ->where('number', 'like', $like)
                            ->orWhere('code', 'like', $like)
                            ->orWhere('display_name', 'like', $like)
                            ->orWhereHas('tenant', function ($tenantQuery) use ($like): void {
                                $tenantQuery
                                    ->where('name', 'like', $like)
                                    ->orWhere('short_name', 'like', $like);
                            });
                    });
                }

                $rows = $query
                    ->orderBy('number')
                    ->orderBy('id')
                    ->limit(5000)
                    ->get(['id', 'market_id', 'number', 'code', 'display_name', 'area_sqm', 'tenant_id', 'space_group_role', 'space_group_parent_id']);

                $rows = $this->filterSharedUseParticipantSpacesWithVisibleBase($rows, $marketId)->values();
                $totalCount = $rows->count();

                $items = $rows
                    ->take($limit)
                    ->map(static function (MarketSpace $space): array {
                        $tenant = $space->tenant;
                        $tenantName = '';

                        if ($tenant) {
                            $tenantName = trim((string) ($tenant->display_name ?? ''));
                            if ($tenantName === '') {
                                $tenantName = trim((string) ($tenant->short_name ?? ''));
                            }
                            if ($tenantName === '') {
                                $tenantName = trim((string) ($tenant->name ?? ''));
                            }
                        }

                        return [
                            'id' => (int) $space->id,
                            'number' => (string) ($space->number ?? ''),
                            'code' => (string) ($space->code ?? ''),
                            'display_name' => (string) ($space->display_name ?? ''),
                            'area_sqm' => $space->area_sqm !== null ? (string) $space->area_sqm : null,
                            'space_group_role' => (string) ($space->space_group_role ?? ''),
                            'space_group_parent_id' => $space->space_group_parent_id ? (int) $space->space_group_parent_id : null,
                            'tenant' => $tenant ? [
                                'id' => (int) $tenant->id,
                                'name' => $tenantName,
                            ] : null,
                            'without_shapes' => true,
                        ];
                    })
                    ->values();

                return response()->json([
                    'ok' => true,
                    'items' => $items,
                    'meta' => [
                        'without_shapes' => true,
                        'count' => $items->count(),
                        'total_count' => $totalCount,
                    ],
                ]);
            })
            ->name('filament.admin.market-map.without-shapes-all');
    }

    private function injectWithoutShapesReviewFix(): void
    {
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            if (! $event->request->is('admin/market-map')) {
                return;
            }

            $response = $event->response;

            if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) {
                return;
            }

            $content = (string) $response->getContent();

            if ($content === '' || ! str_contains($content, 'id="noShapesEntry"') || str_contains($content, 'data-without-shapes-fix="1"')) {
                return;
            }

            $script = $this->withoutShapesFixScript();
            $needle = '</body>';

            if (str_contains($content, $needle)) {
                $content = str_replace($needle, $script . PHP_EOL . $needle, $content);
            } else {
                $content .= PHP_EOL . $script;
            }

            $response->setContent($content);
        });
    }

    private function ensureCanUseMapReview(): void
    {
        $user = Filament::auth()->user();
        abort_unless($user, 403);

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isMarketAdmin = method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['market-admin']);
        $canByPermission = method_exists($user, 'can') && $user->can('markets.update');

        abort_unless($isSuperAdmin || $isMarketAdmin || $canByPermission, 403);
    }

    private function resolveCurrentMarketId(): int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return 0;
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $selectedMarketId = session('dashboard_market_id')
            ?? session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id');

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if ($isSuperAdmin) {
            if (filled($selectedMarketId)) {
                return (int) $selectedMarketId;
            }

            return (int) (Market::query()->orderBy('id')->value('id') ?? 0);
        }

        return (int) ($user->market_id ?? 0);
    }

    private function scopeUsableShape($query): void
    {
        $query->where('is_active', true)
            ->where(static function ($subQuery): void {
                $subQuery->where(static function ($bboxQuery): void {
                    $bboxQuery
                        ->whereNotNull('bbox_x1')
                        ->whereNotNull('bbox_y1')
                        ->whereNotNull('bbox_x2')
                        ->whereNotNull('bbox_y2')
                        ->whereColumn('bbox_x1', '<', 'bbox_x2')
                        ->whereColumn('bbox_y1', '<', 'bbox_y2');
                })->orWhereJsonLength('polygon', '>=', 3);
            });
    }

    /**
     * @param Collection<int, MarketSpace> $rows
     * @return Collection<int, MarketSpace>
     */
    private function filterSharedUseParticipantSpacesWithVisibleBase(Collection $rows, int $marketId): Collection
    {
        $participantBaseNumbers = [];

        foreach ($rows as $space) {
            $number = (string) ($space->number ?? '');
            $baseNumber = preg_replace('/__t[0-9]+$/', '', $number);

            if ($baseNumber === $number) {
                $baseNumber = preg_replace('/_t[0-9]+$/', '', $number);
            }

            if (is_string($baseNumber) && $baseNumber !== $number && $baseNumber !== '') {
                $participantBaseNumbers[$baseNumber] = true;
            }
        }

        if ($participantBaseNumbers === [] || ! Schema::hasTable('market_space_map_shapes')) {
            return $rows;
        }

        $baseNumbersWithShapes = MarketSpace::query()
            ->where('market_id', $marketId)
            ->whereIn('number', array_keys($participantBaseNumbers))
            ->whereHas('mapShapes', function ($shapeQuery): void {
                $this->scopeUsableShape($shapeQuery);
            })
            ->pluck('number')
            ->mapWithKeys(static fn ($number): array => [(string) $number => true]);

        return $rows->filter(static function (MarketSpace $space) use ($baseNumbersWithShapes): bool {
            $number = (string) ($space->number ?? '');
            $baseNumber = preg_replace('/__t[0-9]+$/', '', $number);

            if ($baseNumber === $number) {
                $baseNumber = preg_replace('/_t[0-9]+$/', '', $number);
            }

            if (is_string($baseNumber) && $baseNumber !== $number) {
                return ! $baseNumbersWithShapes->has($baseNumber);
            }

            return true;
        });
    }

    private function withoutShapesFixScript(): string
    {
        return <<<'HTML'
<script data-without-shapes-fix="1">
(function () {
  const endpoint = '/admin/market-map/without-shapes-all';
  let totalCount = 0;
  let searchTimer = null;

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function isReviewMode() {
    return document.getElementById('scenarioReview')?.classList.contains('is-active') === true;
  }

  function syncButton() {
    const group = document.getElementById('reviewNoShapesGroup');
    const button = document.getElementById('noShapesEntry');
    const count = document.getElementById('noShapesCount');
    const visible = isReviewMode() && totalCount > 0;

    if (count) {
      count.textContent = String(totalCount);
    }

    if (group) {
      if (visible) {
        group.style.setProperty('display', 'flex', 'important');
      } else {
        group.style.setProperty('display', 'none', 'important');
      }
      group.hidden = !visible;
    }

    if (button) {
      button.style.display = visible ? 'inline-flex' : 'none';
      button.hidden = !visible;
      button.disabled = !visible;
      button.title = totalCount > 0 ? ('Показать ' + String(totalCount) + ' мест без фигур') : 'Нет мест без фигур';
      button.setAttribute('aria-label', button.title);
    }
  }

  function actionStyle(variant) {
    const base = 'font-size: 12px; line-height: 1.2; min-height: 32px; padding: 6px 10px; border-radius: 999px; cursor: pointer; font-weight: 700; box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; white-space: nowrap; width: auto; align-self: flex-start;';
    if (variant === 'primary') return base + ' color: #065f46; background: #ecfdf5; border: 1px solid #a7f3d0;';
    if (variant === 'neutral-link') return base + ' color: #0369a1; background: #ffffff; border: 1px solid #cbd5e1;';
    if (variant === 'danger') return base + ' color: #b91c1c; background: #fff1f2; border: 1px solid #fecdd3;';
    if (variant === 'info') return base + ' color: #1d4ed8; background: #eff6ff; border: 1px solid #bfdbfe;';
    if (variant === 'warning') return base + ' color: #c2410c; background: #fff7ed; border: 1px solid #fed7aa;';
    return base + ' color: #334155; background: #ffffff; border: 1px solid #cbd5e1;';
  }

  async function fetchWithoutShapes(query) {
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('limit', '200');
    if (String(query || '').trim() !== '') {
      url.searchParams.set('q', String(query).trim());
    }

    const response = await fetch(url.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache'
      }
    });

    const json = await response.json();
    if (!response.ok || !json || json.ok !== true) {
      throw new Error(json?.message || ('HTTP ' + String(response.status)));
    }

    return json;
  }

  function renderRows(items, query) {
    const content = document.getElementById('withoutShapesPanelContent');
    if (!content) return;

    if (!items.length) {
      content.innerHTML = '<div style="text-align: center; padding: 40px 20px; color: #64748b;">Нет мест без фигур' + (query ? ' по запросу &quot;' + escapeHtml(query) + '&quot;' : '') + '</div>';
      return;
    }

    content.innerHTML = items.map((item) => {
      const id = Number(item?.id || 0);
      const number = String(item?.number || item?.code || '—').trim();
      const tenantName = item?.tenant?.name ? String(item.tenant.name) : 'не указан';
      const tenantId = item?.tenant?.id ? Number(item.tenant.id) : 0;
      const actionsId = 'without-shapes-actions-' + String(id);

      let actions = '<div data-without-shapes-actions="' + String(id) + '" id="' + actionsId + '" style="display: none; flex-direction: column; gap: 8px; margin-top: 2px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; align-items: flex-start;" aria-hidden="true">';
      actions += '<button type="button" data-action="select-for-drawing" data-space-id="' + String(id) + '" data-space-number="' + escapeHtml(number) + '" data-space-tenant="' + escapeHtml(tenantName) + '" style="' + actionStyle('primary') + '">Выбрать для отрисовки</button>';
      actions += '<a href="/admin/market-spaces/' + String(id) + '/edit" target="_blank" rel="noopener" style="' + actionStyle('neutral-link') + '">Открыть место →</a>';
      if (tenantId && Number.isFinite(tenantId) && tenantId > 0) {
        actions += '<a href="/admin/tenants/' + String(tenantId) + '/edit" target="_blank" rel="noopener" style="' + actionStyle('neutral-link') + '">Открыть арендатора →</a>';
      }
      actions += '<div style="margin-top: 4px; padding-top: 8px; border-top: 1px solid #dbeafe; display: flex; flex-direction: column; gap: 6px;">';
      actions += '<div style="font-size: 11px; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: #1d4ed8;">Ревизия</div>';
      actions += '<div style="display: flex; flex-wrap: wrap; gap: 6px;">';
      actions += '<button type="button" data-action="review-decision" data-decision="occupancy_conflict" data-space-id="' + String(id) + '" title="Зафиксировать конфликт по месту" aria-label="Зафиксировать конфликт по месту" style="' + actionStyle('danger') + '">Конфликт</button>';
      actions += '<button type="button" data-action="review-decision" data-decision="space_identity_needs_clarification" data-space-id="' + String(id) + '" title="Зафиксировать, что место требует уточнения" aria-label="Зафиксировать, что место требует уточнения" style="' + actionStyle('info') + '">Требует уточнения</button>';
      actions += '<button type="button" data-action="review-decision" data-decision="tenant_changed_on_site" data-space-id="' + String(id) + '" title="Отметить, что на месте другой арендатор" aria-label="Отметить, что на месте другой арендатор" style="' + actionStyle('warning') + '">Сменился арендатор</button>';
      actions += '</div></div></div>';

      return '<div data-without-shapes-row-id="' + String(id) + '" style="display: flex; flex-direction: column; gap: 8px; padding: 14px 20px; border-bottom: 1px solid #e2e8f0;">'
        + '<div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">'
        + '<div style="min-width: 0; flex: 1;">'
        + '<div style="font-weight: 500; color: #1e293b; margin-bottom: 2px;">№' + escapeHtml(number) + '</div>'
        + '<div style="font-size: 12px; color: #94a3b8; margin-bottom: 2px;">ID ' + String(id) + '</div>'
        + '<div style="font-size: 13px; color: #64748b;">' + escapeHtml(tenantName) + '</div>'
        + '</div>'
        + '<button type="button" data-action="toggle-without-shape-actions" data-space-id="' + String(id) + '" aria-expanded="false" aria-controls="' + actionsId + '" style="font-size: 13px; color: #0f172a; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px; cursor: pointer; font-weight: 600; white-space: nowrap;">Действия ▾</button>'
        + '</div>' + actions + '</div>';
    }).join('');
  }

  async function loadPanel(query) {
    const content = document.getElementById('withoutShapesPanelContent');
    if (content) {
      content.innerHTML = '<div style="text-align: center; padding: 40px 20px; color: #64748b;">Загрузка...</div>';
    }

    const json = await fetchWithoutShapes(query);
    totalCount = Number(json?.meta?.total_count || 0);
    syncButton();
    renderRows(Array.isArray(json.items) ? json.items : [], String(query || '').trim());
  }

  function openPanel() {
    const panel = document.getElementById('withoutShapesPanel');
    const overlay = document.getElementById('withoutShapesPanelOverlay');
    const search = document.getElementById('withoutShapesSearch');
    if (!panel || !overlay) return;

    panel.hidden = false;
    panel.setAttribute('aria-hidden', 'false');
    overlay.hidden = false;
    overlay.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(() => {
      panel.style.transform = 'translateX(0)';
      overlay.style.opacity = '1';
      overlay.style.pointerEvents = 'auto';
    });

    if (search) {
      search.value = '';
    }

    loadPanel('').catch((error) => {
      const content = document.getElementById('withoutShapesPanelContent');
      if (content) {
        content.innerHTML = '<div style="text-align: center; padding: 40px 20px; color: #dc2626;">Ошибка: ' + escapeHtml(error?.message || String(error)) + '</div>';
      }
    });
  }

  function closePanel() {
    const panel = document.getElementById('withoutShapesPanel');
    const overlay = document.getElementById('withoutShapesPanelOverlay');
    if (!panel || !overlay) return;

    panel.style.transform = 'translateX(100%)';
    overlay.style.opacity = '0';
    overlay.style.pointerEvents = 'none';
    setTimeout(() => {
      panel.hidden = true;
      panel.setAttribute('aria-hidden', 'true');
      overlay.hidden = true;
      overlay.setAttribute('aria-hidden', 'true');
    }, 250);
  }

  function isPanelOpen() {
    const panel = document.getElementById('withoutShapesPanel');
    return !!panel && panel.hidden === false && panel.getAttribute('aria-hidden') === 'false';
  }

  document.addEventListener('click', function (event) {
    const target = event.target instanceof HTMLElement ? event.target.closest('#noShapesEntry') : null;
    if (!target) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    if (isPanelOpen()) {
      closePanel();
    } else {
      openPanel();
    }
  }, true);

  document.addEventListener('input', function (event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || target.id !== 'withoutShapesSearch') return;

    event.stopImmediatePropagation();
    if (searchTimer) window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
      loadPanel(target.value || '').catch((error) => {
        const content = document.getElementById('withoutShapesPanelContent');
        if (content) {
          content.innerHTML = '<div style="text-align: center; padding: 40px 20px; color: #dc2626;">Ошибка: ' + escapeHtml(error?.message || String(error)) + '</div>';
        }
      });
    }, 350);
  }, true);

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && isPanelOpen()) {
      closePanel();
    }
  });

  const observer = new MutationObserver(syncButton);
  for (const id of ['scenarioReview', 'reviewNoShapesGroup', 'noShapesEntry']) {
    const element = document.getElementById(id);
    if (element) {
      observer.observe(element, { attributes: true, attributeFilter: ['class', 'style', 'hidden', 'disabled'] });
    }
  }

  fetchWithoutShapes('')
    .then((json) => {
      totalCount = Number(json?.meta?.total_count || 0);
      syncButton();
    })
    .catch(() => {
      totalCount = 0;
      syncButton();
    });
})();
</script>
HTML;
    }
}

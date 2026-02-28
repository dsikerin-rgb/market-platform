<?php

declare(strict_types=1);

# app/Filament/Resources/IntegrationExchangeResource/Pages/ListIntegrationExchanges.php

namespace App\Filament\Resources\IntegrationExchangeResource\Pages;

use App\Filament\Resources\IntegrationExchangeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ListIntegrationExchanges extends ListRecords
{
    protected static string $resource = IntegrationExchangeResource::class;

    protected static ?string $title = 'Обмены интеграций';

    public function getBreadcrumb(): string
    {
        return 'Обмены интеграций';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Создать обмен'),

            Actions\Action::make('one_c_import_logs')
                ->label('Выгрузки 1С (лог)')
                ->icon('heroicon-o-document-text')
                ->modalHeading('Последние выгрузки 1С')
                ->modalSubmitActionLabel('Закрыть')
                ->modalCancelActionLabel('Отмена')
                // ВАЖНО: без ": void", иначе PHP 8.3 падает (void не может вернуть значение)
                ->action(fn () => null)
                ->modalContent(function (): HtmlString {
                    if (! Schema::hasTable('one_c_import_logs')) {
                        return new HtmlString(
                            '<div class="text-sm text-gray-600">Таблица <code>one_c_import_logs</code> ещё не создана миграциями.</div>'
                        );
                    }

                    $logs = DB::table('one_c_import_logs')
                        ->select([
                            'id',
                            'created_at',
                            'market_id',
                            'endpoint',
                            'status',
                            'http_status',
                            'calculated_at',
                            'received',
                            'inserted',
                            'skipped',
                            'duration_ms',
                            'error_message',
                        ])
                        ->orderByDesc('id')
                        ->limit(10)
                        ->get();

                    if ($logs->isEmpty()) {
                        return new HtmlString(
                            '<div class="text-sm text-gray-600">Записей пока нет. Запусти выгрузку 1С на STAGING и обнови.</div>'
                        );
                    }

                    $last = $logs->first();
                    $status = (string) ($last->status ?? 'unknown');

                    $badgeClass = $status === 'ok'
                        ? 'inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20'
                        : 'inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20';

                    $summary = sprintf(
                        '<div class="flex flex-wrap items-center gap-3 text-sm">
                            <span class="%s">%s</span>
                            <span class="text-gray-700"><b>created_at:</b> %s</span>
                            <span class="text-gray-700"><b>calculated_at:</b> %s</span>
                            <span class="text-gray-700"><b>received:</b> %s</span>
                            <span class="text-gray-700"><b>inserted:</b> %s</span>
                            <span class="text-gray-700"><b>skipped:</b> %s</span>
                            <span class="text-gray-700"><b>ms:</b> %s</span>
                        </div>',
                        e($badgeClass),
                        e($status),
                        e((string) ($last->created_at ?? '')),
                        e((string) ($last->calculated_at ?? '')),
                        e((string) ($last->received ?? '')),
                        e((string) ($last->inserted ?? '')),
                        e((string) ($last->skipped ?? '')),
                        e((string) ($last->duration_ms ?? ''))
                    );

                    $rowsHtml = '';

                    foreach ($logs as $log) {
                        $rowStatus = (string) ($log->status ?? 'unknown');

                        $rowBadgeClass = $rowStatus === 'ok'
                            ? 'inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20'
                            : 'inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20';

                        $err = Str::limit((string) ($log->error_message ?? ''), 120);

                        $rowsHtml .= '<tr class="divide-x divide-gray-100">
                            <td class="px-3 py-2 text-xs text-gray-700">' . e((string) $log->id) . '</td>
                            <td class="px-3 py-2 text-xs text-gray-700">' . e((string) ($log->created_at ?? '')) . '</td>
                            <td class="px-3 py-2 text-xs"><span class="' . e($rowBadgeClass) . '">' . e($rowStatus) . '</span></td>
                            <td class="px-3 py-2 text-xs text-gray-700">' . e((string) ($log->http_status ?? '')) . '</td>
                            <td class="px-3 py-2 text-xs text-gray-700">' . e((string) ($log->calculated_at ?? '')) . '</td>
                            <td class="px-3 py-2 text-xs text-gray-700">' . e((string) ($log->received ?? '')) . '</td>
                            <td class="px-3 py-2 text-xs text-gray-700">' . e((string) ($log->inserted ?? '')) . '</td>
                            <td class="px-3 py-2 text-xs text-gray-700">' . e((string) ($log->skipped ?? '')) . '</td>
                            <td class="px-3 py-2 text-xs text-gray-700">' . e((string) ($log->duration_ms ?? '')) . '</td>
                            <td class="px-3 py-2 text-xs text-gray-700">' . e($err) . '</td>
                        </tr>';
                    }

                    $table = '
                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-left">
                                <thead class="bg-gray-50">
                                    <tr class="divide-x divide-gray-100">
                                        <th class="px-3 py-2 text-xs font-semibold text-gray-700">ID</th>
                                        <th class="px-3 py-2 text-xs font-semibold text-gray-700">created_at</th>
                                        <th class="px-3 py-2 text-xs font-semibold text-gray-700">status</th>
                                        <th class="px-3 py-2 text-xs font-semibold text-gray-700">http</th>
                                        <th class="px-3 py-2 text-xs font-semibold text-gray-700">calculated_at</th>
                                        <th class="px-3 py-2 text-xs font-semibold text-gray-700">recv</th>
                                        <th class="px-3 py-2 text-xs font-semibold text-gray-700">ins</th>
                                        <th class="px-3 py-2 text-xs font-semibold text-gray-700">skip</th>
                                        <th class="px-3 py-2 text-xs font-semibold text-gray-700">ms</th>
                                        <th class="px-3 py-2 text-xs font-semibold text-gray-700">error</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    ' . $rowsHtml . '
                                </tbody>
                            </table>
                        </div>
                    ';

                    return new HtmlString('<div class="space-y-3">' . $summary . $table . '</div>');
                }),
        ];
    }
}
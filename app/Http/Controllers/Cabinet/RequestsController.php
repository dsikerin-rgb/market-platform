<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class RequestsController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const CATEGORY_LABELS = [
        'repair' => 'Ремонт',
        'cleaning' => 'Уборка',
        'payment' => 'Оплата',
        'help' => 'Помощь',
        'other' => 'Прочее',
    ];

    public function index(Request $request): View
    {
        $tenant = $request->user()->tenant;
        $allowedSpaceIds = $request->user()->allowedTenantSpaceIds();
        $ticketHasSpaceColumn = Schema::hasColumn('tickets', 'market_space_id');

        $tickets = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->when($ticketHasSpaceColumn && $allowedSpaceIds !== [], fn ($query) => $query->where(function ($q) use ($allowedSpaceIds): void {
                $q->whereNull('market_space_id')->orWhereIn('market_space_id', $allowedSpaceIds);
            }))
            ->with(['marketSpace:id,number,code,display_name'])
            ->orderByDesc('created_at')
            ->get();

        $request->session()->put('cabinet.communication_seen_at', now()->toDateTimeString());

        return view('cabinet.requests.index', [
            'tenant' => $tenant,
            'tickets' => $tickets,
            'categories' => self::CATEGORY_LABELS,
        ]);
    }

    public function create(Request $request): View
    {
        $tenant = $request->user()->tenant;
        $spaces = $this->resolveAllowedSpaces($request);

        return view('cabinet.requests.create', [
            'tenant' => $tenant,
            'categories' => self::CATEGORY_LABELS,
            'spaces' => $spaces,
            'defaultCategory' => array_key_exists((string) $request->query('category'), self::CATEGORY_LABELS)
                ? (string) $request->query('category')
                : 'other',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->user()->tenant;
        $ticketHasSpaceColumn = Schema::hasColumn('tickets', 'market_space_id');

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(self::CATEGORY_LABELS))],
            'market_space_id' => ['nullable', 'integer'],
            'description' => ['required', 'string'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'max:5120'],
        ]);

        $selectedSpaceId = isset($validated['market_space_id']) && is_numeric($validated['market_space_id'])
            ? (int) $validated['market_space_id']
            : null;

        if ($ticketHasSpaceColumn && $selectedSpaceId && ! in_array($selectedSpaceId, $request->user()->allowedTenantSpaceIds(), true)) {
            abort(403);
        }

        $payload = [
            'market_id' => $tenant->market_id,
            'tenant_id' => $tenant->id,
            'subject' => $validated['subject'],
            'description' => $validated['description'],
            'category' => $validated['category'],
            'priority' => 'normal',
            'status' => 'new',
            'assigned_to' => $this->resolveAssigneeUserId(
                (int) ($tenant->market_id ?? 0),
                (string) $validated['category'],
                (int) ($request->user()->id ?? 0),
            ),
        ];

        if ($ticketHasSpaceColumn) {
            $payload['market_space_id'] = $selectedSpaceId;
        }

        $ticket = Ticket::create($payload);

        $this->storeAttachments($request, $ticket);

        return redirect()
            ->route('cabinet.requests.show', $ticket->id)
            ->with('success', 'Обращение создано. Администрация скоро ответит.');
    }

    public function show(Request $request, int $ticketId): View
    {
        $tenant = $request->user()->tenant;
        $ticketHasSpaceColumn = Schema::hasColumn('tickets', 'market_space_id');

        $ticket = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->when($ticketHasSpaceColumn && $request->user()->allowedTenantSpaceIds() !== [], fn ($query) => $query->where(function ($q) use ($request): void {
                $spaceIds = $request->user()->allowedTenantSpaceIds();
                $q->whereNull('market_space_id')->orWhereIn('market_space_id', $spaceIds);
            }))
            ->whereKey($ticketId)
            ->with(['marketSpace:id,number,code,display_name'])
            ->firstOrFail();

        $comments = TicketComment::query()
            ->where('ticket_id', $ticket->id)
            ->orderBy('created_at')
            ->get();

        $attachments = TicketAttachment::query()
            ->where('ticket_id', $ticket->id)
            ->get();

        $request->session()->put('cabinet.communication_seen_at', now()->toDateTimeString());

        return view('cabinet.requests.show', [
            'tenant' => $tenant,
            'ticket' => $ticket,
            'comments' => $comments,
            'attachments' => $attachments,
            'categories' => self::CATEGORY_LABELS,
        ]);
    }

    public function comment(Request $request, int $ticketId): RedirectResponse
    {
        $tenant = $request->user()->tenant;

        $ticket = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->whereKey($ticketId)
            ->firstOrFail();

        $validated = $request->validate([
            'body' => ['required', 'string'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'max:5120'],
        ]);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        $this->storeAttachments($request, $ticket);

        return redirect()
            ->route('cabinet.requests.show', $ticket->id)
            ->with('success', 'Сообщение отправлено.');
    }

    private function storeAttachments(Request $request, Ticket $ticket): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }

        foreach ($request->file('attachments', []) as $file) {
            if (! $file) {
                continue;
            }

            $path = $file->store('ticket-attachments', 'public');

            TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
            ]);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, MarketSpace>
     */
    private function resolveAllowedSpaces(Request $request)
    {
        $tenant = $request->user()->tenant;
        $spaceIds = $request->user()->allowedTenantSpaceIds();

        return MarketSpace::query()
            ->where('tenant_id', (int) $tenant->id)
            ->when((int) ($tenant->market_id ?? 0) > 0, fn ($query) => $query->where('market_id', (int) $tenant->market_id))
            ->when($spaceIds !== [], fn ($query) => $query->whereIn('id', $spaceIds))
            ->orderByRaw('COALESCE(code, number, display_name) asc')
            ->get(['id', 'number', 'code', 'display_name']);
    }

    private function resolveAssigneeUserId(int $marketId, string $category, int $senderId): ?int
    {
        if ($marketId <= 0) {
            return null;
        }

        $market = Market::query()->select(['id', 'settings'])->find($marketId);
        $settings = (array) ($market?->settings ?? []);

        $priorityKeys = match ($category) {
            'help' => ['request_help_notification_recipient_user_ids', 'request_notification_recipient_user_ids'],
            'repair' => ['request_repair_notification_recipient_user_ids', 'request_notification_recipient_user_ids'],
            default => ['request_notification_recipient_user_ids'],
        };

        foreach ($priorityKeys as $key) {
            $recipientIds = array_values(array_filter(
                (array) ($settings[$key] ?? []),
                static fn ($value): bool => is_numeric($value),
            ));

            if ($recipientIds === []) {
                continue;
            }

            $assigneeId = (int) (User::query()
                ->where('market_id', $marketId)
                ->whereNull('tenant_id')
                ->whereIn('id', $recipientIds)
                ->where('id', '!=', $senderId)
                ->orderByRaw('array_position(ARRAY[' . implode(',', array_map('intval', $recipientIds)) . ']::int[], id)')
                ->value('id') ?? 0);

            if ($assigneeId > 0) {
                return $assigneeId;
            }
        }

        return (int) (User::query()
            ->where('market_id', $marketId)
            ->whereNull('tenant_id')
            ->where('id', '!=', $senderId)
            ->whereHas('roles', fn ($query) => $query->where('name', 'market-admin'))
            ->orderBy('id')
            ->value('id') ?? 0) ?: null;
    }
}

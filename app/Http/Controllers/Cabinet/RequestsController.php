<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        'other' => 'Прочее',
    ];

    public function index(Request $request): View
    {
        $tenant = $request->user()->tenant;

        $tickets = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->orderByDesc('created_at')
            ->get();

        return view('cabinet.requests.index', [
            'tenant' => $tenant,
            'tickets' => $tickets,
            'categories' => self::CATEGORY_LABELS,
        ]);
    }

    public function create(Request $request): View
    {
        $tenant = $request->user()->tenant;

        return view('cabinet.requests.create', [
            'tenant' => $tenant,
            'categories' => self::CATEGORY_LABELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->user()->tenant;

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(self::CATEGORY_LABELS))],
            'description' => ['required', 'string'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'max:5120'],
        ]);

        $ticket = Ticket::create([
            'market_id' => $tenant->market_id,
            'tenant_id' => $tenant->id,
            'subject' => $validated['subject'],
            'description' => $validated['description'],
            'category' => $validated['category'],
            'priority' => 'normal',
            'status' => 'new',
        ]);

        $this->storeAttachments($request, $ticket);

        return redirect()
            ->route('cabinet.requests.show', $ticket->id)
            ->with('success', 'Заявка создана. Администрация скоро ответит.');
    }

    public function show(Request $request, int $ticketId): View
    {
        $tenant = $request->user()->tenant;

        $ticket = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->when($tenant->market_id, fn ($query) => $query->where('market_id', $tenant->market_id))
            ->whereKey($ticketId)
            ->firstOrFail();

        $comments = TicketComment::query()
            ->where('ticket_id', $ticket->id)
            ->orderBy('created_at')
            ->get();

        $attachments = TicketAttachment::query()
            ->where('ticket_id', $ticket->id)
            ->get();

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
}

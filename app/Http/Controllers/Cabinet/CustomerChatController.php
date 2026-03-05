<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceChat;
use App\Models\MarketplaceChatMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CustomerChatController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $tenant = $user?->tenant;
        abort_unless($tenant, 403);

        $allowedSpaceIds = method_exists($user, 'allowedTenantSpaceIds') ? $user->allowedTenantSpaceIds() : [];

        $chats = MarketplaceChat::query()
            ->where('tenant_id', (int) $tenant->id)
            ->when((int) ($tenant->market_id ?? 0) > 0, fn ($q) => $q->where('market_id', (int) $tenant->market_id))
            ->when($allowedSpaceIds !== [], fn ($q) => $q->where(function ($inner) use ($allowedSpaceIds): void {
                $inner->whereNull('market_space_id')->orWhereIn('market_space_id', $allowedSpaceIds);
            }))
            ->with([
                'buyer:id,name,email',
                'product:id,title,slug',
                'marketSpace:id,display_name,number,code',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $activeChat = null;
        $activeMessages = collect();
        $activeChatId = (int) $request->integer('chat', 0);

        if ($activeChatId > 0) {
            $activeChat = $chats->firstWhere('id', $activeChatId);
        }
        if (! $activeChat && $chats->isNotEmpty()) {
            $activeChat = $chats->first();
        }

        if ($activeChat) {
            $activeMessages = MarketplaceChatMessage::query()
                ->where('chat_id', (int) $activeChat->id)
                ->orderBy('id')
                ->get();

            MarketplaceChat::query()->whereKey((int) $activeChat->id)->update([
                'tenant_unread_count' => 0,
            ]);

            MarketplaceChatMessage::query()
                ->where('chat_id', (int) $activeChat->id)
                ->where('sender_type', 'buyer')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return view('cabinet.customer-chat', [
            'tenant' => $tenant,
            'chats' => $chats,
            'activeChat' => $activeChat,
            'activeMessages' => $activeMessages,
        ]);
    }

    public function send(Request $request, int $chatId): RedirectResponse
    {
        $user = $request->user();
        $tenant = $user?->tenant;
        abort_unless($tenant, 403);

        $allowedSpaceIds = method_exists($user, 'allowedTenantSpaceIds') ? $user->allowedTenantSpaceIds() : [];

        $chat = MarketplaceChat::query()
            ->where('id', $chatId)
            ->where('tenant_id', (int) $tenant->id)
            ->when((int) ($tenant->market_id ?? 0) > 0, fn ($q) => $q->where('market_id', (int) $tenant->market_id))
            ->when($allowedSpaceIds !== [], fn ($q) => $q->where(function ($inner) use ($allowedSpaceIds): void {
                $inner->whereNull('market_space_id')->orWhereIn('market_space_id', $allowedSpaceIds);
            }))
            ->firstOrFail();

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:3000'],
        ]);

        $body = trim((string) ($validated['message'] ?? ''));

        DB::transaction(function () use ($chat, $user, $body): void {
            MarketplaceChatMessage::query()->create([
                'chat_id' => (int) $chat->id,
                'sender_user_id' => (int) $user->id,
                'sender_type' => 'tenant',
                'body' => $body,
            ]);

            $chat->forceFill([
                'last_message_at' => now(),
                'buyer_unread_count' => (int) $chat->buyer_unread_count + 1,
            ])->save();
        });

        return redirect()
            ->route('cabinet.customer-chat', ['chat' => (int) $chat->id])
            ->with('success', 'Сообщение отправлено.');
    }
}


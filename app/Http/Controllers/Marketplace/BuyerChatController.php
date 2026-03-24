<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\MarketplaceChat;
use App\Models\MarketplaceChatMessage;
use App\Models\MarketplaceProduct;
use App\Models\Tenant;
use App\Services\Auth\PortalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BuyerChatController extends BaseMarketplaceController
{
    public function index(Request $request, string $marketSlug): View
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $buyer = $request->user();
        abort_unless($buyer, 403);

        $chats = MarketplaceChat::query()
            ->where('market_id', (int) $market->id)
            ->where('buyer_user_id', (int) $buyer->id)
            ->with([
                'tenant:id,name,short_name,slug',
                'product:id,title,slug',
                'messages' => fn ($q) => $q->latest('id')->limit(1),
            ])
            ->orderByDesc('last_message_at')
            ->paginate(20)
            ->withQueryString();

        return view('marketplace.buyer.chats.index', array_merge(
            $this->sharedViewData($request, $market),
            [
                'chats' => $chats,
            ],
        ));
    }

    public function show(Request $request, string $marketSlug, int $chatId): View
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $buyer = $request->user();
        abort_unless($buyer, 403);

        $chat = MarketplaceChat::query()
            ->where('id', $chatId)
            ->where('market_id', (int) $market->id)
            ->where('buyer_user_id', (int) $buyer->id)
            ->with(['tenant:id,name,short_name,slug', 'product:id,title,slug', 'marketSpace:id,display_name,number,code'])
            ->firstOrFail();

        $messages = MarketplaceChatMessage::query()
            ->where('chat_id', (int) $chat->id)
            ->orderBy('id')
            ->paginate(80)
            ->withQueryString();

        MarketplaceChat::query()->whereKey((int) $chat->id)->update([
            'buyer_unread_count' => 0,
        ]);

        MarketplaceChatMessage::query()
            ->where('chat_id', (int) $chat->id)
            ->where('sender_type', 'tenant')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return view('marketplace.buyer.chats.show', array_merge(
            $this->sharedViewData($request, $market),
            [
                'chat' => $chat,
                'messages' => $messages,
            ],
        ));
    }

    public function start(Request $request, string $marketSlug, string $tenantSlug): RedirectResponse
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $buyer = $request->user();
        abort_unless($buyer, 403);
        $allowWithoutActiveContracts = app(PortalAccessService::class)->allowsPublicSalesWithoutActiveContract($market);
        $showDemoContent = $this->marketplaceDemoContentEnabled($market);

        $tenantQuery = Tenant::query()
            ->where('market_id', (int) $market->id)
            ->where(function ($query) use ($tenantSlug): void {
                $query->where('slug', $tenantSlug);
                if (is_numeric($tenantSlug)) {
                    $query->orWhereKey((int) $tenantSlug);
                }
            });

        if (! $allowWithoutActiveContracts) {
            $tenantQuery->whereHas('contracts', function ($contracts) use ($market): void {
                $contracts
                    ->where('market_id', (int) $market->id)
                    ->where('is_active', true);
            });
        }

        $tenant = $tenantQuery->firstOrFail();

        $validated = $request->validate([
            'product_slug' => ['nullable', 'string', 'max:220'],
            'message' => ['required', 'string', 'max:3000'],
            'space_id' => ['nullable', 'integer'],
        ]);

        $product = null;
        $productSlug = trim((string) ($validated['product_slug'] ?? ''));
        if ($productSlug !== '') {
            $product = MarketplaceProduct::query()
                ->publiclyVisibleInMarket((int) $market->id, $allowWithoutActiveContracts, $showDemoContent)
                ->where('tenant_id', (int) $tenant->id)
                ->where('slug', $productSlug)
                ->first();
        }

        $spaceId = (int) ($validated['space_id'] ?? 0);
        if ($spaceId <= 0) {
            $spaceId = (int) ($product?->market_space_id ?? 0);
        }

        $subject = $product
            ? ('Вопрос по товару: ' . trim((string) $product->title))
            : ('Сообщение магазину ' . trim((string) ($tenant->display_name ?? $tenant->name ?? '')));

        $body = trim((string) $validated['message']);

        $chat = DB::transaction(function () use ($market, $tenant, $buyer, $product, $spaceId, $subject, $body): MarketplaceChat {
            $chat = MarketplaceChat::query()
                ->where('market_id', (int) $market->id)
                ->where('tenant_id', (int) $tenant->id)
                ->where('buyer_user_id', (int) $buyer->id)
                ->where('product_id', $product ? (int) $product->id : null)
                ->where('market_space_id', $spaceId > 0 ? $spaceId : null)
                ->where('status', 'open')
                ->first();

            if (! $chat) {
                $chat = MarketplaceChat::query()->create([
                    'market_id' => (int) $market->id,
                    'tenant_id' => (int) $tenant->id,
                    'buyer_user_id' => (int) $buyer->id,
                    'product_id' => $product ? (int) $product->id : null,
                    'market_space_id' => $spaceId > 0 ? $spaceId : null,
                    'subject' => $subject,
                    'status' => 'open',
                    'last_message_at' => now(),
                    'buyer_unread_count' => 0,
                    'tenant_unread_count' => 0,
                ]);
            }

            MarketplaceChatMessage::query()->create([
                'chat_id' => (int) $chat->id,
                'sender_user_id' => (int) $buyer->id,
                'sender_type' => 'buyer',
                'body' => $body,
            ]);

            $chat->forceFill([
                'last_message_at' => now(),
                'tenant_unread_count' => (int) $chat->tenant_unread_count + 1,
            ])->save();

            return $chat;
        });

        return redirect()->route('marketplace.buyer.chat.show', [
            'marketSlug' => $market->slug,
            'chatId' => (int) $chat->id,
        ])->with('success', 'Сообщение отправлено.');
    }

    public function send(Request $request, string $marketSlug, int $chatId): RedirectResponse
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $buyer = $request->user();
        abort_unless($buyer, 403);

        $chat = MarketplaceChat::query()
            ->where('id', $chatId)
            ->where('market_id', (int) $market->id)
            ->where('buyer_user_id', (int) $buyer->id)
            ->firstOrFail();

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:3000'],
        ]);

        $body = trim((string) $validated['message']);

        DB::transaction(function () use ($chat, $buyer, $body): void {
            MarketplaceChatMessage::query()->create([
                'chat_id' => (int) $chat->id,
                'sender_user_id' => (int) $buyer->id,
                'sender_type' => 'buyer',
                'body' => $body,
            ]);

            $chat->forceFill([
                'last_message_at' => now(),
                'tenant_unread_count' => (int) $chat->tenant_unread_count + 1,
            ])->save();
        });

        return back()->with('success', 'Сообщение отправлено.');
    }
}

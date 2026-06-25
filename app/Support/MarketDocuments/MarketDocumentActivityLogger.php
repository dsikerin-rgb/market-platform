<?php

declare(strict_types=1);

namespace App\Support\MarketDocuments;

use App\Models\MarketDocument;
use App\Models\MarketDocumentActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MarketDocumentActivityLogger
{
    /**
     * @param array<string, mixed> $payload
     */
    public function log(
        MarketDocument $document,
        string $action,
        ?User $actor = null,
        ?User $targetUser = null,
        array $payload = [],
    ): ?MarketDocumentActivityEvent {
        if (! Schema::hasTable('market_document_activity_events')) {
            return null;
        }

        $actor ??= Auth::user();

        try {
            return MarketDocumentActivityEvent::query()->create([
                'market_id' => $document->market_id ? (int) $document->market_id : null,
                'market_document_id' => $document->id ? (int) $document->id : null,
                'actor_user_id' => $actor?->id ? (int) $actor->id : null,
                'target_user_id' => $targetUser?->id ? (int) $targetUser->id : null,
                'folder_id' => $document->folder_id ? (int) $document->folder_id : null,
                'action' => $action,
                'visibility' => filled($document->visibility) ? (string) $document->visibility : null,
                'document_name' => $document->resolvedFileName(),
                'file_path' => filled($document->file_path) ? (string) $document->file_path : null,
                'ip_address' => request()?->ip(),
                'user_agent' => str(request()?->userAgent() ?? '')->limit(255, '')->toString(),
                'payload' => $this->payload($document, $payload),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Failed to write market document activity event.', [
                'document_id' => $document->id,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function payload(MarketDocument $document, array $payload): array
    {
        return array_filter([
            'file_size' => $document->file_size ? (int) $document->file_size : null,
            'mime_type' => filled($document->mime_type) ? (string) $document->mime_type : null,
            ...$payload,
        ], static fn (mixed $value): bool => $value !== null);
    }
}

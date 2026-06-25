<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketDocument;
use App\Models\MarketDocumentActivityEvent;
use App\Models\User;
use App\Support\MarketDocuments\MarketDocumentActivityLogger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketDocumentActivityLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('market_document_activity_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('market_id')->nullable();
            $table->unsignedBigInteger('market_document_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->unsignedBigInteger('folder_id')->nullable();
            $table->string('action', 64);
            $table->string('visibility', 32)->nullable();
            $table->string('document_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function test_logger_writes_document_action_snapshot(): void
    {
        $actor = (new User())->forceFill([
            'id' => 7,
            'market_id' => 3,
            'name' => 'Марина',
            'email' => 'marina@example.test',
        ]);
        $actor->exists = true;

        $document = (new MarketDocument())->forceFill([
            'id' => 42,
            'market_id' => 3,
            'folder_id' => 5,
            'visibility' => MarketDocument::VISIBILITY_SHARED,
            'title' => 'Счёт.pdf',
            'original_name' => 'Счёт.pdf',
            'file_path' => 'market-documents/market-3/shared/invoice.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
        ]);
        $document->exists = true;

        $event = app(MarketDocumentActivityLogger::class)->log(
            $document,
            MarketDocumentActivityEvent::ACTION_TRASHED,
            $actor,
            null,
            ['reason' => 'manual'],
        );

        self::assertInstanceOf(MarketDocumentActivityEvent::class, $event);
        self::assertSame(3, (int) $event->market_id);
        self::assertSame(42, (int) $event->market_document_id);
        self::assertSame(7, (int) $event->actor_user_id);
        self::assertSame(5, (int) $event->folder_id);
        self::assertSame(MarketDocumentActivityEvent::ACTION_TRASHED, $event->action);
        self::assertSame('Счёт.pdf', $event->document_name);
        self::assertSame('market-documents/market-3/shared/invoice.pdf', $event->file_path);
        self::assertSame('manual', $event->payload['reason'] ?? null);
        self::assertSame(1200, $event->payload['file_size'] ?? null);
    }
}

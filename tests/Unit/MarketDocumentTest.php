<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MarketDocument;
use Tests\TestCase;

class MarketDocumentTest extends TestCase
{
    public function test_storage_directory_separates_shared_and_personal_documents(): void
    {
        config(['market_documents.directory' => 'docs']);

        $this->assertSame(
            'docs/market-7/shared',
            MarketDocument::storageDirectory(7, 15, MarketDocument::VISIBILITY_SHARED),
        );

        $this->assertSame(
            'docs/market-7/personal/user-15',
            MarketDocument::storageDirectory(7, 15, MarketDocument::VISIBILITY_PERSONAL),
        );
    }

    public function test_document_labels_are_human_readable(): void
    {
        $document = new MarketDocument([
            'visibility' => MarketDocument::VISIBILITY_SHARED,
            'category' => MarketDocument::CATEGORY_REGULATIONS,
            'file_size' => 2 * 1024 * 1024,
        ]);

        $this->assertSame('Общие документы', $document->visibilityLabel());
        $this->assertSame('Регламенты', $document->categoryLabel());
        $this->assertSame('2,0 МБ', $document->fileSizeLabel());
    }

    public function test_display_file_name_preserves_original_extension(): void
    {
        $document = new MarketDocument([
            'title' => 'Счёт за июнь',
            'original_name' => 'invoice.pdf',
        ]);

        $this->assertSame('Счёт за июнь.pdf', $document->displayFileName());

        $document->title = 'Счёт за июнь.pdf';

        $this->assertSame('Счёт за июнь.pdf', $document->displayFileName());
    }
}

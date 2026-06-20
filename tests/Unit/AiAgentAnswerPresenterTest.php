<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiAgentAnswerPresenter;
use PHPUnit\Framework\TestCase;

class AiAgentAnswerPresenterTest extends TestCase
{
    public function test_moves_internal_tenant_url_to_chip_and_removes_identifier(): void
    {
        $presented = (new AiAgentAnswerPresenter(['market.176.108.244.218.nip.io']))->present(
            "Самый большой долг у арендатора «ТД ЭЛЕКТРОТЕХМОНТАЖ АО».\n\nОткройте карточку арендатора с идентификатором `52`.\n\nhttps://market.176.108.244.218.nip.io/admin/tenants/view/52",
        );

        self::assertStringNotContainsString('https://', $presented['answer']);
        self::assertStringNotContainsString('идентификатор', mb_strtolower($presented['answer']));
        self::assertSame('Открыть арендатора', $presented['chips'][0]['label'] ?? null);
        self::assertSame('https://market.176.108.244.218.nip.io/admin/tenants/52/edit', $presented['chips'][0]['url'] ?? null);
    }

    public function test_normalizes_existing_tenant_view_chip_to_edit_url(): void
    {
        $presented = (new AiAgentAnswerPresenter)->present('Done', [
            ['label' => 'Tenant', 'url' => '/admin/tenants/view/118'],
            ['label' => 'Tenant', 'url' => '/admin/tenants/118/edit'],
        ]);

        self::assertCount(1, $presented['chips']);
        self::assertSame('/admin/tenants/118/edit', $presented['chips'][0]['url']);
    }

    public function test_preserves_existing_chips_and_deduplicates_extracted_links(): void
    {
        $presented = (new AiAgentAnswerPresenter)->present(
            'Готово: [Открыть задачу](/admin/tasks/9/edit) /admin/tasks/9/edit',
            [
                ['label' => 'Открыть задачу', 'url' => '/admin/tasks/9/edit'],
            ],
        );

        self::assertSame('Готово:', $presented['answer']);
        self::assertCount(1, $presented['chips']);
        self::assertSame('Открыть задачу', $presented['chips'][0]['label']);
        self::assertSame('/admin/tasks/9/edit', $presented['chips'][0]['url']);
    }

    public function test_keeps_off_site_admin_urls_out_of_chips(): void
    {
        $presented = (new AiAgentAnswerPresenter(['market.176.108.244.218.nip.io']))->present(
            'Открой [задачу](https://evil.example/admin/tasks/9/edit)',
        );

        self::assertStringContainsString('https://evil.example/admin/tasks/9/edit', $presented['answer']);
        self::assertSame([], $presented['chips']);
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Service\Skills\Content\CmsData\CreateBlockAction;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\BuildsStoreLayouts;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CreateBlockActionTest extends TestCase
{
    use BuildsStoreLayouts;

    private const ADMIN_USER_ID = 7;

    #[Test]
    public function itCreatesTheBlockForAllStoreViewsByDefault(): void
    {
        $apiClient = (new FakeInternalApiClient())->withResponse(FakeInternalApiClient::POST, 'cmsBlock', ['id' => 3]);

        $result = $this->actionWith($apiClient)->execute($this->params(), self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        self::assertSame('all', $apiClient->calls()[0]['store_code']);
        self::assertSame(self::ADMIN_USER_ID, $apiClient->calls()[0]['admin_user_id']);
        self::assertSame('Block "footer-links" created for all store views', $result['message']);
        self::assertSame(
            [['label' => 'Edit Footer links', 'url' => 'https://admin.example/cms/block/edit/block_id/3/']],
            $result['_links']
        );
    }

    #[Test]
    public function itCreatesTheBlockInTheRequestedStoreView(): void
    {
        $apiClient = (new FakeInternalApiClient())->withResponse(FakeInternalApiClient::POST, 'cmsBlock', ['id' => 3]);

        $result = $this->actionWith($apiClient)->execute($this->params(['store_id' => 2]), self::ADMIN_USER_ID);

        self::assertSame('luma', $apiClient->calls()[0]['store_code']);
        self::assertSame('store view "Luma" (id 2, code "luma")', $result['store_label']);
    }

    #[Test]
    public function itRejectsAnUnknownStoreViewWithoutCallingTheApi(): void
    {
        $apiClient = new FakeInternalApiClient();

        $result = $this->actionWith($apiClient)->execute($this->params(['store_id' => 42]), self::ADMIN_USER_ID);

        self::assertStringStartsWith('Unknown store view id 42', $result['error']);
        self::assertSame([], $apiClient->calls());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function params(array $overrides = []): array
    {
        return $overrides + [
            'identifier' => 'footer-links',
            'title' => 'Footer links',
            'content' => '<ul></ul>',
        ];
    }

    private function actionWith(FakeInternalApiClient $apiClient): CreateBlockAction
    {
        $secureAdminUrl = $this->createMock(SecureAdminUrl::class);
        $secureAdminUrl->method('getUrl')
            ->with('cms/block/edit', ['block_id' => 3])
            ->willReturn('https://admin.example/cms/block/edit/block_id/3/');

        return new CreateBlockAction($apiClient, new StoreScopeContext($this->multiStoreManager()), $secureAdminUrl);
    }
}

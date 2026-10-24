<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Service\Skills\Content\CmsData\CreatePageAction;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\BuildsStoreLayouts;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CreatePageActionTest extends TestCase
{
    use BuildsStoreLayouts;

    private const ADMIN_USER_ID = 7;

    #[Test]
    public function itCreatesThePageForAllStoreViewsByDefault(): void
    {
        $apiClient = $this->apiClient();

        $result = $this->actionWith($apiClient)->execute($this->params(), self::ADMIN_USER_ID);

        $this->assertPostedOnceTo($apiClient, 'all');
        self::assertTrue($result['success']);
        self::assertSame(0, $result['store_id']);
        self::assertSame('all store views', $result['store_label']);
        self::assertSame('Page "about-us" created for all store views', $result['message']);
        self::assertSame(
            [['label' => 'Edit About us', 'url' => 'https://admin.example/cms/page/edit/page_id/12/']],
            $result['_links']
        );
    }

    #[Test]
    public function itCreatesThePageInTheRequestedStoreView(): void
    {
        $apiClient = $this->apiClient();

        $result = $this->actionWith($apiClient)->execute($this->params(['store_id' => 2]), self::ADMIN_USER_ID);

        $this->assertPostedOnceTo($apiClient, 'luma');
        self::assertSame(2, $result['store_id']);
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
            'identifier' => 'about-us',
            'title' => 'About us',
            'content' => '<p>Hello</p>',
        ];
    }

    private function apiClient(): FakeInternalApiClient
    {
        return (new FakeInternalApiClient())->withResponse(FakeInternalApiClient::POST, 'cmsPage', ['id' => 12]);
    }

    private function assertPostedOnceTo(FakeInternalApiClient $apiClient, string $storeCode): void
    {
        $calls = $apiClient->callsOf(FakeInternalApiClient::POST);

        self::assertCount(1, $calls);
        self::assertSame('about-us', $calls[0]['payload']['page']['identifier']);
        self::assertSame(self::ADMIN_USER_ID, $calls[0]['admin_user_id']);
        self::assertSame($storeCode, $calls[0]['store_code']);
    }

    private function actionWith(FakeInternalApiClient $apiClient): CreatePageAction
    {
        $secureAdminUrl = $this->createMock(SecureAdminUrl::class);
        $secureAdminUrl->method('getUrl')
            ->with('cms/page/edit', ['page_id' => 12])
            ->willReturn('https://admin.example/cms/page/edit/page_id/12/');

        return new CreatePageAction($apiClient, new StoreScopeContext($this->multiStoreManager()), $secureAdminUrl);
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Skills\Content\CmsData\CreatePageAction;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\BuildsStoreLayouts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CreatePageActionTest extends TestCase
{
    use BuildsStoreLayouts;

    private const ADMIN_USER_ID = 7;

    #[Test]
    public function itCreatesThePageForAllStoreViewsByDefault(): void
    {
        $apiClient = $this->apiClientExpectingPost('all');

        $result = $this->actionWith($apiClient)->execute($this->params(), self::ADMIN_USER_ID);

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
        $apiClient = $this->apiClientExpectingPost('luma');

        $result = $this->actionWith($apiClient)->execute($this->params(['store_id' => 2]), self::ADMIN_USER_ID);

        self::assertSame(2, $result['store_id']);
        self::assertSame('store view "Luma" (id 2, code "luma")', $result['store_label']);
    }

    #[Test]
    public function itRejectsAnUnknownStoreViewWithoutCallingTheApi(): void
    {
        $apiClient = $this->createMock(InternalApiClient::class);
        $apiClient->expects(self::never())->method('post');

        $result = $this->actionWith($apiClient)->execute($this->params(['store_id' => 42]), self::ADMIN_USER_ID);

        self::assertStringStartsWith('Unknown store view id 42', $result['error']);
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

    private function apiClientExpectingPost(string $storeCode): InternalApiClient&MockObject
    {
        $apiClient = $this->createMock(InternalApiClient::class);
        $apiClient->expects(self::once())
            ->method('post')
            ->with(
                'cmsPage',
                self::callback(static fn (array $body): bool => $body['page']['identifier'] === 'about-us'),
                self::ADMIN_USER_ID,
                $storeCode
            )
            ->willReturn(['id' => 12]);

        return $apiClient;
    }

    private function actionWith(InternalApiClient $apiClient): CreatePageAction
    {
        $secureAdminUrl = $this->createMock(SecureAdminUrl::class);
        $secureAdminUrl->method('getUrl')
            ->with('cms/page/edit', ['page_id' => 12])
            ->willReturn('https://admin.example/cms/page/edit/page_id/12/');

        return new CreatePageAction($apiClient, new StoreScopeContext($this->multiStoreManager()), $secureAdminUrl);
    }
}

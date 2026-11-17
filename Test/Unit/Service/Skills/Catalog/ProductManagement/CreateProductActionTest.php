<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductManagement;

use MagoAssistant\Mago\Service\Skills\Catalog\ProductManagement\CreateProductAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #142: nothing in these tests covers the one-at-a-time questioning (that is prompt text,
 * fixed in getInstructions()/getDescription()) - this checks that resolved category_ids /
 * website_ids (what the model is now told to resolve via list_categories and the [Store scope]
 * context instead of asking the user) still reach the product payload unchanged.
 */
final class CreateProductActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    #[Test]
    public function resolvedCategoryAndWebsiteIdsAreAppliedToTheProductPayload(): void
    {
        $apiClient = $this->apiClientForNewSku('shirt-1');

        $this->action($apiClient)->execute([
            'sku' => 'shirt-1',
            'name' => 'Shirt',
            'product_type' => 'simple',
            'price' => 10,
            'category_ids' => [5, 6],
            'website_ids' => [1, 2],
        ], self::ADMIN_USER_ID);

        $payload = $apiClient->callsOf(FakeInternalApiClient::POST)[0]['payload']['product'];
        self::assertSame([1, 2], $payload['extension_attributes']['website_ids']);
        self::assertSame(
            [
                ['position' => 0, 'category_id' => '5'],
                ['position' => 1, 'category_id' => '6'],
            ],
            $payload['extension_attributes']['category_links']
        );
    }

    #[Test]
    public function websiteIdsDefaultToOneWhenTheUserNamedNoWebsite(): void
    {
        $apiClient = $this->apiClientForNewSku('shirt-2');

        $this->action($apiClient)->execute([
            'sku' => 'shirt-2',
            'name' => 'Shirt',
            'product_type' => 'simple',
            'price' => 10,
        ], self::ADMIN_USER_ID);

        $payload = $apiClient->callsOf(FakeInternalApiClient::POST)[0]['payload']['product'];
        self::assertSame([1], $payload['extension_attributes']['website_ids']);
        self::assertArrayNotHasKey('category_links', $payload['extension_attributes']);
    }

    private function apiClientForNewSku(string $sku): FakeInternalApiClient
    {
        return (new FakeInternalApiClient())
            ->withResponse('GET', 'products/' . $sku, ['error' => 'not found'])
            ->withResponse('POST', 'products', ['sku' => $sku, 'id' => 1]);
    }

    private function action(FakeInternalApiClient $apiClient): CreateProductAction
    {
        $secureAdminUrl = $this->createStub(SecureAdminUrl::class);
        $secureAdminUrl->method('getUrl')->willReturn('https://example.test/admin/product');

        return new CreateProductAction($apiClient, $secureAdminUrl);
    }
}

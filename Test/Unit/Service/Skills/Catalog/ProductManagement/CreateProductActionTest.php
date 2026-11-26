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
 * Issue #142. Most of the fix is prompt text (getInstructions()/getDescription()), not covered
 * here. What IS code: category_ids/website_ids (now resolved via list_categories/[Store scope]
 * instead of asked as raw IDs) still reach the product payload unchanged; findRefusal() reports a
 * missing price without any database access, so the confirmation card never shows for a product
 * that was never going to be created; and once a price is given, execute() checks SKU-exists and
 * similar-name together in ONE response, never one after the other.
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
            'details_asked' => true,
            'name' => 'Shirt',
            'product_type' => 'simple',
            'price' => 10,
        ], self::ADMIN_USER_ID);

        $payload = $apiClient->callsOf(FakeInternalApiClient::POST)[0]['payload']['product'];
        self::assertSame([1], $payload['extension_attributes']['website_ids']);
        self::assertArrayNotHasKey('category_links', $payload['extension_attributes']);
    }

    #[Test]
    public function aMissingPriceIsReportedByFindRefusalWithoutAnyDatabaseLookup(): void
    {
        $apiClient = $this->apiClientForNewSku('boots-1')
            ->withPagedItems('GET', 'products', [['sku' => 'boots-old', 'name' => 'Laarzen', 'type_id' => 'simple']]);
        $action = $this->action($apiClient);

        $params = ['sku' => 'boots-1', 'name' => 'Laarzen', 'product_type' => 'simple'];

        $refusal = $action->findRefusal($params);
        self::assertNotNull($refusal);
        self::assertTrue($refusal['not_created']);
        self::assertSame(['price'], $refusal['missing_required']);
        self::assertSame([], $refusal['similar_products']);

        $result = $action->execute($params, self::ADMIN_USER_ID);
        self::assertSame($refusal, $result);
        self::assertSame([], $apiClient->calls(), 'a missing price must never reach the database');
    }

    #[Test]
    public function aNameOnlyRequestAsksForThePriceAndEveryRelevantFieldInOneForm(): void
    {
        $refusal = $this->action(new FakeInternalApiClient())->findRefusal(
            ['sku' => 'SNEAKER', 'name' => 'Sneaker', 'product_type' => 'simple']
        );

        self::assertNotNull($refusal);
        self::assertSame(
            ['price', 'qty', 'weight', 'description', 'short_description', 'category', 'website'],
            $refusal['ask_fields']
        );
        self::assertSame(['price'], $refusal['missing_required']);
        self::assertStringContainsString('"type":"productForm"', $refusal['hint']);
        self::assertStringContainsString('"required":["price"]', $refusal['hint']);
        self::assertStringContainsString('details_asked: true', $refusal['hint']);
    }

    #[Test]
    public function aNameAndPriceAloneStillGetTheOptionalFieldsAskedOnce(): void
    {
        $refusal = $this->action(new FakeInternalApiClient())->findRefusal(
            ['sku' => 'SNEAKER', 'name' => 'Sneaker', 'product_type' => 'simple', 'price' => 49.95]
        );

        self::assertNotNull($refusal);
        self::assertSame([], $refusal['missing_required']);
        self::assertSame(
            ['qty', 'weight', 'description', 'short_description', 'category', 'website'],
            $refusal['ask_fields']
        );
        self::assertStringContainsString('"required":[]', $refusal['hint']);
    }

    #[Test]
    public function aRequestCarryingMoreThanNameAndPriceIsCreatedWithoutAsking(): void
    {
        $apiClient = $this->apiClientForNewSku('sneaker-2')->withPagedItems('GET', 'products', []);

        $result = $this->action($apiClient)->execute([
            'sku' => 'sneaker-2',
            'name' => 'Sneaker',
            'product_type' => 'simple',
            'price' => 49.95,
            'qty' => 10,
        ], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
    }

    #[Test]
    public function aPriceLeftEmptyInTheFormIsTheOnlyThingAskedAgain(): void
    {
        $refusal = $this->action(new FakeInternalApiClient())->findRefusal([
            'sku' => 'SNEAKER',
            'name' => 'Sneaker',
            'product_type' => 'simple',
            'qty' => 10,
            'details_asked' => true,
        ]);

        self::assertNotNull($refusal);
        self::assertSame(['price'], $refusal['missing_required']);
        self::assertArrayNotHasKey('ask_fields', $refusal);
    }

    #[Test]
    public function onceTheFormWasAnsweredANameAndPriceAreEnough(): void
    {
        self::assertNull($this->action(new FakeInternalApiClient())->findRefusal([
            'sku' => 'SNEAKER',
            'name' => 'Sneaker',
            'product_type' => 'simple',
            'price' => 49.95,
            'details_asked' => true,
        ]));
    }

    #[Test]
    public function theFormOnlyOffersFieldsTheProductTypeUses(): void
    {
        $action = $this->action(new FakeInternalApiClient());

        $virtual = $action->findRefusal(['sku' => 'V', 'name' => 'Service', 'product_type' => 'virtual']);
        $grouped = $action->findRefusal(['sku' => 'G', 'name' => 'Set', 'product_type' => 'grouped']);

        self::assertSame(['price', 'qty', 'description', 'short_description', 'category', 'website'], $virtual['ask_fields'] ?? null);
        self::assertSame(['description', 'short_description', 'category', 'website'], $grouped['ask_fields'] ?? null);
        self::assertSame([], $grouped['missing_required'] ?? null);
    }

    #[Test]
    public function aSimilarNameIsReportedWhenPriceIsAlreadyGiven(): void
    {
        $apiClient = $this->apiClientForNewSku('boots-2')
            ->withPagedItems('GET', 'products', [['sku' => 'boots-old', 'name' => 'Laarzen', 'type_id' => 'simple']]);

        $result = $this->action($apiClient)->execute([
            'sku' => 'boots-2',
            'details_asked' => true,
            'name' => 'Laarzen',
            'product_type' => 'simple',
            'price' => 59.95,
        ], self::ADMIN_USER_ID);

        self::assertTrue($result['not_created']);
        self::assertSame([], $result['missing_required']);
        self::assertArrayNotHasKey('sku_exists', $result);
        self::assertSame('boots-old', $result['similar_products'][0]['sku']);
        self::assertStringContainsString('similar name', $result['hint']);
        self::assertSame([], $apiClient->callsOf(FakeInternalApiClient::POST));
    }

    #[Test]
    public function anExistingSkuIsReportedAloneWhenTheNameIsNotSimilarToAnything(): void
    {
        $apiClient = (new FakeInternalApiClient())
            ->withResponse('GET', 'products/boots-3', ['id' => 42, 'sku' => 'boots-3'])
            ->withPagedItems('GET', 'products', []);

        $result = $this->action($apiClient)->execute([
            'sku' => 'boots-3',
            'details_asked' => true,
            'name' => 'Laarzen',
            'product_type' => 'simple',
            'price' => 59.95,
        ], self::ADMIN_USER_ID);

        self::assertTrue($result['not_created']);
        self::assertTrue($result['sku_exists']);
        self::assertSame([], $result['similar_products']);
        self::assertStringContainsString('boots-3', $result['hint']);
        self::assertSame([], $apiClient->callsOf(FakeInternalApiClient::POST));
    }

    #[Test]
    public function anExistingSkuAndASimilarNameAreReportedTogetherInOneCall(): void
    {
        $apiClient = (new FakeInternalApiClient())
            ->withResponse('GET', 'products/boots-4', ['id' => 42, 'sku' => 'boots-4'])
            ->withPagedItems('GET', 'products', [['sku' => 'boots-old', 'name' => 'Laarzen', 'type_id' => 'simple']]);

        $result = $this->action($apiClient)->execute([
            'sku' => 'boots-4',
            'details_asked' => true,
            'name' => 'Laarzen',
            'product_type' => 'simple',
            'price' => 59.95,
        ], self::ADMIN_USER_ID);

        self::assertTrue($result['not_created']);
        self::assertTrue($result['sku_exists']);
        self::assertSame('boots-old', $result['similar_products'][0]['sku']);
        self::assertStringContainsString('boots-4', $result['hint']);
        self::assertStringContainsString('similar name', $result['hint']);
    }

    #[Test]
    public function ignoreSimilarActuallySkipsTheSimilarNameDatabaseLookup(): void
    {
        $apiClient = $this->apiClientForNewSku('boots-5')
            ->withPagedItems('GET', 'products', [['sku' => 'boots-old', 'name' => 'Laarzen', 'type_id' => 'simple']]);

        $result = $this->action($apiClient)->execute([
            'sku' => 'boots-5',
            'details_asked' => true,
            'name' => 'Laarzen',
            'product_type' => 'simple',
            'price' => 59.95,
            'ignore_similar' => true,
        ], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        $getEndpoints = array_column($apiClient->callsOf(FakeInternalApiClient::GET), 'endpoint');
        self::assertSame(['products/boots-5'], $getEndpoints, 'the name-similarity search must not run');
    }

    #[Test]
    public function creationProceedsOnceEveryAnswerIsGivenAtOnce(): void
    {
        $apiClient = $this->apiClientForNewSku('boots-6')
            ->withPagedItems('GET', 'products', []);

        $result = $this->action($apiClient)->execute([
            'sku' => 'boots-6',
            'details_asked' => true,
            'name' => 'Laarzen',
            'product_type' => 'simple',
            'price' => 59.95,
        ], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        self::assertCount(1, $apiClient->callsOf(FakeInternalApiClient::POST));
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

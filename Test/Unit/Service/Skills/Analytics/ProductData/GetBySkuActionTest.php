<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Analytics\ProductData;

use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\GetBySkuAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "Open MH02-S-Red" got a link to the Products grid instead of the product: get_by_sku dropped the
 * product id the API returned, so neither an edit link nor admin_navigator's direct link was possible.
 */
final class GetBySkuActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    #[Test]
    public function itLinksTheProductToItsAdminEditPage(): void
    {
        $action = $this->action(['id' => 1234, 'sku' => 'MH02-S-Red', 'name' => 'Teton Pullover Hoodie-S-Red']);

        $result = $action->execute(['query' => 'MH02-S-Red'], self::ADMIN_USER_ID);

        self::assertArrayHasKey('admin_url', $result);
        self::assertSame('https://shop.test/admin/catalog/product/edit/id/1234/', $result['admin_url']);
    }

    /**
     * With the id the model can also ask admin_navigator for a direct link (entity_type + entity_id).
     */
    #[Test]
    public function itReturnsTheProductIdForAdminNavigator(): void
    {
        $action = $this->action(['id' => '1234', 'sku' => 'MH02-S-Red']);

        $result = $action->execute(['query' => 'MH02-S-Red'], self::ADMIN_USER_ID);

        self::assertSame(1234, $result['id']);
    }

    #[Test]
    public function aProductWithoutAnIdGetsNoLink(): void
    {
        $action = $this->action(['sku' => 'MH02-S-Red', 'name' => 'Teton Pullover Hoodie-S-Red']);

        $result = $action->execute(['query' => 'MH02-S-Red'], self::ADMIN_USER_ID);

        self::assertArrayNotHasKey('admin_url', $result);
        self::assertArrayNotHasKey('id', $result);
        self::assertSame('Teton Pullover Hoodie-S-Red', $result['name']);
    }

    #[Test]
    public function anUnknownSkuIsStillAnErrorWithoutALink(): void
    {
        $action = $this->action(['error' => 'The product that was requested doesn\'t exist.']);

        $result = $action->execute(['query' => 'NOPE'], self::ADMIN_USER_ID);

        self::assertSame(['error' => 'Product not found: NOPE'], $result);
    }

    /**
     * The id and the URL go to the model as tokens, the way admin_navigator's entity_id and every other
     * admin_url do.
     */
    #[Test]
    public function everyReturnedFieldIsClassifiedAndTheLinksAreTokenised(): void
    {
        $action = $this->action([
            'id' => 1234,
            'sku' => 'MH02-S-Red',
            'custom_attributes' => [['attribute_code' => 'special_price', 'value' => '19.99']],
        ]);

        $result = $action->execute(['query' => 'MH02-S-Red'], self::ADMIN_USER_ID);

        $classification = $action->getFieldClassification();
        self::assertSame([], array_diff(array_keys($result), array_keys($classification)));
        self::assertSame(['tokenise', 'entity'], $classification['id']);
        self::assertSame(['tokenise', 'url'], $classification['admin_url']);
    }

    /**
     * @param array<string,mixed> $product
     */
    private function action(array $product): GetBySkuAction
    {
        $apiClient = (new FakeInternalApiClient())->withResponseForEvery(FakeInternalApiClient::GET, $product);

        return new GetBySkuAction($apiClient, new SecureAdminUrl(new FakeBackendUrl()));
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\CreateRuleAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CreateRuleActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private FakeInternalApiClient $apiClient;

    protected function setUp(): void
    {
        $this->apiClient = (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::POST, 'salesRules', ['rule_id' => 12]);
    }

    private function action(): CreateRuleAction
    {
        $secureAdminUrl = $this->createStub(SecureAdminUrl::class);
        $secureAdminUrl->method('getUrl')->willReturn('https://example.test/admin/rule');

        return new CreateRuleAction($this->apiClient, $secureAdminUrl);
    }

    private function create(array $params): array
    {
        return $this->action()->execute($params + ['name' => 'Spring sale'], self::ADMIN_USER_ID);
    }

    /**
     * A rejected amount must never reach the create call, not just return an error alongside it.
     */
    private function assertNoRuleIsCreated(): void
    {
        self::assertSame([], $this->apiClient->callsOf(FakeInternalApiClient::POST));
    }

    private function assertOneRuleIsCreated(): void
    {
        self::assertCount(1, $this->apiClient->callsOf(FakeInternalApiClient::POST));
    }

    #[Test]
    public function aPercentageOverOneHundredIsRejected(): void
    {
        $result = $this->create(['discount_type' => 'percent', 'discount_amount' => 500]);

        self::assertSame(
            'discount_amount must be between 0 and 100 for a percentage discount',
            $result['error'] ?? null
        );
        $this->assertNoRuleIsCreated();
    }

    /**
     * A negative amount raises the grand total instead of discounting it, so no type accepts one.
     *
     * @return array<string,array{0:string}>
     */
    public static function discountTypes(): array
    {
        return [
            'percent' => ['percent'],
            'fixed' => ['fixed'],
            'free_shipping' => ['free_shipping'],
            'unknown type' => ['nonsense'],
        ];
    }

    #[Test]
    #[DataProvider('discountTypes')]
    public function aNegativeAmountIsRejectedForEveryType(string $discountType): void
    {
        $result = $this->create(['discount_type' => $discountType, 'discount_amount' => -10]);

        self::assertSame('discount_amount must be 0 or greater', $result['error'] ?? null);
        $this->assertNoRuleIsCreated();
    }

    /**
     * Magento's own form accepts zero (validate-zero-or-greater), so this does too: the bound is
     * stricter than the screen only where the screen's own clamp would otherwise hide a mistake.
     */
    #[Test]
    public function zeroPercentIsStillAllowedAsMagentoAllowsIt(): void
    {
        $result = $this->create(['discount_type' => 'percent', 'discount_amount' => 0]);

        self::assertArrayNotHasKey('error', $result);
        $this->assertOneRuleIsCreated();
    }

    /**
     * An unrecognised discount_type falls through to by_percent, so it has to be bounded too.
     */
    #[Test]
    public function anUnrecognisedDiscountTypeIsBoundedAsAPercentage(): void
    {
        $result = $this->create(['discount_type' => 'nonsense', 'discount_amount' => 500]);

        self::assertArrayHasKey('error', $result);
        $this->assertNoRuleIsCreated();
    }

    #[Test]
    public function oneHundredPercentOffIsStillAllowed(): void
    {
        $result = $this->create(['discount_type' => 'percent', 'discount_amount' => 100]);

        self::assertArrayNotHasKey('error', $result);
        self::assertSame(12, $result['rule_id']);
        $this->assertOneRuleIsCreated();
    }

    /**
     * free_shipping maps to by_percent but overwrites discount_amount with 0, so whatever amount
     * came in is never spent as a percentage and must not be bounded.
     */
    #[Test]
    public function freeShippingIsNotBoundedByThePercentageRange(): void
    {
        $result = $this->create(['discount_type' => 'free_shipping', 'discount_amount' => 0]);

        self::assertArrayNotHasKey('error', $result);
        $this->assertOneRuleIsCreated();
    }

    #[Test]
    public function aFixedAmountIsNotBoundedByThePercentageRange(): void
    {
        $result = $this->create(['discount_type' => 'fixed', 'discount_amount' => 500]);

        self::assertArrayNotHasKey('error', $result);
        $this->assertOneRuleIsCreated();
    }

    #[Test]
    public function theParameterSchemaNamesTheBound(): void
    {
        $description = $this->action()->getParameterSchema()['discount_amount']['description'];

        self::assertStringContainsString('0 or greater', $description);
        self::assertStringContainsString('at most 100', $description);
    }
}

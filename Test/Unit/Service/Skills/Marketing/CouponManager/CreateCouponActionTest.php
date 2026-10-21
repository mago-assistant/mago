<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\CreateCouponAction;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleState;
use MagoAssistant\Mago\Service\Time\StoreTime;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The REST rule payload carries coupon_type as a string, which an int cast read as 0, so the
 * no-coupon guard never fired (#277).
 */
class CreateCouponActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    public static function rulesThatCannotGenerate(): array
    {
        return [
            'no coupon' => [['coupon_type' => 'NO_COUPON'], 'does not use coupon codes'],
            'one fixed code' => [
                ['coupon_type' => 'SPECIFIC_COUPON', 'use_auto_generation' => false],
                'Use Auto Generation',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    #[Test]
    #[DataProvider('rulesThatCannotGenerate')]
    public function aRuleThatCannotGenerateIsRefusedBeforeTheGenerateCall(array $rule, string $reason): void
    {
        $apiClient = (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'salesRules/5', $rule + ['name' => 'Summer sale']);

        $result = $this->action($apiClient)->execute(['rule_id' => 5], self::ADMIN_USER_ID);

        self::assertStringContainsString($reason, $result['error'] ?? '');
        self::assertSame([], $apiClient->callsOf(FakeInternalApiClient::POST));
    }

    #[Test]
    public function aRuleWithAutoGenerationReachesTheGenerateCall(): void
    {
        $apiClient = (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'salesRules/5', [
                'name' => 'Summer sale',
                'coupon_type' => 'SPECIFIC_COUPON',
                'use_auto_generation' => true,
            ])
            ->withResponse(FakeInternalApiClient::POST, 'coupons/generate', ['AB12CD34']);

        $this->action($apiClient)->execute(['rule_id' => 5], self::ADMIN_USER_ID);

        self::assertCount(1, $apiClient->callsOf(FakeInternalApiClient::POST));
    }

    #[Test]
    public function itCautionsHowManyWorkingCodesItCreatesForWhichRule(): void
    {
        $apiClient = (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'salesRules/5', ['name' => 'Summer sale']);

        $cautions = $this->action($apiClient)->getCautions(['rule_id' => 5, 'quantity' => 500], self::ADMIN_USER_ID);

        self::assertSame(
            ['Creates 500 working coupon codes for cart price rule "Summer sale" (5), '
                . 'each giving its discount to whoever enters it.'],
            $cautions
        );
    }

    #[Test]
    public function itCautionsAboutAtLeastOneCodeLikeTheGenerateCallMakes(): void
    {
        $apiClient = (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'salesRules/5', ['name' => 'Summer sale']);

        $cautions = $this->action($apiClient)->getCautions(['rule_id' => 5, 'quantity' => 0], self::ADMIN_USER_ID);

        self::assertStringStartsWith('Creates 1 working coupon code for', $cautions[0]);
    }

    #[Test]
    public function itStillCautionsWhenTheRuleCannotBeRead(): void
    {
        $apiClient = (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'salesRules/5', ['error' => 'Not allowed']);

        $cautions = $this->action($apiClient)->getCautions(['rule_id' => 5], self::ADMIN_USER_ID);

        self::assertStringContainsString('for cart price rule 5,', $cautions[0]);
    }

    /**
     * @return array<string, array{0: array<string, int>, 1: string}>
     */
    public static function batchesTooLargeForTheChat(): array
    {
        return [
            'too many codes' => [['quantity' => 5000], 'at most 100 coupon codes'],
            'codes too long' => [['length' => 500], 'at most 32 characters'],
        ];
    }

    /**
     * @param array<string, int> $params
     */
    #[Test]
    #[DataProvider('batchesTooLargeForTheChat')]
    public function aBatchTooLargeForTheChatIsRefusedBeforeTheCardAndNeverGenerated(array $params, string $reason): void
    {
        $apiClient = (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'salesRules/5', [
                'name' => 'Summer sale',
                'coupon_type' => 'SPECIFIC_COUPON',
                'use_auto_generation' => true,
            ]);
        $action = $this->action($apiClient);

        $refusal = $action->findRefusal(['rule_id' => 5] + $params);
        $result = $action->execute(['rule_id' => 5] + $params, self::ADMIN_USER_ID);

        self::assertStringContainsString($reason, $refusal['error'] ?? '');
        self::assertStringContainsString($reason, $result['error'] ?? '');
        self::assertSame([], $apiClient->callsOf(FakeInternalApiClient::POST));
    }

    #[Test]
    public function aHundredCodesIsStillGenerated(): void
    {
        $action = $this->action(new FakeInternalApiClient());

        self::assertNull($action->findRefusal(['rule_id' => 5, 'quantity' => 100, 'length' => 32]));
    }

    private function action(FakeInternalApiClient $apiClient): CreateCouponAction
    {
        $secureAdminUrl = $this->createStub(SecureAdminUrl::class);
        $secureAdminUrl->method('getUrl')->willReturn('https://example.test/admin/rule');

        return new CreateCouponAction($apiClient, $secureAdminUrl, new RuleState($this->createStub(StoreTime::class)));
    }
}

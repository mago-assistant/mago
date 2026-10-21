<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing;

use MagoAssistant\Mago\Service\Skills\Marketing\DiscountRuleCautions;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DiscountRuleCautionsTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private FakeInternalApiClient $apiClient;

    protected function setUp(): void
    {
        $this->apiClient = (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'store/websites', [
                ['id' => 0, 'code' => 'admin', 'name' => 'Admin'],
                ['id' => 1, 'code' => 'base', 'name' => 'Main Website'],
                ['id' => 2, 'code' => 'b2b', 'name' => 'Wholesale Site'],
            ])
            ->withResponse(FakeInternalApiClient::GET, 'customerGroups/search', ['items' => [
                ['id' => 0, 'code' => 'NOT LOGGED IN'],
                ['id' => 1, 'code' => 'General'],
                ['id' => 2, 'code' => 'Wholesale'],
            ]]);
    }

    #[Test]
    public function itNamesEveryWebsiteWhenTheRuleTakesThemAll(): void
    {
        $lines = $this->scope(null, null);

        self::assertSame('Applies on every website: Main Website, Wholesale Site.', $lines[0]);
    }

    #[Test]
    public function itNamesTheChosenWebsites(): void
    {
        $lines = $this->scope([2], null);

        self::assertSame('Applies on website Wholesale Site.', $lines[0]);
    }

    #[Test]
    public function itNamesAWebsiteItCannotFindByItsId(): void
    {
        $lines = $this->scope([1, 9], null);

        self::assertSame('Applies on websites Main Website, website 9.', $lines[0]);
    }

    #[Test]
    public function itStillCautionsWhenTheWebsiteNamesCannotBeRead(): void
    {
        $this->apiClient->withResponse(FakeInternalApiClient::GET, 'store/websites', ['error' => 'Not allowed']);

        $lines = $this->scope(null, null);

        self::assertSame('Applies on every website.', $lines[0]);
    }

    #[Test]
    public function itCallsOutGuestsWhenTheRuleTakesEveryCustomerGroup(): void
    {
        $lines = $this->scope(null, null);

        self::assertSame('Applies to every customer group, guests (NOT LOGGED IN) included.', $lines[1]);
    }

    #[Test]
    public function itCallsOutGuestsAmongTheChosenGroups(): void
    {
        $lines = $this->scope(null, [0, 1]);

        self::assertSame('Applies to customer groups NOT LOGGED IN, General, so guests get it too.', $lines[1]);
    }

    #[Test]
    public function itNamesTheChosenGroupsWithoutGuests(): void
    {
        $lines = $this->scope(null, [2]);

        self::assertSame('Applies to customer group Wholesale.', $lines[1]);
    }

    #[Test]
    public function itSaysARuleWithoutDatesRunsUntilSwitchedOff(): void
    {
        $lines = $this->scope(null, null);

        self::assertSame('No start or end date: it runs until someone switches it off.', $lines[2]);
    }

    #[Test]
    public function itNamesBothDatesOfABoundedRule(): void
    {
        $lines = (new DiscountRuleCautions($this->apiClient))
            ->describeScope(null, null, '2026-11-01', '2026-11-30', self::ADMIN_USER_ID);

        self::assertSame('Runs from 2026-11-01 until 2026-11-30.', $lines[2]);
    }

    #[Test]
    public function itFormatsAnAmountAsTheAdminWouldTypeIt(): void
    {
        $cautions = new DiscountRuleCautions($this->apiClient);

        $amounts = [$cautions->formatAmount(20.0), $cautions->formatAmount(12.5), $cautions->formatAmount(0.0)];

        self::assertSame(['20', '12.5', '0'], $amounts);
    }

    /**
     * @param int[]|null $websiteIds
     * @param int[]|null $customerGroupIds
     * @return string[]
     */
    private function scope(?array $websiteIds, ?array $customerGroupIds): array
    {
        return (new DiscountRuleCautions($this->apiClient))
            ->describeScope($websiteIds, $customerGroupIds, '', '', self::ADMIN_USER_ID);
    }
}

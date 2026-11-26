<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CatalogPriceRules;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\ApplyRulesAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCatalogRule;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCatalogRuleRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCatalogRuleServices;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeRulePriceReindexer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #329: with a rule_id, apply_rules recalculates that rule's prices right away; without one
 * it only marks every rule for the full reindex cron runs, and the card says so.
 */
final class ApplyRulesActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;
    private const RULE_ID = 13;

    private FakeRulePriceReindexer $reindexer;

    protected function setUp(): void
    {
        $this->reindexer = new FakeRulePriceReindexer(false, [4, 5, 6]);
    }

    #[Test]
    public function itCautionsThatApplyingEveryRuleWaitsForCron(): void
    {
        $cautions = $this->actionWithActiveRules(3)->getCautions([], self::ADMIN_USER_ID);

        self::assertSame(
            ['Marks all 3 active catalog price rules for a full reindex: cron recalculates the price of every '
                . 'product on its next run, not right away.'],
            $cautions
        );
    }

    #[Test]
    public function itNamesASingleActiveRuleInTheSingular(): void
    {
        $cautions = $this->actionWithActiveRules(1)->getCautions([], self::ADMIN_USER_ID);

        self::assertStringContainsString('Marks all 1 active catalog price rule for', $cautions[0]);
    }

    #[Test]
    public function itCautionsWhichProductsOneRuleRecalculatesNow(): void
    {
        $cautions = $this->actionWithActiveRules(1)->getCautions(['rule_id' => self::RULE_ID], self::ADMIN_USER_ID);

        self::assertSame(
            ['Recalculates now the price of the 3 products catalog price rule "10% off Red products" (ID 13) '
                . 'reaches or reached, and refreshes their cached pages.'],
            $cautions
        );
    }

    #[Test]
    public function itReindexesOnlyTheGivenRule(): void
    {
        $result = $this->actionWithActiveRules(1)->execute(['rule_id' => self::RULE_ID], self::ADMIN_USER_ID);

        self::assertSame([self::RULE_ID], $this->reindexer->reindexedRuleIds());
        self::assertSame(3, $result['product_count']);
        self::assertStringContainsString('the storefront shows them now', $result['message']);
    }

    #[Test]
    public function anUnknownRuleIsRefusedBeforeTheCard(): void
    {
        $refusal = $this->actionWithActiveRules(1)->findRefusal(['rule_id' => 99]);

        self::assertStringContainsString('"99" ID wasn\'t found', $refusal['error'] ?? '');
        self::assertSame([], $this->reindexer->reindexedRuleIds());
    }

    private function actionWithActiveRules(int $count): ApplyRulesAction
    {
        $repository = (new FakeCatalogRuleRepository())->withRule(
            self::RULE_ID,
            new FakeCatalogRule(['rule_id' => self::RULE_ID, 'name' => '10% off Red products', 'is_active' => 1])
        );

        return new ApplyRulesAction(
            new FakeCatalogRuleServices($repository, null, $count),
            new ErrorReporter(new ErrorLogger(new FakeLogger(), new Json()), new PiiHeuristic()),
            $this->reindexer
        );
    }
}

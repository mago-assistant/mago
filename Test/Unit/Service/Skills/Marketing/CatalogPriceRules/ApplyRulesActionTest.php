<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\ApplyRulesAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCatalogRuleServices;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplyRulesActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    #[Test]
    public function itCautionsThatApplyingChangesEveryProductPriceFromTheActiveRules(): void
    {
        $cautions = $this->actionWithActiveRules(3)->getCautions([], self::ADMIN_USER_ID);

        self::assertSame(
            ['Recalculates the price of every product from the 3 active catalog price rules, '
                . 'including any created or changed since they were last applied.'],
            $cautions
        );
    }

    #[Test]
    public function itNamesASingleActiveRuleInTheSingular(): void
    {
        $cautions = $this->actionWithActiveRules(1)->getCautions([], self::ADMIN_USER_ID);

        self::assertStringContainsString('from the 1 active catalog price rule,', $cautions[0]);
    }

    private function actionWithActiveRules(int $count): ApplyRulesAction
    {
        return new ApplyRulesAction(
            new FakeCatalogRuleServices($this->createStub(CatalogRuleRepositoryInterface::class), null, $count),
            new ErrorReporter(new ErrorLogger(new FakeLogger(), new Json()), new PiiHeuristic())
        );
    }
}

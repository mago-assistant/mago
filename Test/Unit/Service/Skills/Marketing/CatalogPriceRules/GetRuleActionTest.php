<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\GetRuleAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCatalogRuleServices;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GetRuleActionTest extends TestCase
{
    /**
     * The tool result reaches the model, the stored trace and a WebApi caller; a driver's message
     * carries the query (#224)
     */
    #[Test]
    public function aDatabaseFailureReachesTheModelOnlyAsAReference(): void
    {
        $result = $this->actionFailingWith(
            new \RuntimeException('SQLSTATE[HY000]: General error, query was: SELECT * FROM catalogrule')
        )->execute(['rule_id' => 3], 7);

        self::assertStringStartsWith(
            'Failed to get catalog price rule: The tool failed unexpectedly',
            $result['error']
        );
        self::assertStringNotContainsString('SELECT', $result['error']);
    }

    #[Test]
    public function magentosOwnReasonStillReachesTheModel(): void
    {
        $result = $this->actionFailingWith(
            new NoSuchEntityException(__('The rule with the "3" ID wasn\'t found. Verify the ID and try again.'))
        )->execute(['rule_id' => 3], 7);

        self::assertStringContainsString('wasn\'t found', $result['error']);
    }

    private function actionFailingWith(\Throwable $exception): GetRuleAction
    {
        $repository = $this->createStub(CatalogRuleRepositoryInterface::class);
        $repository->method('get')->willThrowException($exception);

        return new GetRuleAction(
            new FakeCatalogRuleServices($repository),
            $this->createStub(SecureAdminUrl::class),
            new ErrorReporter(new ErrorLogger(new FakeLogger(), new Json()), new PiiHeuristic())
        );
    }
}

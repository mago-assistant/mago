<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\CatalogRule\Model\Rule;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\CreateRuleAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCatalogRuleServices;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Issue #199: a catalog rule saved through CatalogRuleRepositoryInterface skips the validation
 * Magento's own save controller runs, so a percentage above 100 reached the indexer, which
 * computes price * (1 - amount/100) with no clamp and indexes a negative price.
 *
 * What the bound itself is - 0 to 100 for the percentage actions, 0 or greater for the fixed ones -
 * is Magento's rule, checked by Magento's own tests. What is checked here is that this action asks
 * before saving and refuses when the answer is no.
 */
class CreateRuleActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private CatalogRuleRepositoryInterface&MockObject $catalogRuleRepository;

    private Rule&MockObject $rule;

    private CreateRuleAction $action;

    protected function setUp(): void
    {
        $this->catalogRuleRepository = $this->createMock(CatalogRuleRepositoryInterface::class);

        $this->rule = $this->createMock(Rule::class);
        $this->rule->method('getId')->willReturn(12);
        $this->rule->method('getData')->willReturn(['simple_action' => 'by_percent', 'discount_amount' => 500.0]);

        $secureAdminUrl = $this->createMock(SecureAdminUrl::class);
        $secureAdminUrl->method('getUrl')->willReturn('https://example.test/admin/rule');

        $this->action = $this->actionWith([
            'catalogRules' => new FakeCatalogRuleServices($this->catalogRuleRepository, $this->rule),
            'secureAdminUrl' => $secureAdminUrl,
            'errorReporter' => new ErrorReporter(new ErrorLogger(new FakeLogger(), new Json()), new PiiHeuristic()),
        ]);
    }

    /**
     * The constructor also takes a StoreManagerInterface and the customer group CollectionFactory,
     * both only reached when website_ids / customer_group_ids are absent - which create() always
     * supplies. The factory is a generated class, so it cannot be mocked under this module's own
     * test bootstrap (Magento's dev/tests/unit bootstrap, which CI runs the suite under, registers
     * a FactoryGenerator autoloader for exactly that); constructing around it keeps this test
     * runnable in both. Each named dependency is assigned to its promoted property, which is
     * readonly but still uninitialized at this point.
     *
     * @param array<string,object> $dependencies
     */
    private function actionWith(array $dependencies): CreateRuleAction
    {
        $action = (new \ReflectionClass(CreateRuleAction::class))->newInstanceWithoutConstructor();

        foreach ($dependencies as $property => $dependency) {
            (new \ReflectionProperty(CreateRuleAction::class, $property))->setValue($action, $dependency);
        }

        return $action;
    }

    /**
     * website_ids and customer_group_ids are passed in so the happy path never reaches the store
     * manager or the customer group collection, which this test does not stub.
     */
    private function create(array $params = []): array
    {
        return $this->action->execute(
            $params + [
                'name' => 'Spring sale',
                'discount_type' => 'percent',
                'discount_amount' => 500,
                'website_ids' => [1],
                'customer_group_ids' => [0, 1],
            ],
            self::ADMIN_USER_ID
        );
    }

    /**
     * Asking Magento rather than bounding the amount here is what also picks up its checks on fixed
     * amounts, unknown actions and the rule's dates - and reports them in Magento's own words.
     */
    #[Test]
    public function aRuleMagentoRejectsIsNotSaved(): void
    {
        $this->rule->expects(self::once())->method('validateData')
            ->with(self::callback(
                static fn(DataObject $data): bool => $data->getData('simple_action') === 'by_percent'
                    && $data->getData('discount_amount') === 500.0
            ))
            ->willReturn([__('Percentage discount should be between 0 and 100.')]);
        $this->catalogRuleRepository->expects(self::never())->method('save');

        $result = $this->create();

        self::assertStringContainsString('between 0 and 100', $result['error'] ?? '');
    }

    #[Test]
    public function everyReasonMagentoGivesReachesTheAdministrator(): void
    {
        $this->rule->method('validateData')->willReturn([
            __('Percentage discount should be between 0 and 100.'),
            __('The rule end date has to be later than the start date.'),
        ]);

        $result = $this->create();

        self::assertStringContainsString('between 0 and 100', $result['error']);
        self::assertStringContainsString('later than the start date', $result['error']);
    }

    #[Test]
    public function aRuleMagentoAcceptsIsSaved(): void
    {
        $this->rule->method('validateData')->willReturn(true);
        $this->catalogRuleRepository->expects(self::once())->method('save');

        $result = $this->create(['discount_amount' => 20]);

        self::assertArrayNotHasKey('error', $result);
        self::assertSame(12, $result['rule_id']);
    }

    /**
     * An unknown discount_type is refused before a rule is ever built, so Magento's "Unknown
     * action." is never the message the administrator sees for a typo in the tool call.
     */
    #[Test]
    public function anUnknownDiscountTypeIsRefusedBeforeTheRuleIsBuilt(): void
    {
        $this->catalogRuleRepository->expects(self::never())->method('save');

        $result = $this->create(['discount_type' => 'nonsense']);

        self::assertStringContainsString('Invalid discount_type', $result['error']);
    }

    #[Test]
    public function theParameterSchemaNamesTheBounds(): void
    {
        $description = $this->action->getParameterSchema()['discount_amount']['description'];

        self::assertStringContainsString('greater than 0', $description);
        self::assertStringContainsString('at most 100', $description);
    }
}

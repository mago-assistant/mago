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
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\ProductMatches;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\PromoAttribute;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\RuleConditionReview;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\RuleConditions;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\UpdateRuleAction;
use MagoAssistant\Mago\Service\Skills\Marketing\DiscountRuleCautions;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCatalogRule;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCatalogRuleRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeCatalogRuleServices;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeMatchingProducts;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePromoAttributes;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeRulePriceReindexer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #329: update_rule changes only the fields it is given, shows the admin each change on the
 * caution card, and refuses before the card what it would refuse anyway.
 */
final class UpdateRuleActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;
    private const RULE_ID = 3;

    private FakeCatalogRule $rule;
    private FakeCatalogRuleRepository $repository;
    private FakeMatchingProducts $matchingProducts;
    private FakeRulePriceReindexer $reindexer;

    protected function setUp(): void
    {
        $this->rule = new FakeCatalogRule([
            'rule_id' => self::RULE_ID,
            'name' => 'Winter sale',
            'is_active' => 1,
            'simple_action' => 'by_percent',
            'discount_amount' => 10.0,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-14',
            'sort_order' => 0,
            'website_ids' => [1, 2],
        ]);
        $this->repository = (new FakeCatalogRuleRepository())->withRule(self::RULE_ID, $this->rule);
        $this->matchingProducts = new FakeMatchingProducts(new ProductMatches(2, ['CAM-1' => 'Turret 8MP']));
        $this->reindexer = new FakeRulePriceReindexer();
    }

    #[Test]
    public function onScheduledIndexersItAsksToApplyTheRuleRightAway(): void
    {
        $result = $this->action()->execute(['rule_id' => self::RULE_ID, 'discount_amount' => 15], self::ADMIN_USER_ID);

        self::assertStringEndsWith('offer apply_rules with rule_id 3 to recalculate them right away.', $result['message']);
    }

    #[Test]
    public function onUpdateOnSaveItSaysPricesAreAlreadyRecalculated(): void
    {
        $this->reindexer = new FakeRulePriceReindexer(true);

        $result = $this->action()->execute(['rule_id' => self::RULE_ID, 'discount_amount' => 15], self::ADMIN_USER_ID);

        self::assertStringEndsWith('there is no need to run apply_rules.', $result['message']);
    }

    #[Test]
    public function itChangesOnlyTheFieldsItIsGiven(): void
    {
        $result = $this->action()->execute(
            ['rule_id' => self::RULE_ID, 'discount_amount' => 15, 'to_date' => '2026-12-21'],
            self::ADMIN_USER_ID
        );

        self::assertTrue($result['success']);
        self::assertSame([$this->rule], $this->repository->savedRules());
        self::assertSame(15.0, $this->rule->getData('discount_amount'));
        self::assertSame('2026-12-21', $this->rule->getData('to_date'));
        self::assertSame('2026-12-01', $this->rule->getData('from_date'));
        self::assertSame('by_percent', $this->rule->getData('simple_action'));
        self::assertSame('Winter sale', $this->rule->getData('name'));
    }

    #[Test]
    public function anEmptyDateRemovesIt(): void
    {
        $this->action()->execute(['rule_id' => self::RULE_ID, 'to_date' => ''], self::ADMIN_USER_ID);

        self::assertNull($this->rule->getData('to_date'));
    }

    #[Test]
    public function itSwitchesARuleBackOn(): void
    {
        $this->rule->setData('is_active', 0);

        $this->action()->execute(['rule_id' => self::RULE_ID, 'is_active' => 'true'], self::ADMIN_USER_ID);

        self::assertSame(1, $this->rule->getData('is_active'));
    }

    #[Test]
    public function aRuleMagentoRejectsIsNotSaved(): void
    {
        $this->rule->givenValidationFails(['Percentage discount should be between 0 and 100.']);

        $result = $this->action()->execute(['rule_id' => self::RULE_ID, 'discount_amount' => 500], self::ADMIN_USER_ID);

        self::assertSame('Percentage discount should be between 0 and 100.', $result['error']);
        self::assertSame([], $this->repository->savedRules());
    }

    #[Test]
    public function theCardNamesEveryChange(): void
    {
        $cautions = $this->action()->getCautions(
            [
                'rule_id' => self::RULE_ID,
                'discount_type' => 'fixed',
                'discount_amount' => 5,
                'to_date' => '',
                'is_active' => false,
                'website_ids' => [1],
            ],
            self::ADMIN_USER_ID
        );

        self::assertSame(
            [
                'Changes catalog price rule "Winter sale" (ID 3).',
                'End date: 2026-12-14 -> none.',
                'Discount: 10% off -> 5 off.',
                'From now on: applies on website website 1.',
                'Switches the rule off.',
            ],
            $cautions
        );
    }

    #[Test]
    public function theCardShowsTheNewConditionsAndWhatTheyMatchOnTheRulesWebsites(): void
    {
        $cautions = $this->action()->getCautions(
            ['rule_id' => self::RULE_ID, 'conditions' => [['attribute' => 'color', 'values' => ['Red']]]],
            self::ADMIN_USER_ID
        );

        self::assertSame(
            [
                'Changes catalog price rule "Winter sale" (ID 3).',
                'Replaces the rule\'s conditions.',
                'Only products where Color is Red.',
                'Matches 2 products right now, for example CAM-1 (Turret 8MP).',
            ],
            $cautions
        );
        self::assertSame([[1, 2]], $this->matchingProducts->askedWebsiteIds());
    }

    #[Test]
    public function theCardNamesTheCategoryThatReplacesTheConditions(): void
    {
        $cautions = $this->action()->getCautions(['rule_id' => self::RULE_ID, 'category_ids' => [42]], self::ADMIN_USER_ID);

        self::assertSame(
            [
                'Changes catalog price rule "Winter sale" (ID 3).',
                'Replaces the rule\'s conditions.',
                'Only products where the category is one of 42.',
                'Matches 2 products right now, for example CAM-1 (Turret 8MP).',
            ],
            $cautions
        );
    }

    /**
     * The repository hands out this same instance for the rest of the request; a later write to
     * the rule must not save what Magento refused here
     */
    #[Test]
    public function aRefusedUpdateLeavesTheRuleAsItWas(): void
    {
        $this->rule->givenValidationFails(['End Date must follow Start Date.']);

        $this->action()->execute(
            ['rule_id' => self::RULE_ID, 'discount_amount' => 50, 'to_date' => '2026-11-01', 'is_active' => false],
            self::ADMIN_USER_ID
        );

        self::assertSame(10.0, $this->rule->getData('discount_amount'));
        self::assertSame('2026-12-14', $this->rule->getData('to_date'));
        self::assertSame(1, $this->rule->getData('is_active'));
    }

    #[Test]
    public function emptyConditionsAreCalledOutAsReachingEveryProduct(): void
    {
        $cautions = $this->action()->getCautions(['rule_id' => self::RULE_ID, 'conditions' => []], self::ADMIN_USER_ID);

        self::assertContains('Removes every condition: the rule reaches every product.', $cautions);
    }

    #[Test]
    public function aCallThatChangesNothingIsRefused(): void
    {
        $refusal = $this->action()->findRefusal(['rule_id' => self::RULE_ID]);

        self::assertStringStartsWith('Nothing to change', $refusal['error'] ?? '');
    }

    #[Test]
    public function anUnknownRuleIsRefusedBeforeTheCard(): void
    {
        $refusal = $this->action()->findRefusal(['rule_id' => 99, 'discount_amount' => 5]);

        self::assertStringContainsString('"99" ID wasn\'t found', $refusal['error'] ?? '');
    }

    #[Test]
    public function conditionsMatchAloneIsRefused(): void
    {
        $refusal = $this->action()->findRefusal(['rule_id' => self::RULE_ID, 'conditions_match' => 'any']);

        self::assertStringContainsString('only changes together with conditions', $refusal['error'] ?? '');
    }

    #[Test]
    public function conditionsThatMatchNothingAreRefused(): void
    {
        $this->matchingProducts = new FakeMatchingProducts(new ProductMatches(0, []));

        $refusal = $this->action()->findRefusal(
            ['rule_id' => self::RULE_ID, 'conditions' => [['attribute' => 'color', 'values' => ['Red']]]]
        );

        self::assertStringContainsString('match no products', $refusal['error'] ?? '');
    }

    #[Test]
    public function anInvalidDiscountTypeIsRefused(): void
    {
        $refusal = $this->action()->findRefusal(['rule_id' => self::RULE_ID, 'discount_type' => 'half']);

        self::assertStringStartsWith('Invalid discount_type', $refusal['error'] ?? '');
    }

    private function action(): UpdateRuleAction
    {
        $errorReporter = new ErrorReporter(new ErrorLogger(new FakeLogger(), new Json()), new PiiHeuristic());
        $secureAdminUrl = new SecureAdminUrl(new FakeBackendUrl());

        return new UpdateRuleAction(
            new FakeCatalogRuleServices($this->repository),
            $secureAdminUrl,
            $errorReporter,
            new DiscountRuleCautions(new FakeInternalApiClient()),
            new RuleConditionReview(
                new RuleConditions(
                    new FakePromoAttributes(new PromoAttribute(93, 'color', 'Color', 'select', true, ['49' => 'Red'])),
                    $secureAdminUrl
                ),
                $this->matchingProducts,
                $errorReporter
            ),
            $this->reindexer
        );
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Model\Rule;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\Marketing\DiscountRuleCautions;
use MagoAssistant\Mago\Service\Skills\Marketing\RuleIds;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

/**
 * Changes the fields of an existing catalog price rule that the call names and leaves the rest as
 * they are. conditions and category_ids together replace the rule's whole condition set.
 */
class UpdateRuleAction implements CautionedActionInterface, ValidatingActionInterface
{
    private const SIMPLE_FIELDS = [
        'name' => 'name',
        'from_date' => 'from_date',
        'to_date' => 'to_date',
        'priority' => 'sort_order',
    ];
    private const FIELD_LABELS = [
        'name' => 'Name',
        'from_date' => 'Start date',
        'to_date' => 'End date',
        'priority' => 'Priority',
    ];
    private const CHANGEABLE = [
        'name', 'discount_type', 'discount_amount', 'from_date', 'to_date', 'website_ids',
        'customer_group_ids', 'category_ids', 'conditions', 'priority', 'stop_further_rules', 'is_active',
    ];

    public function __construct(
        private readonly CatalogRuleServices $catalogRules,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly ErrorReporter $errorReporter,
        private readonly DiscountRuleCautions $cautions,
        private readonly RuleConditionReview $conditionReview,
        private readonly RulePriceReindexer $reindexer
    ) {
    }

    public function getName(): string
    {
        return 'update_rule';
    }

    public function getDescription(): string
    {
        return 'Change an existing catalog price rule; only the fields passed change';
    }

    public function getParameterSchema(): array
    {
        return [
            'rule_id' => [
                'type' => 'integer',
                'description' => 'The catalog price rule ID',
            ],
            ...RuleFields::schema(),
            'is_active' => [
                'type' => 'boolean',
                'description' => 'Switch the rule on (true) or off (false)',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'message' => [PiiClass::PUBLIC],
            'success' => [PiiClass::PUBLIC],
            'rule_id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'update_rule: pass rule_id and only the fields to change. An empty from_date or to_date '
            . 'removes that date. conditions (with conditions_match) and category_ids replace the rule\'s '
            . 'whole condition set, so call get_rule first and pass every condition that should remain.';
    }

    public function getCautions(array $params, int $adminUserId): array
    {
        try {
            $rule = $this->loadRule($params);
        } catch (NoSuchEntityException) {
            return [];
        }

        return array_values(array_filter([
            'Changes catalog price rule "' . $rule->getData('name') . '" (ID ' . (int)$rule->getId() . ').',
            ...$this->describeFieldChanges($params, $rule),
            $this->describeDiscountChange($params, $rule),
            ...$this->describeScopeChanges($params, $adminUserId),
            ...$this->describeConditionChange($params, $rule),
            $this->describeActiveChange($params, $rule),
        ]));
    }

    public function findRefusal(array $params): ?array
    {
        $refusal = $this->findInputRefusal($params);
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            $rule = $this->loadRule($params);
        } catch (NoSuchEntityException $e) {
            return ['error' => $e->getMessage()];
        }

        return $this->conditionReview->findRefusal($params, $this->websiteIdsFor($params, $rule), $this->toolName());
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $refusal = $this->findRefusal($params);
        if ($refusal !== null) {
            return $refusal;
        }

        // findRefusal() just loaded this rule, so it is known to exist
        $rule = $this->loadRule($params);
        $snapshot = $this->snapshot($params, $rule);
        try {
            $error = $this->saveChanges($params, $rule);
        } catch (\Exception $e) {
            $this->restore($rule, $snapshot);
            return ['error' => 'Failed to update catalog price rule: '
                . $this->errorReporter->reportToolFailure($this->toolName(), $e)];
        }
        if ($error !== null) {
            $this->restore($rule, $snapshot);
            return ['error' => $error];
        }

        $ruleId = (int)$rule->getId();

        return [
            'success' => true,
            'rule_id' => $ruleId,
            'name' => (string)$rule->getData('name'),
            'message' => 'Catalog price rule "' . $rule->getData('name') . '" updated. '
                . $this->reindexer->describeAfterSave($ruleId),
            'admin_url' => $this->secureAdminUrl->getUrl('catalog_rule/promo_catalog/edit', ['id' => $ruleId]),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return string|null Magento's reasons when it refuses the changed rule, null once saved
     */
    private function saveChanges(array $params, Rule $rule): ?string
    {
        $this->applyChanges($params, $rule);

        // The admin's save controller validates and the repository does not (#199)
        $validation = $rule->validateData(new DataObject($rule->getData()));
        if ($validation !== true) {
            return implode(' ', array_map('strval', (array)$validation));
        }

        $this->catalogRules->getRepository()->save($rule);

        return null;
    }

    /**
     * The repository hands out the same rule instance for the rest of the request, so a change that
     * is not saved must not stay on it: a later write to the rule would save it unapproved.
     *
     * @param array<string, mixed> $params
     * @return array{data: array<string, mixed>, conditions: array<string, mixed>|null}
     */
    private function snapshot(array $params, Rule $rule): array
    {
        $conditions = $this->conditionReview->hasConditions($params) ? $rule->getConditions()->asArray() : null;

        return ['data' => $rule->getData(), 'conditions' => $conditions];
    }

    /**
     * @param array{data: array<string, mixed>, conditions: array<string, mixed>|null} $snapshot
     */
    private function restore(Rule $rule, array $snapshot): void
    {
        $rule->setData($snapshot['data']);
        if ($snapshot['conditions'] !== null) {
            $rule->getConditions()->setConditions([])->loadArray($snapshot['conditions']);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>|null
     */
    private function findInputRefusal(array $params): ?array
    {
        $discountType = $params['discount_type'] ?? null;

        return match (true) {
            (int)($params['rule_id'] ?? 0) === 0 => ['error' => 'rule_id parameter is required for update_rule'],
            $this->isGiven($params, 'conditions_match') && !$this->conditionReview->hasConditions($params) => [
                'error' => 'conditions_match only changes together with conditions: call get_rule and pass the '
                    . 'conditions that should remain.',
            ],
            $this->givenFields($params) === [] => [
                'error' => 'Nothing to change: pass at least one of ' . implode(', ', self::CHANGEABLE) . '.',
            ],
            $discountType !== null && !isset(RuleFields::DISCOUNT_TYPES[$discountType]) => [
                'error' => 'Invalid discount_type. Use: percent, fixed, to_percent, or to_fixed',
            ],
            $this->isGiven($params, 'discount_amount') && (float)$params['discount_amount'] <= 0 => [
                'error' => 'discount_amount must be greater than 0',
            ],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function applyChanges(array $params, Rule $rule): void
    {
        foreach (self::SIMPLE_FIELDS as $param => $field) {
            if ($this->isGiven($params, $param)) {
                $rule->setData($field, $this->simpleValue($param, $params[$param]));
            }
        }
        if ($this->isGiven($params, 'discount_type')) {
            $rule->setData('simple_action', RuleFields::DISCOUNT_TYPES[$params['discount_type']]);
        }
        if ($this->isGiven($params, 'discount_amount')) {
            $rule->setData('discount_amount', (float)$params['discount_amount']);
        }
        if ($this->isGiven($params, 'website_ids')) {
            $rule->setData('website_ids', RuleIds::fromInput($params['website_ids']));
        }
        if ($this->isGiven($params, 'customer_group_ids')) {
            $rule->setData('customer_group_ids', RuleIds::fromInput($params['customer_group_ids']));
        }
        if ($this->isGiven($params, 'stop_further_rules')) {
            $rule->setData('stop_rules_processing', (int)$this->toBool($params['stop_further_rules']));
        }
        if ($this->isGiven($params, 'is_active')) {
            $rule->setData('is_active', (int)$this->toBool($params['is_active']));
        }
        if ($this->conditionReview->hasConditions($params)) {
            $rule->getConditions()->setConditions([])->loadArray($this->conditionReview->build($params)->toMagentoArray());
        }
    }

    private function simpleValue(string $param, mixed $value): mixed
    {
        return match ($param) {
            'priority' => (int)$value,
            'from_date', 'to_date' => trim((string)$value) === '' ? null : trim((string)$value),
            default => (string)$value,
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private function describeFieldChanges(array $params, Rule $rule): array
    {
        $lines = array_map(
            fn (string $param, string $field): string => $this->isGiven($params, $param)
                ? self::FIELD_LABELS[$param] . ': ' . $this->shown($rule->getData($field)) . ' -> '
                    . $this->shown($this->simpleValue($param, $params[$param])) . '.'
                : '',
            array_keys(self::SIMPLE_FIELDS),
            self::SIMPLE_FIELDS
        );
        if ($this->isGiven($params, 'stop_further_rules')) {
            $lines[] = $this->toBool($params['stop_further_rules'])
                ? 'Stops the rules after it from applying.'
                : 'Lets the rules after it apply as well.';
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function describeDiscountChange(array $params, Rule $rule): string
    {
        if (!$this->isGiven($params, 'discount_type') && !$this->isGiven($params, 'discount_amount')) {
            return '';
        }

        $currentType = (string)array_search($rule->getData('simple_action'), RuleFields::DISCOUNT_TYPES, true);
        $currentAmount = (float)$rule->getData('discount_amount');

        return 'Discount: ' . $this->describeDiscount($currentType, $currentAmount) . ' -> '
            . $this->describeDiscount(
                (string)($params['discount_type'] ?? $currentType),
                (float)($params['discount_amount'] ?? $currentAmount)
            ) . '.';
    }

    private function describeDiscount(string $type, float $amount): string
    {
        $formatted = $this->cautions->formatAmount($amount);

        return match ($type) {
            'percent' => $formatted . '% off',
            'fixed' => $formatted . ' off',
            'to_percent' => 'price set to ' . $formatted . '% of the original',
            'to_fixed' => 'price set to ' . $formatted,
            default => $type,
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private function describeScopeChanges(array $params, int $adminUserId): array
    {
        if (!$this->isGiven($params, 'website_ids') && !$this->isGiven($params, 'customer_group_ids')) {
            return [];
        }

        [$websites, $groups] = $this->cautions->describeScope(
            RuleIds::fromInput($params['website_ids'] ?? null),
            RuleIds::fromInput($params['customer_group_ids'] ?? null),
            '',
            '',
            $adminUserId
        );

        return [
            $this->isGiven($params, 'website_ids') ? 'From now on: ' . lcfirst($websites) : '',
            $this->isGiven($params, 'customer_group_ids') ? 'From now on: ' . lcfirst($groups) : '',
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private function describeConditionChange(array $params, Rule $rule): array
    {
        if (!$this->conditionReview->hasConditions($params)) {
            return [];
        }

        $lines = $this->conditionReview->describe($params, $this->websiteIdsFor($params, $rule));

        return $lines === []
            ? ['Removes every condition: the rule reaches every product.']
            : ['Replaces the rule\'s conditions.', ...$lines];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function describeActiveChange(array $params, Rule $rule): string
    {
        if (!$this->isGiven($params, 'is_active')) {
            return '';
        }

        $isActive = $this->toBool($params['is_active']);

        return match (true) {
            $isActive === (bool)$rule->getData('is_active') => '',
            $isActive => 'Switches the rule back on.',
            default => 'Switches the rule off.',
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return int[]
     */
    private function websiteIdsFor(array $params, Rule $rule): array
    {
        return RuleIds::fromInput($params['website_ids'] ?? null)
            ?? RuleIds::fromInput($rule->getWebsiteIds())
            ?? [];
    }

    /**
     * @param array<string, mixed> $params
     * @throws NoSuchEntityException
     */
    private function loadRule(array $params): Rule
    {
        $rule = $this->catalogRules->getRepository()->get((int)($params['rule_id'] ?? 0));
        if (!$rule instanceof Rule) {
            throw new NoSuchEntityException(__('The catalog price rule could not be loaded.'));
        }

        return $rule;
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private function givenFields(array $params): array
    {
        return array_values(array_filter(self::CHANGEABLE, fn (string $field): bool => $this->isGiven($params, $field)));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function isGiven(array $params, string $field): bool
    {
        return array_key_exists($field, $params) && $params[$field] !== null;
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool)$value;
    }

    private function shown(mixed $value): string
    {
        return $value === null || $value === '' ? 'none' : (string)$value;
    }

    private function toolName(): string
    {
        return 'catalog_price_rules ' . $this->getName();
    }
}

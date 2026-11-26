<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionVocabulary as Vocabulary;
use MagoAssistant\Mago\Service\Skills\Marketing\DiscountRuleCautions;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

/**
 * Changes an existing cart price rule: its conditions and actions, the discount settings, name,
 * description and dates. Websites, customer groups, the on/off switch and coupons stay as they are.
 *
 * The rule is read and written back whole, the way the REST API takes it. A given conditions or
 * actions tree replaces that whole tree; an empty "rules" list clears it. A date is removed with an
 * empty string, because the REST API keeps the stored date when it gets null.
 */
class UpdateRuleAction implements CautionedActionInterface, ValidatingActionInterface
{
    private const SIMPLE_ACTION_UNKNOWN = '';
    private const DISCOUNT_FIELDS = ['discount_amount', 'discount_qty', 'discount_step'];
    private const FREE_SHIPPING_FOR_MATCHING_ITEMS = '1';
    private const NO_FREE_SHIPPING = '0';
    private const TEXT_FIELDS = ['name', 'description'];
    private const DATE_FIELDS = ['from_date', 'to_date'];
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';
    private const COUNT_FIELDS = ['discount_qty', 'discount_step'];
    private const CHANGEABLE_FIELDS = [
        'name', 'description', 'from_date', 'to_date', 'discount_amount', 'discount_qty', 'discount_step',
        'free_shipping', 'conditions', 'actions',
    ];

    public function __construct(
        private readonly InternalApiClientInterface $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly DiscountRuleCautions $cautions,
        private readonly RuleConditionValidator $conditionValidator,
        private readonly RuleConditionBuilder $conditionBuilder,
        private readonly RuleConditionReader $conditionReader,
        private readonly RuleConditionDescriber $conditionDescriber,
        private readonly RuleDiscountValidator $discountValidator
    ) {
    }

    public function getName(): string
    {
        return 'update_rule';
    }

    public function getDescription(): string
    {
        return 'Change an existing cart price rule: conditions, actions, discount amount, discount_qty, '
            . 'discount_step, free shipping, name, description or dates. Only the parameters given change';
    }

    public function getParameterSchema(): array
    {
        return [
            'rule_id' => [
                'type' => 'integer',
                'description' => 'The cart price rule ID',
            ],
            'name' => ['type' => 'string', 'description' => 'New name for the rule'],
            'description' => [
                'type' => 'string',
                'description' => 'New internal description, shown only in the admin; an empty string clears it',
            ],
            'discount_amount' => [
                'type' => 'number',
                'description' => 'New discount amount, 0 or greater: a percentage (at most 100) for a percent rule, '
                    . 'the items given free for buy_x_get_y',
            ],
            'discount_qty' => [
                'type' => 'integer',
                'description' => 'Maximum number of items the discount applies to per cart (0: no limit)',
            ],
            'discount_step' => [
                'type' => 'integer',
                'description' => 'For buy_x_get_y: the items bought per free set. Otherwise: the discount applies '
                    . 'per this many items (0: every item)',
            ],
            'free_shipping' => [
                'type' => 'boolean',
                'description' => 'For update_rule: true gives the matching items free shipping, false takes it away',
            ],
            'from_date' => [
                'type' => 'string',
                'description' => 'New start date, YYYY-MM-DD; an empty string removes the start date',
            ],
            'to_date' => [
                'type' => 'string',
                'description' => 'New end date, YYYY-MM-DD; an empty string removes the end date',
            ],
            'conditions' => Vocabulary::conditionsSchema(),
            'actions' => Vocabulary::actionsSchema(),
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
            'success' => [PiiClass::PUBLIC],
            'rule_id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'changed' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'Read the rule with get_rule before update_rule. conditions and actions replace the whole tree, '
            . 'so send the complete new tree: the rules to keep plus the change. An empty "rules" list clears it, '
            . 'and an empty from_date or to_date removes that date. '
            . 'The discount type, websites, customer groups, the on/off switch and coupons cannot be changed with '
            . 'update_rule: to go from a percentage to a fixed amount, say so and offer to create a new rule.';
    }

    /**
     * What the rule looks like now and after the change, one line per changed part.
     */
    public function getCautions(array $params, int $adminUserId): array
    {
        $rule = $this->apiClient->get('salesRules/' . (int)($params['rule_id'] ?? 0), [], $adminUserId);
        if (isset($rule['error'])) {
            return ['Changes cart price rule ' . (int)($params['rule_id'] ?? 0) . ', which could not be read: '
                . $rule['error']];
        }

        return [
            'Changes cart price rule ' . (int)($rule['rule_id'] ?? 0) . ' "' . ($rule['name'] ?? '') . '":',
            ...$this->describeFieldChanges($params, $rule),
            ...$this->describeTreeChange(
                'Conditions',
                $this->tree($params, 'conditions'),
                $this->conditionReader->readConditions($rule['condition'] ?? null),
                $this->conditionDescriber->describeConditions(...),
                $adminUserId
            ),
            ...$this->describeTreeChange(
                'Actions',
                $this->tree($params, 'actions'),
                $this->conditionReader->readActions($rule['action_condition'] ?? null),
                $this->conditionDescriber->describeActions(...),
                $adminUserId
            ),
            ...$this->conditionDescriber->describeSharedCategoryNames(
                $this->tree($params, 'conditions'),
                $this->tree($params, 'actions'),
                $adminUserId
            ),
        ];
    }

    public function findRefusal(array $params): ?array
    {
        $error = $this->findInputError($params);

        return $error === null ? null : ['error' => $error];
    }

    public function execute(array $params, int $adminUserId): array
    {
        $refusal = $this->findRefusal($params);
        if ($refusal !== null) {
            return $refusal;
        }
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $ruleId = (int)$params['rule_id'];
        $rule = $this->apiClient->get('salesRules/' . $ruleId, [], $adminUserId);
        if (isset($rule['error'])) {
            return $rule;
        }

        $conditions = $this->tree($params, 'conditions');
        $actions = $this->tree($params, 'actions');
        $error = $this->findRuleError($params, $rule)
            ?? $this->conditionValidator->findTargetError($conditions, $actions, $adminUserId);
        if ($error !== null) {
            return ['error' => $error];
        }

        $result = $this->apiClient->put('salesRules/' . $ruleId, [
            'rule' => $this->updatedRule($params, $rule, $conditions, $actions),
        ], $adminUserId);
        if (isset($result['error'])) {
            return ['error' => 'Failed to update rule: ' . $result['error']];
        }

        return [
            'success' => true,
            'rule_id' => $ruleId,
            'name' => (string)($result['name'] ?? $rule['name'] ?? ''),
            'changed' => $this->changedFields($params),
            'admin_url' => $this->adminUrl($ruleId),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function findInputError(array $params): ?string
    {
        return match (true) {
            (int)($params['rule_id'] ?? 0) < 1 => 'rule_id parameter is required for update_rule',
            $this->changedFields($params) === [] => 'update_rule needs at least one of: '
                . implode(', ', self::CHANGEABLE_FIELDS),
            isset($params['name']) && trim((string)$params['name']) === '' => 'name cannot be empty',
            array_filter(
                self::DATE_FIELDS,
                static fn (string $field): bool => isset($params[$field]) && (string)$params[$field] !== ''
                    && preg_match(self::DATE_PATTERN, (string)$params[$field]) !== 1
            ) !== [] => 'from_date and to_date must be YYYY-MM-DD, or an empty string to remove the date',
            default => $this->discountValidator->findError(
                self::SIMPLE_ACTION_UNKNOWN,
                $params['discount_amount'] ?? 0,
                $params['discount_step'] ?? 0,
                $params['discount_qty'] ?? 0
            )
                ?? (isset($params['conditions'])
                    ? $this->conditionValidator->findConditionsError($params['conditions'])
                    : null)
                ?? (isset($params['actions']) ? $this->conditionValidator->findActionsError($params['actions']) : null),
        };
    }

    /**
     * Checks that need the rule as it is: whether Mago can read the tree it would replace, and the
     * discount settings as they will be saved, measured against the rule's discount type. Replacing
     * a tree Mago cannot read would drop parts of it nobody saw on the card. The discount is only
     * checked when the call changes it, so a rule saved in the admin with odd settings can still be
     * renamed.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $rule
     */
    private function findRuleError(array $params, array $rule): ?string
    {
        $unreadable = $this->unreadableTreeName($params, $rule);
        if ($unreadable !== null) {
            return 'This rule has ' . $unreadable . ' that Mago cannot read, so it will not replace them. Change '
                . 'them in the admin: ' . $this->adminUrl((int)($rule['rule_id'] ?? 0));
        }
        $isDiscountChanged = array_intersect_key(
            array_filter($params, static fn (mixed $value): bool => $value !== null),
            array_flip(self::DISCOUNT_FIELDS)
        ) !== [];

        return $isDiscountChanged ? $this->discountValidator->findError(
            (string)($rule['simple_action'] ?? ''),
            $params['discount_amount'] ?? $rule['discount_amount'] ?? 0,
            $params['discount_step'] ?? $rule['discount_step'] ?? 0,
            $params['discount_qty'] ?? $rule['discount_qty'] ?? 0
        ) : null;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $rule
     */
    private function unreadableTreeName(array $params, array $rule): ?string
    {
        return match (true) {
            isset($params['conditions'])
                && $this->conditionReader->readConditions($rule['condition'] ?? null) === null => 'conditions',
            isset($params['actions'])
                && $this->conditionReader->readActions($rule['action_condition'] ?? null) === null => 'actions',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $rule
     * @param array<string, mixed>|null $conditions
     * @param array<string, mixed>|null $actions
     * @return array<string, mixed>
     */
    private function updatedRule(array $params, array $rule, ?array $conditions, ?array $actions): array
    {
        $params = array_filter($params, static fn (mixed $value): bool => $value !== null);

        return array_merge(
            $rule,
            array_map(
                static fn (mixed $value): string => trim((string)$value),
                array_intersect_key($params, array_flip([...self::TEXT_FIELDS, ...self::DATE_FIELDS]))
            ),
            isset($params['discount_amount']) ? ['discount_amount' => (float)$params['discount_amount']] : [],
            array_map('intval', array_intersect_key($params, array_flip(self::COUNT_FIELDS))),
            isset($params['free_shipping']) ? ['simple_free_shipping' => $this->freeShippingValue($params)] : [],
            $conditions === null ? [] : ['condition' => $this->conditionBuilder->buildConditions($conditions)],
            $actions === null ? [] : ['action_condition' => $this->conditionBuilder->buildActions($actions)]
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function freeShippingValue(array $params): string
    {
        return filter_var($params['free_shipping'], FILTER_VALIDATE_BOOLEAN)
            ? self::FREE_SHIPPING_FOR_MATCHING_ITEMS
            : self::NO_FREE_SHIPPING;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $rule
     * @return string[]
     */
    private function describeFieldChanges(array $params, array $rule): array
    {
        $changes = [
            'name' => ['Name', $rule['name'] ?? '', $params['name'] ?? null],
            'description' => ['Description', $rule['description'] ?? '', $params['description'] ?? null],
            'discount_amount' => [
                'Discount amount',
                $this->cautions->formatAmount((float)($rule['discount_amount'] ?? 0)),
                isset($params['discount_amount'])
                    ? $this->cautions->formatAmount((float)$params['discount_amount'])
                    : null,
            ],
            'discount_qty' => ['Maximum items discounted (0: no limit)', (int)($rule['discount_qty'] ?? 0),
                $params['discount_qty'] ?? null],
            'discount_step' => ['Discount step', (int)($rule['discount_step'] ?? 0), $params['discount_step'] ?? null],
            'free_shipping' => [
                'Free shipping',
                in_array((string)($rule['simple_free_shipping'] ?? ''), ['', self::NO_FREE_SHIPPING], true) ? 'no' : 'yes',
                isset($params['free_shipping'])
                    ? ($this->freeShippingValue($params) === self::NO_FREE_SHIPPING ? 'no' : 'yes')
                    : null,
            ],
            'from_date' => ['Start date', $rule['from_date'] ?? '', $params['from_date'] ?? null],
            'to_date' => ['End date', $rule['to_date'] ?? '', $params['to_date'] ?? null],
        ];

        return array_values(array_map(
            static fn (array $change): string => $change[0] . ': ' . self::shown($change[1]) . ' -> '
                . self::shown($change[2]) . '.',
            array_filter($changes, static fn (array $change): bool => $change[2] !== null)
        ));
    }

    private static function shown(mixed $value): string
    {
        $text = trim(is_scalar($value) ? (string)$value : '');

        return $text === '' ? '(none)' : '"' . $text . '"';
    }

    /**
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>|null $before null when Mago cannot read the current tree
     * @param callable(array<string, mixed>, int): string $describe
     * @return string[]
     */
    private function describeTreeChange(
        string $label,
        ?array $after,
        ?array $before,
        callable $describe,
        int $adminUserId
    ): array {
        if ($after === null) {
            return [];
        }

        return [
            $label . ' now: ' . ($before === null
                ? 'not readable by Mago (this change will be refused).'
                : $describe($before, $adminUserId)),
            $label . ' after: ' . $describe($after, $adminUserId),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function changedFields(array $params): array
    {
        return array_values(array_filter(
            self::CHANGEABLE_FIELDS,
            static fn (string $field): bool => isset($params[$field])
        ));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function tree(array $params, string $key): ?array
    {
        return isset($params[$key]) ? Vocabulary::treeFromInput($params[$key]) : null;
    }

    private function adminUrl(int $ruleId): string
    {
        return $this->secureAdminUrl->getUrl('sales_rule/promo_quote/edit', ['id' => $ruleId]);
    }
}

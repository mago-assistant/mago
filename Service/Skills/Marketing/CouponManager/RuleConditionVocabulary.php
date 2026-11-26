<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

/**
 * The words a model uses for cart rule conditions and actions, and the Magento names behind them.
 *
 * The Magento condition classes live here as constants and nowhere else: the REST API saves any
 * class name it gets, and an unknown one silently wipes the rule's conditions, so a class name
 * never comes from tool input.
 */
class RuleConditionVocabulary
{
    public const COMBINE_CLASS = 'Magento\\SalesRule\\Model\\Rule\\Condition\\Combine';
    public const ADDRESS_CLASS = 'Magento\\SalesRule\\Model\\Rule\\Condition\\Address';
    public const PRODUCT_FOUND_CLASS = 'Magento\\SalesRule\\Model\\Rule\\Condition\\Product\\Found';
    public const PRODUCT_COMBINE_CLASS = 'Magento\\SalesRule\\Model\\Rule\\Condition\\Product\\Combine';
    public const PRODUCT_CLASS = 'Magento\\SalesRule\\Model\\Rule\\Condition\\Product';

    public const MATCH_ALL = 'all';
    public const MATCH_ANY = 'any';
    public const MATCHES = [self::MATCH_ALL, self::MATCH_ANY];

    /**
     * A group of product rules inside a tree: {"group": {"match": ..., "is": ..., "rules": [...]}}.
     * "is": false is the admin's "If ALL/ANY of these conditions are FALSE". Groups nest one level.
     */
    public const GROUP = 'group';
    public const IS = 'is';

    public const FIELD_SHIPPING_COUNTRY = 'shipping_country';
    public const FIELD_PRODUCT_IN_CART = 'product_in_cart';
    public const PRESENCE_FOUND = 'found';
    public const PRESENCE_NOT_FOUND = 'not_found';

    public const ATTRIBUTE_SKU = 'sku';
    public const ATTRIBUTE_CATEGORY_IDS = 'category_ids';
    public const ADDRESS_COUNTRY = 'country_id';

    public const OPERATOR_IS = 'is';
    public const OPERATOR_IS_NOT = 'is_not';
    public const OPERATOR_IN = 'in';
    public const OPERATOR_NOT_IN = 'not_in';

    public const MAX_RULES_PER_TREE = 20;
    public const MAX_VALUES_PER_RULE = 100;

    /**
     * Cart total fields by tool name, with the Magento address attribute and how the card says it
     */
    public const TOTAL_FIELDS = [
        'subtotal' => ['attribute' => 'base_subtotal', 'label' => 'subtotal'],
        'subtotal_incl_tax' => ['attribute' => 'base_subtotal_total_incl_tax', 'label' => 'subtotal incl. tax'],
        'subtotal_after_discount' => [
            'attribute' => 'base_subtotal_with_discount',
            'label' => 'subtotal after discounts',
        ],
        'total_qty' => ['attribute' => 'total_qty', 'label' => 'total item quantity'],
    ];

    /**
     * Comparison operators allowed on a cart total, with their words on the card
     */
    public const NUMERIC_OPERATORS = [
        '>=' => 'is at least',
        '>' => 'is more than',
        '<=' => 'is at most',
        '<' => 'is less than',
        '==' => 'is exactly',
        '!=' => 'is not',
    ];

    /**
     * Product attribute operators by tool name, with the Magento operator
     */
    public const PRODUCT_OPERATORS = [
        self::OPERATOR_IS => '==',
        self::OPERATOR_IS_NOT => '!=',
        self::OPERATOR_IN => '()',
        self::OPERATOR_NOT_IN => '!()',
    ];

    public const LIST_OPERATORS = [self::OPERATOR_IN, self::OPERATOR_NOT_IN];

    /**
     * @return array<string, mixed>
     */
    public static function conditionsSchema(): array
    {
        return self::treeSchema(
            'When the rule applies, checked on the cart. Omit to apply to every cart (create) or to keep the '
            . 'current conditions (update). {"match": "all"|"any", "is": true|false, "rules": [...]}; "is": false '
            . 'means the rules have to FAIL ("all" + false: none holds; "any" + false: at least one fails), '
            . 'default true. Each rule one of: '
            . '{"field": "subtotal"|"subtotal_incl_tax"|"subtotal_after_discount"|"total_qty", '
            . '"operator": ">="|">"|"<="|"<"|"=="|"!=", "value": number} (amounts in base currency); '
            . '{"field": "shipping_country", "operator": "in"|"not_in", "value": ["NL", "BE"]}; '
            . '{"field": "product_in_cart", "presence": "found"|"not_found", "match": "all"|"any", '
            . '"rules": [product rules]}. ' . self::productRuleDescription()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function actionsSchema(): array
    {
        return self::treeSchema(
            'Which cart items get the discount. Omit to discount every item (create) or to keep the current '
            . 'actions (update). {"match": "all"|"any", "is": true|false, "rules": [product rules]}, "is" as for '
            . 'conditions. ' . self::productRuleDescription()
        );
    }

    /**
     * A tree as the model sent it: an object, or the same object JSON-encoded, which some models
     * produce for nested parameters. Anything else is null.
     *
     * @return array<string, mixed>|null
     */
    public static function treeFromInput(mixed $tree): ?array
    {
        $decoded = is_string($tree) ? json_decode($tree, true) : $tree;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Every product rule in the trees: inside product_in_cart conditions and in the actions
     *
     * @param array<string, mixed>|null $conditions
     * @param array<string, mixed>|null $actions
     * @return list<array<string, mixed>>
     */
    public static function productRulesIn(?array $conditions, ?array $actions): array
    {
        $productInCart = array_filter(
            self::rulesOf($conditions),
            static fn (array $rule): bool => ($rule['field'] ?? '') === self::FIELD_PRODUCT_IN_CART
        );

        $productRules = [
            ...array_merge([], ...array_map(self::rulesOf(...), array_values($productInCart))),
            ...self::rulesOf($actions),
        ];

        return array_merge([], ...array_map(
            static fn (array $rule): array => self::isGroup($rule) ? self::rulesOf($rule[self::GROUP]) : [$rule],
            $productRules
        ));
    }

    /**
     * @param array<string, mixed> $rule
     */
    public static function isGroup(array $rule): bool
    {
        return array_key_exists(self::GROUP, $rule);
    }

    /**
     * Whether the rules of a tree or group have to hold (true, the default) or have to fail
     *
     * @param array<string, mixed>|null $tree
     */
    public static function isAffirmed(?array $tree): bool
    {
        return ($tree[self::IS] ?? true) !== false;
    }

    /**
     * @param list<array<string, mixed>> $productRules
     * @return list<int>
     */
    public static function categoryIdsIn(array $productRules): array
    {
        return array_values(array_unique(array_map('intval', array_merge([], ...array_map(
            static fn (array $rule): array => self::valuesFromInput($rule['value'] ?? []),
            array_values(array_filter(
                $productRules,
                static fn (array $rule): bool => ($rule['attribute'] ?? '') === self::ATTRIBUTE_CATEGORY_IDS
            ))
        )))));
    }

    /**
     * @param array<string, mixed>|null $tree
     * @return list<array<string, mixed>>
     */
    public static function rulesOf(?array $tree): array
    {
        return array_values(array_filter((array)($tree['rules'] ?? []), 'is_array'));
    }

    public static function totalFieldForAttribute(string $attribute): ?string
    {
        $fields = array_keys(array_filter(
            self::TOTAL_FIELDS,
            static fn (array $field): bool => $field['attribute'] === $attribute
        ));

        return $fields[0] ?? null;
    }

    public static function productOperatorForMagento(string $magentoOperator): ?string
    {
        $operator = array_search($magentoOperator, self::PRODUCT_OPERATORS, true);

        return is_string($operator) ? $operator : null;
    }

    public static function isListOperator(string $operator): bool
    {
        return in_array($operator, self::LIST_OPERATORS, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function treeSchema(string $description): array
    {
        return [
            'type' => 'object',
            'description' => $description,
            'properties' => [
                'match' => ['type' => 'string', 'enum' => self::MATCHES],
                'is' => ['type' => 'boolean'],
                'rules' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
        ];
    }

    private static function productRuleDescription(): string
    {
        return 'A product rule is {"attribute": "sku"|"category_ids"|<promo attribute code>, '
            . '"operator": "is"|"is_not"|"in"|"not_in", "value": one value or a list}, or a group of product '
            . 'rules {"group": {"match": "all"|"any", "is": true|false, "rules": [product rules]}} (groups do not '
            . 'nest). category_ids takes category ids with "in"/"not_in", or one id with "is"/"is_not"; a '
            . 'dropdown or yes/no attribute takes one option id with '
            . '"is"/"is_not"; a multiple select attribute takes option ids with "in"/"not_in". Look ids up '
            . 'with find_rule_targets.';
    }

    /**
     * @return list<string>
     */
    public static function valuesFromInput(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', (string)(is_scalar($value) ? $value : ''));

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): string => trim(is_scalar($item) ? (string)$item : ''),
                $list
            ),
            static fn (string $item): bool => $item !== ''
        ));
    }
}

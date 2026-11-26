<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

/**
 * The rule fields create_rule and update_rule share: their parameter schema and the discount
 * types by the name the model uses and the simple_action Magento saves.
 */
class RuleFields
{
    public const DISCOUNT_TYPES = [
        'percent' => 'by_percent',
        'fixed' => 'by_fixed',
        'to_percent' => 'to_percent',
        'to_fixed' => 'to_fixed',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array
    {
        return [
            'name' => [
                'type' => 'string',
                'description' => 'Rule name (required)',
            ],
            'discount_type' => [
                'type' => 'string',
                'description' => 'Discount type: "percent", "fixed", "to_percent", or "to_fixed"',
            ],
            'discount_amount' => [
                'type' => 'number',
                'description' => 'Discount amount, greater than 0. For "percent" and "to_percent" '
                    . 'this is a percentage and must be at most 100.',
            ],
            'from_date' => [
                'type' => 'string',
                'description' => 'Start date (YYYY-MM-DD format, optional)',
            ],
            'to_date' => [
                'type' => 'string',
                'description' => 'End date (YYYY-MM-DD format, optional)',
            ],
            'website_ids' => [
                'type' => 'array',
                'description' => 'Website IDs to apply rule to (default: all websites)',
            ],
            'customer_group_ids' => [
                'type' => 'array',
                'description' => 'Customer group IDs (default: all groups)',
            ],
            'category_ids' => [
                'type' => 'array',
                'description' => 'Category IDs to restrict the rule to (optional). Always has to hold, '
                    . 'also when conditions_match is "any".',
            ],
            'conditions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'attribute' => ['type' => 'string'],
                        'operator' => ['type' => 'string', 'enum' => ['is', 'is_not']],
                        'values' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['attribute', 'values'],
                ],
                'description' => 'Product conditions (optional, at most ' . RuleConditions::MAX_CONDITIONS
                    . '). attribute is a product attribute code or admin label, "sku", or "category" '
                    . '(values are category IDs). values are option labels as the admin sees them, SKUs, '
                    . 'or yes/no; several values mean "is one of". operator is "is" (default) or "is_not".',
            ],
            'conditions_match' => [
                'type' => 'string',
                'enum' => [RuleConditionSet::MATCH_ALL, RuleConditionSet::MATCH_ANY],
                'description' => 'Whether all conditions must hold or at least one (default: all)',
            ],
            'priority' => [
                'type' => 'integer',
                'description' => 'Rule priority / sort order (default: 0)',
            ],
            'stop_further_rules' => [
                'type' => 'boolean',
                'description' => 'Stop processing subsequent rules (default: false)',
            ],
        ];
    }
}

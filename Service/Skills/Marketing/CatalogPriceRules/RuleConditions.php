<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use MagoAssistant\Mago\Service\Skills\Marketing\RuleIds;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

/**
 * Turns the conditions, conditions_match and category_ids a model passes to create_rule or
 * update_rule into the conditions Magento saves, refusing anything that would not save as asked.
 *
 * Attributes are found by code or admin label and must have "Use for Promo Rule Conditions" on;
 * option values are matched on their admin label. A name that fits more than one attribute or
 * option is refused with the candidates, so the model asks instead of guessing.
 */
class RuleConditions
{
    public const MAX_CONDITIONS = 10;
    public const MAX_VALUES = 200;

    private const OPERATOR_IS = 'is';
    private const OPERATOR_IS_NOT = 'is_not';
    private const CATEGORY_NAMES = ['category', 'categories', 'category_ids'];
    private const CATEGORY_ATTRIBUTE = 'category_ids';
    private const SUPPORTED_INPUTS = ['select', 'multiselect', 'boolean', 'text', 'textarea', 'price', 'weight'];
    private const BOOLEAN_VALUES = ['yes' => '1', 'true' => '1', '1' => '1', 'no' => '0', 'false' => '0', '0' => '0'];
    private const LIST_SEPARATOR_PATTERN = '/[,;]/';
    private const SHOWN_OPTIONS = 25;

    public function __construct(
        private readonly PromoAttributes $promoAttributes,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hasConditions(array $params): bool
    {
        return ($params['conditions'] ?? null) !== null || ($params['category_ids'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $params
     * @throws InvalidRuleConditionException
     */
    public function build(array $params): RuleConditionSet
    {
        $conditions = $this->conditionList($params['conditions'] ?? null);
        $categoryIds = RuleIds::fromInput($params['category_ids'] ?? null) ?? [];

        return new RuleConditionSet(
            $this->match($params['conditions_match'] ?? null),
            array_map($this->resolve(...), $conditions),
            $categoryIds === [] ? null : $this->categoryCondition(self::OPERATOR_IS, array_map('strval', $categoryIds))
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function conditionList(mixed $conditions): array
    {
        $list = is_string($conditions) ? $this->decode($conditions) : $conditions;
        if ($list === null || $list === []) {
            return [];
        }
        if (!is_array($list) || !array_is_list($list)) {
            throw new InvalidRuleConditionException(
                'conditions must be a list of {attribute, operator, values} objects.'
            );
        }
        if (count($list) > self::MAX_CONDITIONS) {
            throw new InvalidRuleConditionException(
                'A rule takes at most ' . self::MAX_CONDITIONS . ' conditions; ' . count($list) . ' were given.'
            );
        }

        return array_map(
            static fn (mixed $condition): array => is_array($condition)
                ? $condition
                : throw new InvalidRuleConditionException(
                    'Each condition must be an object with attribute, operator and values.'
                ),
            $list
        );
    }

    /**
     * A string that is not a JSON list is refused rather than read as no conditions, which would
     * save the rule for the whole catalog
     */
    private function decode(string $conditions): mixed
    {
        if (trim($conditions) === '') {
            return null;
        }

        $list = json_decode($conditions, true);
        if (!is_array($list)) {
            throw new InvalidRuleConditionException(
                'conditions could not be read: pass a list of {attribute, operator, values} objects.'
            );
        }

        return $list;
    }

    private function match(mixed $match): string
    {
        $value = strtolower(trim((string)($match ?? RuleConditionSet::MATCH_ALL)));
        if ($value === '') {
            return RuleConditionSet::MATCH_ALL;
        }
        if (!in_array($value, [RuleConditionSet::MATCH_ALL, RuleConditionSet::MATCH_ANY], true)) {
            throw new InvalidRuleConditionException('conditions_match must be "all" or "any".');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function resolve(array $condition): RuleCondition
    {
        $name = trim((string)($condition['attribute'] ?? ''));
        if ($name === '') {
            throw new InvalidRuleConditionException('Each condition needs an attribute.');
        }
        $operator = $this->operator($condition['operator'] ?? null);
        $values = $this->values($condition['values'] ?? $condition['value'] ?? null, $name);

        if (in_array(strtolower($name), self::CATEGORY_NAMES, true)) {
            return $this->categoryCondition($operator, $values);
        }

        return $this->attributeCondition($this->attribute($name), $operator, $values);
    }

    private function operator(mixed $operator): string
    {
        $value = strtolower(str_replace([' ', '-'], '_', trim((string)($operator ?? self::OPERATOR_IS))));
        if ($value === '') {
            return self::OPERATOR_IS;
        }
        if (!in_array($value, [self::OPERATOR_IS, self::OPERATOR_IS_NOT], true)) {
            throw new InvalidRuleConditionException(
                'operator must be "is" or "is_not"; "' . $value . '" is not supported.'
            );
        }

        return $value;
    }

    /**
     * @return string[]
     */
    private function values(mixed $values, string $attributeName): array
    {
        $list = array_values(array_filter(
            array_map(
                static fn (mixed $value): string => trim((string)$value),
                array_filter(is_array($values) ? $values : [$values], 'is_scalar')
            ),
            static fn (string $value): bool => $value !== ''
        ));

        return match (true) {
            $list === [] => throw new InvalidRuleConditionException(
                'The condition on "' . $attributeName . '" needs at least one value.'
            ),
            count($list) > self::MAX_VALUES => throw new InvalidRuleConditionException(
                'A condition takes at most ' . self::MAX_VALUES . ' values; "' . $attributeName . '" has '
                    . count($list) . '.'
            ),
            default => $list,
        };
    }

    /**
     * @param string[] $values
     */
    private function categoryCondition(string $operator, array $values): RuleCondition
    {
        $invalid = array_filter($values, static fn (string $value): bool => !ctype_digit($value) || (int)$value === 0);
        if ($invalid !== []) {
            throw new InvalidRuleConditionException(
                'Category conditions take category IDs; look up the ID of: ' . implode(', ', $invalid) . '.'
            );
        }

        return new RuleCondition(
            self::CATEGORY_ATTRIBUTE,
            $operator === self::OPERATOR_IS ? '()' : '!()',
            implode(',', $values),
            ($operator === self::OPERATOR_IS ? 'the category is one of ' : 'the category is not one of ')
                . implode(', ', $values)
        );
    }

    private function attribute(string $name): PromoAttribute
    {
        $attributes = $this->promoAttributes->findByName($name);
        if ($attributes === []) {
            throw new InvalidRuleConditionException(
                'No product attribute has the code or label "' . $name . '". Ask the administrator which '
                    . 'attribute they mean.'
            );
        }
        if (count($attributes) > 1) {
            throw new InvalidRuleConditionException(
                'More than one product attribute is called "' . $name . '": '
                    . implode(', ', array_map(
                        static fn (PromoAttribute $attribute): string => $attribute->code . ' (' . $attribute->label . ')',
                        $attributes
                    ))
                    . '. Ask the administrator which one they mean and pass its code.'
            );
        }

        return $this->usableAttribute($attributes[0]);
    }

    private function usableAttribute(PromoAttribute $attribute): PromoAttribute
    {
        if (!$attribute->isUsedForPromoRules) {
            throw new InvalidRuleConditionException(
                'The attribute "' . $attribute->label . '" (' . $attribute->code . ') cannot be used in price '
                    . 'rule conditions until "Use for Promo Rule Conditions" is set to Yes on its Storefront '
                    . 'Properties tab: ' . $this->secureAdminUrl->getUrl(
                        'catalog/product_attribute/edit',
                        ['attribute_id' => $attribute->id]
                    )
                    . ' Tell the administrator; do not change the attribute yourself.'
            );
        }
        if (!in_array($attribute->input, self::SUPPORTED_INPUTS, true)) {
            throw new InvalidRuleConditionException(
                'Conditions on "' . $attribute->label . '" (' . $attribute->input . ') are not supported here; '
                    . 'set them up in the admin instead.'
            );
        }

        return $attribute;
    }

    /**
     * @param string[] $values
     */
    private function attributeCondition(PromoAttribute $attribute, string $operator, array $values): RuleCondition
    {
        $resolved = array_map(fn (string $value): string => $this->optionId($attribute, $value), $values);
        $isList = count($resolved) > 1 || $attribute->isMultiselect();
        if ($isList) {
            $this->assertNoSeparators($attribute, $resolved);
        }

        return new RuleCondition(
            $attribute->code,
            match (true) {
                $isList && $operator === self::OPERATOR_IS => '()',
                $isList => '!()',
                $operator === self::OPERATOR_IS => '==',
                default => '!=',
            },
            implode(',', $resolved),
            $attribute->label . ' ' . $this->operatorWords($operator, count($resolved) > 1) . ' '
                . implode(', ', array_map(fn (string $id): string => $this->readable($attribute, $id), $resolved))
        );
    }

    private function optionId(PromoAttribute $attribute, string $value): string
    {
        if ($attribute->isBoolean()) {
            return self::BOOLEAN_VALUES[strtolower($value)]
                ?? throw new InvalidRuleConditionException(
                    '"' . $attribute->label . '" is a yes/no attribute; "' . $value . '" is neither.'
                );
        }
        if (!$attribute->hasOptions()) {
            return $value;
        }

        $matches = array_keys(array_filter(
            $attribute->options,
            static fn (string $label): bool => strcasecmp($label, $value) === 0
        ));

        return match (true) {
            count($matches) === 1 => (string)$matches[0],
            count($matches) > 1 => throw new InvalidRuleConditionException(
                '"' . $attribute->label . '" has more than one option labelled "' . $value . '" (option IDs '
                    . implode(', ', $matches) . '). Ask the administrator which one they mean and pass its ID.'
            ),
            isset($attribute->options[$value]) => $value,
            default => throw new InvalidRuleConditionException(
                '"' . $attribute->label . '" has no option "' . $value . '". Its options are: '
                    . $this->optionList($attribute) . '. Ask the administrator which one they mean.'
            ),
        };
    }

    private function optionList(PromoAttribute $attribute): string
    {
        $labels = array_values($attribute->options);
        $shown = implode(', ', array_slice($labels, 0, self::SHOWN_OPTIONS));

        return count($labels) > self::SHOWN_OPTIONS
            ? $shown . ' and ' . (count($labels) - self::SHOWN_OPTIONS) . ' more'
            : $shown;
    }

    /**
     * Magento splits a list value on commas and semicolons, so a value holding one would turn into
     * two different values
     *
     * @param string[] $values
     */
    private function assertNoSeparators(PromoAttribute $attribute, array $values): void
    {
        $split = array_filter($values, static fn (string $value): bool => preg_match(self::LIST_SEPARATOR_PATTERN, $value) === 1);
        if ($split !== []) {
            throw new InvalidRuleConditionException(
                'Values in a list for "' . $attribute->label . '" cannot contain a comma or semicolon: '
                    . implode(' | ', $split) . '.'
            );
        }
    }

    private function operatorWords(string $operator, bool $isList): string
    {
        return match (true) {
            $isList && $operator === self::OPERATOR_IS => 'is one of',
            $isList => 'is not one of',
            $operator === self::OPERATOR_IS => 'is',
            default => 'is not',
        };
    }

    private function readable(PromoAttribute $attribute, string $value): string
    {
        if ($attribute->isBoolean()) {
            return $value === '1' ? 'Yes' : 'No';
        }

        return $attribute->options[$value] ?? $value;
    }
}

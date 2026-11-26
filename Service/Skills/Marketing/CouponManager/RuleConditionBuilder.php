<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionVocabulary as Vocabulary;

/**
 * Turns the conditions and actions a model wrote, already checked by RuleConditionValidator, into
 * the REST `condition` and `action_condition` trees of a cart price rule.
 */
class RuleConditionBuilder
{
    private const TRUE_VALUE = '1';
    private const FALSE_VALUE = '0';

    /**
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>
     */
    public function buildConditions(array $conditions): array
    {
        return $this->combine(
            Vocabulary::COMBINE_CLASS,
            $this->match($conditions),
            array_map($this->cartRule(...), $this->rules($conditions)),
            Vocabulary::isAffirmed($conditions)
        );
    }

    /**
     * @param array<string, mixed> $actions
     * @return array<string, mixed>
     */
    public function buildActions(array $actions): array
    {
        return $this->combine(
            Vocabulary::PRODUCT_COMBINE_CLASS,
            $this->match($actions),
            array_map($this->productRuleOrGroup(...), $this->rules($actions)),
            Vocabulary::isAffirmed($actions)
        );
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function productRuleOrGroup(array $rule): array
    {
        if (!Vocabulary::isGroup($rule)) {
            return $this->productRule($rule);
        }
        $group = (array)$rule[Vocabulary::GROUP];

        return $this->combine(
            Vocabulary::PRODUCT_COMBINE_CLASS,
            $this->match($group),
            array_map($this->productRule(...), $this->rules($group)),
            Vocabulary::isAffirmed($group)
        );
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function cartRule(array $rule): array
    {
        $field = (string)($rule['field'] ?? '');

        return match ($field) {
            Vocabulary::FIELD_SHIPPING_COUNTRY => $this->shippingCountry($rule),
            Vocabulary::FIELD_PRODUCT_IN_CART => $this->productInCart($rule),
            default => $this->address(
                Vocabulary::TOTAL_FIELDS[$field]['attribute'] ?? $field,
                (string)($rule['operator'] ?? ''),
                $this->number($rule['value'] ?? 0)
            ),
        };
    }

    /**
     * One country is one condition. Several become a nested combination, because Magento offers
     * only "is" and "is not" on the country: "is one of" is any of several "is", "is not one of"
     * is all of several "is not".
     *
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function shippingCountry(array $rule): array
    {
        $isExcluded = ($rule['operator'] ?? '') === Vocabulary::OPERATOR_NOT_IN;
        $operator = $isExcluded ? '!=' : '==';
        $countries = array_map('strtoupper', Vocabulary::valuesFromInput($rule['value'] ?? []));
        $conditions = array_map(
            fn (string $country): array => $this->address(Vocabulary::ADDRESS_COUNTRY, $operator, $country),
            $countries
        );

        return count($conditions) === 1
            ? $conditions[0]
            : $this->combine(
                Vocabulary::COMBINE_CLASS,
                $isExcluded ? Vocabulary::MATCH_ALL : Vocabulary::MATCH_ANY,
                $conditions,
                true
            );
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function productInCart(array $rule): array
    {
        return [
            'condition_type' => Vocabulary::PRODUCT_FOUND_CLASS,
            'aggregator_type' => $this->match($rule),
            'value' => ($rule['presence'] ?? '') === Vocabulary::PRESENCE_NOT_FOUND
                ? self::FALSE_VALUE
                : self::TRUE_VALUE,
            'conditions' => array_map($this->productRuleOrGroup(...), $this->rules($rule)),
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function productRule(array $rule): array
    {
        $operator = (string)($rule['operator'] ?? '');
        $values = Vocabulary::valuesFromInput($rule['value'] ?? []);

        return [
            'condition_type' => Vocabulary::PRODUCT_CLASS,
            'attribute_name' => (string)($rule['attribute'] ?? ''),
            'operator' => Vocabulary::PRODUCT_OPERATORS[$operator] ?? '==',
            'value' => Vocabulary::isListOperator($operator) ? implode(',', $values) : ($values[0] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function address(string $attribute, string $operator, string $value): array
    {
        return [
            'condition_type' => Vocabulary::ADDRESS_CLASS,
            'attribute_name' => $attribute,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    /**
     * @param list<array<string, mixed>> $conditions
     * @return array<string, mixed>
     */
    private function combine(string $class, string $match, array $conditions, bool $isAffirmed): array
    {
        return [
            'condition_type' => $class,
            'aggregator_type' => $match,
            'value' => $isAffirmed ? self::TRUE_VALUE : self::FALSE_VALUE,
            'conditions' => $conditions,
        ];
    }

    /**
     * @param array<string, mixed> $tree
     */
    private function match(array $tree): string
    {
        return ($tree['match'] ?? '') === Vocabulary::MATCH_ANY ? Vocabulary::MATCH_ANY : Vocabulary::MATCH_ALL;
    }

    /**
     * @param array<string, mixed> $tree
     * @return list<array<string, mixed>>
     */
    private function rules(array $tree): array
    {
        return array_values(array_filter((array)($tree['rules'] ?? []), 'is_array'));
    }

    private function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float)(is_numeric($value) ? $value : 0), 4, '.', ''), '0'), '.');
    }
}

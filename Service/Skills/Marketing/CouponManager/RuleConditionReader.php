<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionVocabulary as Vocabulary;

/**
 * Reads a cart rule's REST `condition` and `action_condition` trees back into the words the model
 * writes them in, the inverse of RuleConditionBuilder.
 *
 * Null means the tree holds something that vocabulary cannot say (a payment method, a subselect,
 * a group inside a group, a condition from another module). Such a tree is shown and kept as it is,
 * never rewritten, or the parts Mago cannot read would be dropped without anyone seeing it.
 */
class RuleConditionReader
{
    /**
     * @return array{match: string, is?: false, rules: list<array<string, mixed>>}|null
     */
    public function readConditions(mixed $condition): ?array
    {
        if (!is_array($condition) || $condition === []) {
            return $this->emptyTree();
        }
        if (!$this->isCombine($condition, Vocabulary::COMBINE_CLASS)) {
            return null;
        }

        return $this->tree($condition, $this->cartRule(...));
    }

    /**
     * @return array{match: string, is?: false, rules: list<array<string, mixed>>}|null
     */
    public function readActions(mixed $actionCondition): ?array
    {
        if (!is_array($actionCondition) || $actionCondition === []) {
            return $this->emptyTree();
        }
        if (!$this->isCombine($actionCondition, Vocabulary::PRODUCT_COMBINE_CLASS)) {
            return null;
        }

        return $this->tree($actionCondition, $this->productRuleOrGroup(...));
    }

    /**
     * "is" is only written when the rules have to fail, the way the model leaves it out by default
     *
     * @param array<string, mixed> $node
     * @param callable(array<string, mixed>): ?array<string, mixed> $readRule
     * @return array{match: string, is?: false, rules: list<array<string, mixed>>}|null
     */
    private function tree(array $node, callable $readRule): ?array
    {
        $rules = $this->children($node, $readRule);
        if ($rules === null) {
            return null;
        }

        $match = (string)$node['aggregator_type'];

        return $this->isTrue($node['value'] ?? null)
            ? ['match' => $match, 'rules' => $rules]
            : ['match' => $match, 'is' => false, 'rules' => $rules];
    }

    /**
     * A product rule, or a group of them. A group inside a group is unreadable: the vocabulary
     * nests one level, as far as Magento's admin form is commonly used.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function productRuleOrGroup(array $node): ?array
    {
        if (($node['condition_type'] ?? null) !== Vocabulary::PRODUCT_COMBINE_CLASS) {
            return $this->productRule($node);
        }
        $group = $this->isCombine($node, Vocabulary::PRODUCT_COMBINE_CLASS)
            ? $this->tree($node, $this->productRule(...))
            : null;

        return $group === null ? null : [Vocabulary::GROUP => $group];
    }

    /**
     * @param array<string, mixed> $node
     * @param callable(array<string, mixed>): ?array<string, mixed> $readRule
     * @return list<array<string, mixed>>|null
     */
    private function children(array $node, callable $readRule): ?array
    {
        $children = array_values(array_filter((array)($node['conditions'] ?? []), 'is_array'));
        $rules = array_map($readRule, $children);

        return in_array(null, $rules, true) ? null : $rules;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function cartRule(array $node): ?array
    {
        return match ($node['condition_type'] ?? null) {
            Vocabulary::ADDRESS_CLASS => $this->addressRule($node),
            Vocabulary::COMBINE_CLASS => $this->countryList($node),
            Vocabulary::PRODUCT_FOUND_CLASS => $this->productInCart($node),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function addressRule(array $node): ?array
    {
        $attribute = (string)($node['attribute_name'] ?? '');
        $operator = (string)($node['operator'] ?? '');
        if ($attribute === Vocabulary::ADDRESS_COUNTRY) {
            return $this->singleCountry($operator, (string)($node['value'] ?? ''));
        }

        $field = Vocabulary::totalFieldForAttribute($attribute);
        if ($field === null || !isset(Vocabulary::NUMERIC_OPERATORS[$operator]) || !is_numeric($node['value'] ?? null)) {
            return null;
        }

        return ['field' => $field, 'operator' => $operator, 'value' => (float)$node['value']];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function singleCountry(string $operator, string $country): ?array
    {
        $listOperator = match ($operator) {
            '==' => Vocabulary::OPERATOR_IN,
            '!=' => Vocabulary::OPERATOR_NOT_IN,
            default => null,
        };

        return $listOperator === null || $country === ''
            ? null
            : ['field' => Vocabulary::FIELD_SHIPPING_COUNTRY, 'operator' => $listOperator, 'value' => [$country]];
    }

    /**
     * The nested combination RuleConditionBuilder writes for several countries: any of several
     * "is", or all of several "is not". Any other nested combination is unreadable.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function countryList(array $node): ?array
    {
        if (!$this->isCombine($node, Vocabulary::COMBINE_CLASS) || !$this->isTrue($node['value'] ?? null)) {
            return null;
        }
        $countries = $this->children($node, $this->addressRule(...));
        $expectedOperator = $node['aggregator_type'] === Vocabulary::MATCH_ANY
            ? Vocabulary::OPERATOR_IN
            : Vocabulary::OPERATOR_NOT_IN;
        $isCountryList = $countries !== null && $countries !== [] && array_filter(
            $countries,
            static fn (array $rule): bool => ($rule['field'] ?? '') !== Vocabulary::FIELD_SHIPPING_COUNTRY
                || $rule['operator'] !== $expectedOperator
        ) === [];

        return $isCountryList ? [
            'field' => Vocabulary::FIELD_SHIPPING_COUNTRY,
            'operator' => $expectedOperator,
            'value' => array_merge(...array_map(static fn (array $rule): array => $rule['value'], $countries)),
        ] : null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function productInCart(array $node): ?array
    {
        $match = $node['aggregator_type'] ?? null;
        $rules = $this->children($node, $this->productRuleOrGroup(...));
        if ($rules === null || !in_array($match, Vocabulary::MATCHES, true)) {
            return null;
        }

        return [
            'field' => Vocabulary::FIELD_PRODUCT_IN_CART,
            'presence' => $this->isTrue($node['value'] ?? null)
                ? Vocabulary::PRESENCE_FOUND
                : Vocabulary::PRESENCE_NOT_FOUND,
            'match' => $match,
            'rules' => $rules,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function productRule(array $node): ?array
    {
        $attribute = (string)($node['attribute_name'] ?? '');
        $operator = Vocabulary::productOperatorForMagento((string)($node['operator'] ?? ''));
        $hasScope = (string)($node['extension_attributes']['attribute_scope'] ?? '') !== '';
        if (($node['condition_type'] ?? null) !== Vocabulary::PRODUCT_CLASS
            || $attribute === ''
            || $operator === null
            || $hasScope
        ) {
            return null;
        }
        $values = Vocabulary::valuesFromInput($node['value'] ?? '');

        return [
            'attribute' => $attribute,
            'operator' => $operator,
            'value' => Vocabulary::isListOperator($operator) ? $values : ($values[0] ?? ''),
        ];
    }

    /**
     * A combination of this class with a known match, whose rules have to hold or have to fail
     *
     * @param array<string, mixed> $node
     */
    private function isCombine(array $node, string $class): bool
    {
        return ($node['condition_type'] ?? null) === $class
            && in_array($node['aggregator_type'] ?? null, Vocabulary::MATCHES, true)
            && ($this->isTrue($node['value'] ?? null) || $this->isFalse($node['value'] ?? null));
    }

    private function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    private function isFalse(mixed $value): bool
    {
        return in_array($value, [false, 0, '0'], true);
    }

    /**
     * @return array{match: string, rules: list<array<string, mixed>>}
     */
    private function emptyTree(): array
    {
        return ['match' => Vocabulary::MATCH_ALL, 'rules' => []];
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionVocabulary as Vocabulary;

/**
 * Checks the conditions and actions a model wrote before they reach a cart rule.
 *
 * Magento's REST API checks none of it: an attribute that does not exist, or one that is not used
 * for promo rules, saves fine and then never matches. So the shape is checked before the caution
 * card (no lookups), and what the ids point at is checked against the store before the save.
 */
class RuleConditionValidator
{
    private const ATTRIBUTE_CODE_PATTERN = '/^[a-z][a-z0-9_]*$/';
    private const COUNTRY_CODE_PATTERN = '/^[A-Za-z]{2}$/';
    private const SINGLE_VALUE_INPUTS = ['select', 'boolean'];
    private const LIST_VALUE_INPUTS = ['multiselect'];
    private const PROMO_SETTING = 'Stores > Attributes > Product > %s > Storefront Properties > '
        . 'Use for Promo Rule Conditions';

    public function __construct(
        private readonly RuleTargetCatalog $catalog
    ) {
    }

    public function findConditionsError(mixed $conditions): ?string
    {
        $tree = Vocabulary::treeFromInput($conditions);

        return $tree === null
            ? 'conditions must be an object with "match" and "rules"'
            : $this->treeError('conditions', $tree, $this->cartRuleError(...));
    }

    public function findActionsError(mixed $actions): ?string
    {
        $tree = Vocabulary::treeFromInput($actions);

        return $tree === null
            ? 'actions must be an object with "match" and "rules"'
            : $this->treeError('actions', $tree, $this->productRuleOrGroupError(...));
    }

    /**
     * What the rules point at, checked against the store: categories, attributes and their options,
     * countries. Only for trees that passed the shape checks.
     *
     * @param array<string, mixed>|null $conditions
     * @param array<string, mixed>|null $actions
     */
    public function findTargetError(?array $conditions, ?array $actions, int $adminUserId): ?string
    {
        $productRules = Vocabulary::productRulesIn($conditions, $actions);

        return $this->categoryError($productRules, $adminUserId)
            ?? $this->firstError(array_map(
                fn (array $rule): ?string => $this->attributeError($rule, $adminUserId),
                $productRules
            ))
            ?? $this->countryError($conditions, $adminUserId);
    }

    /**
     * @param array<string, mixed> $tree
     * @param callable(array<string, mixed>): ?string $ruleError
     */
    private function treeError(string $name, array $tree, callable $ruleError): ?string
    {
        if (!in_array($tree['match'] ?? Vocabulary::MATCH_ALL, Vocabulary::MATCHES, true)) {
            return $name . '.match must be "all" or "any"';
        }
        if (!is_bool($tree[Vocabulary::IS] ?? true)) {
            return $name . '.is must be true or false';
        }
        if (!is_array($tree['rules'] ?? [])) {
            return $name . '.rules must be a list';
        }
        $rules = (array)($tree['rules'] ?? []);
        if (count($rules) > Vocabulary::MAX_RULES_PER_TREE) {
            return $name . ' can hold at most ' . Vocabulary::MAX_RULES_PER_TREE . ' rules';
        }

        return $this->firstError(array_map(
            fn (mixed $rule): ?string => is_array($rule) ? $ruleError($rule) : $name . ' rules must be objects',
            $rules
        ));
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function cartRuleError(array $rule): ?string
    {
        $field = (string)($rule['field'] ?? '');

        return match (true) {
            $field === Vocabulary::FIELD_SHIPPING_COUNTRY => $this->countryRuleError($rule),
            $field === Vocabulary::FIELD_PRODUCT_IN_CART => $this->productInCartError($rule),
            isset(Vocabulary::TOTAL_FIELDS[$field]) => $this->totalRuleError($field, $rule),
            default => 'Unsupported condition field "' . $field . '". Supported: '
                . implode(', ', [
                    ...array_keys(Vocabulary::TOTAL_FIELDS),
                    Vocabulary::FIELD_SHIPPING_COUNTRY,
                    Vocabulary::FIELD_PRODUCT_IN_CART,
                ])
                . '. Other conditions (payment or shipping method, postcode, weight) have to be set in the admin.',
        };
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function totalRuleError(string $field, array $rule): ?string
    {
        if (!isset(Vocabulary::NUMERIC_OPERATORS[(string)($rule['operator'] ?? '')])) {
            return $field . ' needs an operator out of ' . implode(' ', array_keys(Vocabulary::NUMERIC_OPERATORS));
        }
        $value = $rule['value'] ?? null;

        return is_numeric($value) && (float)$value >= 0 ? null : $field . ' needs a number of 0 or more as value';
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function countryRuleError(array $rule): ?string
    {
        if (!Vocabulary::isListOperator((string)($rule['operator'] ?? ''))) {
            return 'shipping_country needs operator "in" or "not_in"';
        }
        $countries = Vocabulary::valuesFromInput($rule['value'] ?? []);
        $invalid = array_filter(
            $countries,
            static fn (string $country): bool => preg_match(self::COUNTRY_CODE_PATTERN, $country) !== 1
        );

        return $this->valueCountError('shipping_country', $countries)
            ?? ($invalid === [] ? null : 'shipping_country takes two-letter country codes, not: ' . implode(', ', $invalid));
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function productInCartError(array $rule): ?string
    {
        $presence = $rule['presence'] ?? Vocabulary::PRESENCE_FOUND;
        if (!in_array($presence, [Vocabulary::PRESENCE_FOUND, Vocabulary::PRESENCE_NOT_FOUND], true)) {
            return 'product_in_cart.presence must be "found" or "not_found"';
        }
        if ((array)($rule['rules'] ?? []) === []) {
            return 'product_in_cart needs at least one rule describing the product';
        }

        if (array_key_exists(Vocabulary::IS, $rule)) {
            return 'product_in_cart takes "presence" ("found" or "not_found"), not "is"';
        }

        return $this->treeError('product_in_cart', $rule, $this->productRuleOrGroupError(...));
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function productRuleOrGroupError(array $rule): ?string
    {
        if (!Vocabulary::isGroup($rule)) {
            return $this->productRuleError($rule);
        }
        $group = $rule[Vocabulary::GROUP];
        if (!is_array($group) || (array)($group['rules'] ?? []) === []) {
            return 'A group needs "rules" with at least one product rule';
        }

        return $this->treeError('group', $group, $this->productRuleError(...));
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function productRuleError(array $rule): ?string
    {
        if (Vocabulary::isGroup($rule)) {
            return 'Groups do not nest: a group holds product rules only';
        }
        $attribute = (string)($rule['attribute'] ?? '');
        $operator = (string)($rule['operator'] ?? '');
        if (preg_match(self::ATTRIBUTE_CODE_PATTERN, $attribute) !== 1) {
            return 'Every product rule needs an attribute code, such as sku, category_ids or a promo attribute';
        }
        if (!isset(Vocabulary::PRODUCT_OPERATORS[$operator])) {
            return $attribute . ' needs an operator out of ' . implode(', ', array_keys(Vocabulary::PRODUCT_OPERATORS));
        }
        $values = Vocabulary::valuesFromInput($rule['value'] ?? []);
        if ($attribute === Vocabulary::ATTRIBUTE_CATEGORY_IDS) {
            return $this->categoryRuleError($operator, $values);
        }

        return Vocabulary::isListOperator($operator)
            ? $this->valueCountError($attribute, $values)
            : (count($values) === 1 ? null : $attribute . ' with "' . $operator . '" takes exactly one value');
    }

    /**
     * @param list<string> $values
     */
    private function categoryRuleError(string $operator, array $values): ?string
    {
        $nonIds = array_filter($values, static fn (string $value): bool => !ctype_digit($value));
        $countError = Vocabulary::isListOperator($operator)
            ? $this->valueCountError('category_ids', $values)
            : (count($values) === 1 ? null : 'category_ids with "' . $operator . '" takes exactly one id');

        return $countError
            ?? ($nonIds === [] ? null : 'category_ids takes category ids, not names: look them up with '
                . 'find_rule_targets first (' . implode(', ', $nonIds) . ')');
    }

    /**
     * @param list<string> $values
     */
    private function valueCountError(string $name, array $values): ?string
    {
        return match (true) {
            $values === [] => $name . ' needs at least one value',
            count($values) > Vocabulary::MAX_VALUES_PER_RULE => $name . ' can hold at most '
                . Vocabulary::MAX_VALUES_PER_RULE . ' values',
            default => null,
        };
    }

    /**
     * @param list<array<string, mixed>> $productRules
     */
    private function categoryError(array $productRules, int $adminUserId): ?string
    {
        $categoryIds = Vocabulary::categoryIdsIn($productRules);
        if ($categoryIds === []) {
            return null;
        }
        $missing = array_diff($categoryIds, array_keys($this->catalog->categoryPaths($categoryIds, $adminUserId)));

        return $missing === []
            ? null
            : 'No category with id ' . implode(', ', $missing) . ' exists, or this admin role cannot read '
                . 'categories. Look categories up with find_rule_targets.';
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function attributeError(array $rule, int $adminUserId): ?string
    {
        $code = (string)($rule['attribute'] ?? '');
        if (in_array($code, [Vocabulary::ATTRIBUTE_SKU, Vocabulary::ATTRIBUTE_CATEGORY_IDS], true)) {
            return null;
        }
        $attribute = $this->catalog->attribute($code, $adminUserId);
        if ($attribute === null) {
            return 'No product attribute "' . $code . '" exists, or this admin role cannot read product '
                . 'attributes. Look attributes up with find_rule_targets.';
        }
        if (!$attribute['is_promo']) {
            return 'Attribute "' . $attribute['label'] . '" (' . $code . ') is not used for promo rules, so a cart '
                . 'rule cannot check it. Switch it on under ' . sprintf(self::PROMO_SETTING, $attribute['label'])
                . ', then try again.';
        }

        return $this->attributeOperatorError($attribute, (string)($rule['operator'] ?? ''))
            ?? $this->optionError($attribute, Vocabulary::valuesFromInput($rule['value'] ?? []));
    }

    /**
     * @param array{code: string, label: string, input: string, is_promo: bool, options: array<string, string>} $attribute
     */
    private function attributeOperatorError(array $attribute, string $operator): ?string
    {
        $isList = Vocabulary::isListOperator($operator);

        return match (true) {
            in_array($attribute['input'], self::SINGLE_VALUE_INPUTS, true) => $isList
                ? $attribute['code'] . ' is a single-choice attribute: use "is" or "is_not" with one option id, '
                    . 'and match "any" with one rule per option for several'
                : null,
            in_array($attribute['input'], self::LIST_VALUE_INPUTS, true) => $isList
                ? null
                : $attribute['code'] . ' is a multiple-choice attribute: use "in" or "not_in"',
            default => 'Attribute ' . $attribute['code'] . ' is a ' . $attribute['input'] . ' attribute. Mago can '
                . 'only set rules on sku, category_ids and dropdown, multiple select or yes/no attributes; set '
                . 'this one in the admin.',
        };
    }

    /**
     * @param array{code: string, label: string, input: string, is_promo: bool, options: array<string, string>} $attribute
     * @param list<string> $values
     */
    private function optionError(array $attribute, array $values): ?string
    {
        $unknown = $attribute['options'] === [] ? [] : array_diff($values, array_keys($attribute['options']));

        return $unknown === [] ? null : 'Attribute ' . $attribute['code'] . ' has no option with id '
            . implode(', ', $unknown) . '. Look option ids up with find_rule_targets.';
    }

    /**
     * @param array<string, mixed>|null $conditions
     */
    private function countryError(?array $conditions, int $adminUserId): ?string
    {
        $countries = array_map('strtoupper', array_merge([], ...array_map(
            static fn (array $rule): array => Vocabulary::valuesFromInput($rule['value'] ?? []),
            array_filter(
                Vocabulary::rulesOf($conditions),
                static fn (array $rule): bool => ($rule['field'] ?? '') === Vocabulary::FIELD_SHIPPING_COUNTRY
            )
        )));
        $known = $countries === [] ? [] : $this->catalog->countries($adminUserId);
        $unknown = $known === [] ? [] : array_diff($countries, array_keys($known));

        return $unknown === [] ? null : 'Unknown country code ' . implode(', ', $unknown) . '.';
    }

    /**
     * @param array<int|string, string|null> $errors
     */
    private function firstError(array $errors): ?string
    {
        return array_values(array_filter($errors, static fn (?string $error): bool => $error !== null))[0] ?? null;
    }
}

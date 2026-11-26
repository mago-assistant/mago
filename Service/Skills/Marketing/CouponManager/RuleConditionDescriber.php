<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionVocabulary as Vocabulary;

/**
 * Says a cart rule's conditions and actions in plain words, with categories, attributes, options
 * and countries by name, so the admin approves what the rule does rather than a list of ids.
 */
class RuleConditionDescriber
{
    private const OPERATOR_WORDS = [
        Vocabulary::OPERATOR_IS => 'is',
        Vocabulary::OPERATOR_IS_NOT => 'is not',
        Vocabulary::OPERATOR_IN => 'is one of',
        Vocabulary::OPERATOR_NOT_IN => 'is not one of',
    ];

    public function __construct(
        private readonly RuleTargetCatalog $catalog
    ) {
    }

    /**
     * @param array<string, mixed> $conditions
     */
    public function describeConditions(array $conditions, int $adminUserId): string
    {
        $clauses = array_map(
            fn (array $rule): string => $this->cartClause($rule, $adminUserId),
            Vocabulary::rulesOf($conditions)
        );

        $isAffirmed = Vocabulary::isAffirmed($conditions);

        return match (true) {
            $clauses === [] => 'Applies to every cart, with no conditions.',
            count($clauses) === 1 => ($isAffirmed ? 'Applies when ' : 'Applies unless ') . $clauses[0] . '.',
            default => 'Applies when ' . $this->matchPhrase($conditions) . ': ' . implode('; ', $clauses) . '.',
        };
    }

    /**
     * Magento's combination: "all" with true needs every rule to hold, "any" with true one of them;
     * "all" with false needs every rule to fail, "any" with false one of them.
     *
     * @param array<string, mixed> $tree
     */
    private function matchPhrase(array $tree): string
    {
        $isAny = ($tree['match'] ?? '') === Vocabulary::MATCH_ANY;

        return match (true) {
            Vocabulary::isAffirmed($tree) => ($isAny ? 'ANY' : 'ALL') . ' of these hold',
            $isAny => 'at least one of these does NOT hold',
            default => 'NONE of these hold',
        };
    }

    /**
     * @param array<string, mixed> $actions
     */
    public function describeActions(array $actions, int $adminUserId): string
    {
        $clauses = $this->productClauses($actions, $adminUserId);

        return $clauses === ''
            ? 'Discounts every item in the cart.'
            : 'Discounts only items where ' . $clauses . '.';
    }

    /**
     * One line per chosen category that shares its name with other categories, naming them all.
     * Whether or not the model looked the name up and asked, the admin sees which one the rule uses
     * before allowing it.
     *
     * @param array<string, mixed>|null $conditions
     * @param array<string, mixed>|null $actions
     * @return string[]
     */
    public function describeSharedCategoryNames(?array $conditions, ?array $actions, int $adminUserId): array
    {
        $chosen = $this->catalog->categoryPaths(
            Vocabulary::categoryIdsIn(Vocabulary::productRulesIn($conditions, $actions)),
            $adminUserId
        );

        return array_values(array_filter(array_map(
            fn (int $id, string $path): ?string => $this->sharedNameLine($id, $path, $adminUserId),
            array_keys($chosen),
            $chosen
        )));
    }

    private function sharedNameLine(int $categoryId, string $path, int $adminUserId): ?string
    {
        $name = (string)array_slice(explode(RuleTargetCatalog::PATH_SEPARATOR, $path), -1)[0];
        $others = array_diff_key($this->catalog->categoriesNamed($name, $adminUserId), [$categoryId => true]);
        if ($others === []) {
            return null;
        }

        return 'Check the category: ' . (count($others) + 1) . ' categories are named "' . $name . '". This rule uses '
            . $path . ' (' . $categoryId . '), not ' . implode(', ', array_map(
                static fn (int $id, string $otherPath): string => $otherPath . ' (' . $id . ')',
                array_keys($others),
                $others
            )) . '.';
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function cartClause(array $rule, int $adminUserId): string
    {
        $field = (string)($rule['field'] ?? '');

        return match ($field) {
            Vocabulary::FIELD_SHIPPING_COUNTRY => $this->countryClause($rule, $adminUserId),
            Vocabulary::FIELD_PRODUCT_IN_CART => $this->productInCartClause($rule, $adminUserId),
            default => $this->totalClause($field, $rule),
        };
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function totalClause(string $field, array $rule): string
    {
        $isQuantity = $field === 'total_qty';
        $value = $rule['value'] ?? 0;
        $number = rtrim(rtrim(number_format((float)(is_numeric($value) ? $value : 0), 4, '.', ''), '0'), '.');

        return 'the ' . (Vocabulary::TOTAL_FIELDS[$field]['label'] ?? $field)
            . ($isQuantity ? '' : ' (base currency)') . ' '
            . (Vocabulary::NUMERIC_OPERATORS[(string)($rule['operator'] ?? '')] ?? (string)($rule['operator'] ?? ''))
            . ' ' . $number;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function countryClause(array $rule, int $adminUserId): string
    {
        $names = $this->catalog->countries($adminUserId);
        $countries = array_map(
            static fn (string $code): string => isset($names[$code]) ? $names[$code] . ' (' . $code . ')' : $code,
            array_map('strtoupper', Vocabulary::valuesFromInput($rule['value'] ?? []))
        );

        return 'the shipping country ' . $this->operatorWord((string)($rule['operator'] ?? ''), count($countries))
            . ' ' . implode(', ', $countries);
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function productInCartClause(array $rule, int $adminUserId): string
    {
        $isAbsent = ($rule['presence'] ?? '') === Vocabulary::PRESENCE_NOT_FOUND;

        return ($isAbsent ? 'the cart has no item where ' : 'the cart has an item where ')
            . $this->productClauses($rule, $adminUserId);
    }

    /**
     * The rules of a tree or group as one expression. Rules that have to fail are said as the NOT of
     * the opposite: "all" of them failing is NOT (a OR b), "any" of them failing is NOT (a AND b).
     *
     * @param array<string, mixed> $tree
     */
    private function productClauses(array $tree, int $adminUserId): string
    {
        $isAny = ($tree['match'] ?? '') === Vocabulary::MATCH_ANY;
        $isAffirmed = Vocabulary::isAffirmed($tree);
        $clauses = array_map(
            fn (array $rule): string => $this->productClause($rule, $adminUserId),
            Vocabulary::rulesOf($tree)
        );
        $joined = implode($isAny === $isAffirmed ? ' OR ' : ' AND ', $clauses);

        return match (true) {
            $isAffirmed || $clauses === [] => $joined,
            count($clauses) === 1 => 'NOT ' . $joined,
            default => 'NOT (' . $joined . ')',
        };
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function productClause(array $rule, int $adminUserId): string
    {
        if (Vocabulary::isGroup($rule)) {
            return $this->groupClause((array)$rule[Vocabulary::GROUP], $adminUserId);
        }
        $attribute = (string)($rule['attribute'] ?? '');
        $values = Vocabulary::valuesFromInput($rule['value'] ?? []);
        $operator = $this->operatorWord((string)($rule['operator'] ?? ''), count($values));

        return match ($attribute) {
            Vocabulary::ATTRIBUTE_SKU => 'SKU ' . $operator . ' ' . implode(', ', $values),
            Vocabulary::ATTRIBUTE_CATEGORY_IDS => 'category ' . $operator . ' '
                . $this->categoryNames($values, $adminUserId),
            default => $this->attributeClause($attribute, $operator, $values, $adminUserId),
        };
    }

    /**
     * @param list<string> $values
     */
    private function attributeClause(string $code, string $operator, array $values, int $adminUserId): string
    {
        $attribute = $this->catalog->attribute($code, $adminUserId);
        $options = $attribute['options'] ?? [];

        return ($attribute['label'] ?? $code) . ' ' . $operator . ' ' . implode(', ', array_map(
            static fn (string $value): string => $options[$value] ?? $value,
            $values
        ));
    }

    /**
     * @param list<string> $categoryIds
     */
    private function categoryNames(array $categoryIds, int $adminUserId): string
    {
        $paths = $this->catalog->categoryPaths(array_map('intval', $categoryIds), $adminUserId);

        return implode(', ', array_map(
            static fn (string $id): string => isset($paths[(int)$id])
                ? $paths[(int)$id] . ' (' . $id . ')'
                : 'category ' . $id . ' (not found)',
            $categoryIds
        ));
    }

    private function operatorWord(string $operator, int $valueCount): string
    {
        $singular = [Vocabulary::OPERATOR_IN => 'is', Vocabulary::OPERATOR_NOT_IN => 'is not'];

        return $valueCount === 1 && isset($singular[$operator])
            ? $singular[$operator]
            : self::OPERATOR_WORDS[$operator] ?? $operator;
    }

    /**
     * A group in brackets when it joins several rules that have to hold; a negated group already
     * brings its own brackets after NOT.
     *
     * @param array<string, mixed> $group
     */
    private function groupClause(array $group, int $adminUserId): string
    {
        $expression = $this->productClauses($group, $adminUserId);
        $hasSeveralRules = count(Vocabulary::rulesOf($group)) > 1;

        return Vocabulary::isAffirmed($group) && $hasSeveralRules ? '(' . $expression . ')' : $expression;
    }
}

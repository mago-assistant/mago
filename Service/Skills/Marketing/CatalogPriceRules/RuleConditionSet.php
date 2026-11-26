<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Model\Rule\Condition\Combine;

/**
 * The conditions a catalog rule is saved with: the model's own conditions joined by all or any, and
 * the category_ids shortcut, which always has to hold as well. With "any" that takes one nested
 * group, so "in category 5 and (red or blue)" keeps meaning what the card says.
 */
class RuleConditionSet
{
    public const MATCH_ALL = 'all';
    public const MATCH_ANY = 'any';

    /**
     * @param RuleCondition[] $conditions
     */
    public function __construct(
        public readonly string $match,
        public readonly array $conditions,
        public readonly ?RuleCondition $categoryCondition = null
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->conditions === [] && $this->categoryCondition === null;
    }

    /**
     * @return array<string, mixed> The array Combine::loadArray() takes
     */
    public function toMagentoArray(): array
    {
        $conditions = array_map(static fn (RuleCondition $condition): array => $condition->toMagentoArray(), $this->conditions);
        if ($this->categoryCondition === null) {
            return $this->combine($this->match, $conditions);
        }

        $category = $this->categoryCondition->toMagentoArray();
        if ($conditions === [] || $this->match === self::MATCH_ALL || count($conditions) === 1) {
            return $this->combine(self::MATCH_ALL, [$category, ...$conditions]);
        }

        return $this->combine(self::MATCH_ALL, [$category, $this->combine($this->match, $conditions)]);
    }

    /**
     * The conditions in words, one line for the caution card; empty when there are none
     */
    public function describe(): string
    {
        $descriptions = array_map(static fn (RuleCondition $condition): string => $condition->description, $this->conditions);
        $own = match (true) {
            $descriptions === [] => '',
            count($descriptions) === 1 => $descriptions[0],
            $this->match === self::MATCH_ANY => 'at least one of: ' . implode('; ', $descriptions),
            default => 'all of: ' . implode('; ', $descriptions),
        };
        $category = $this->categoryCondition === null ? '' : $this->categoryCondition->description;

        return match (true) {
            $own === '' && $category === '' => '',
            $own === '' => 'Only products where ' . $category . '.',
            $category === '' => 'Only products where ' . $own . '.',
            default => 'Only products where ' . $category . ', and ' . $own . '.',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $conditions
     * @return array<string, mixed>
     */
    private function combine(string $match, array $conditions): array
    {
        return [
            'type' => Combine::class,
            'aggregator' => $match,
            'value' => '1',
            'conditions' => $conditions,
        ];
    }
}

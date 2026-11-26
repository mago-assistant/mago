<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Turns the names an admin uses ("Open Box", "brand Hikvision") into the ids a cart rule condition
 * takes. Every match comes back, so a name shared by two categories shows up as two candidates
 * with their paths, and the model asks which one is meant instead of guessing.
 */
class FindRuleTargetsAction implements ActionInterface
{
    private const TARGET_CATEGORY = 'category';
    private const TARGET_ATTRIBUTE = 'attribute';
    private const TARGET_ATTRIBUTE_OPTION = 'attribute_option';
    private const TARGETS = [self::TARGET_CATEGORY, self::TARGET_ATTRIBUTE, self::TARGET_ATTRIBUTE_OPTION];

    public function __construct(
        private readonly RuleTargetCatalog $catalog
    ) {
    }

    public function getName(): string
    {
        return 'find_rule_targets';
    }

    public function getDescription(): string
    {
        return 'Look up the ids a cart rule condition or action takes: categories by name (with their path), '
            . 'product attributes usable in promo rules, or the option ids of one attribute';
    }

    public function getParameterSchema(): array
    {
        return [
            'target' => [
                'type' => 'string',
                'enum' => self::TARGETS,
                'description' => 'For find_rule_targets: "category", "attribute" or "attribute_option"',
            ],
            'query' => [
                'type' => 'string',
                'description' => 'For find_rule_targets: part of the category name, attribute code or label, or '
                    . 'option label to look for. Empty lists the first matches',
            ],
            'attribute_code' => [
                'type' => 'string',
                'description' => 'For find_rule_targets with target "attribute_option": the attribute whose '
                    . 'options to list',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'categories' => [PiiClass::PUBLIC],
            'attributes' => [PiiClass::PUBLIC],
            'attribute' => [PiiClass::PUBLIC],
            'options' => [PiiClass::PUBLIC],
            'id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'path' => [PiiClass::PUBLIC],
            'code' => [PiiClass::PUBLIC],
            'label' => [PiiClass::PUBLIC],
            'input' => [PiiClass::PUBLIC],
            'is_used_for_promo_rules' => [PiiClass::PUBLIC],
            'total_count' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'When find_rule_targets returns more than one category or option for the name the user gave, '
            . 'ask which one they mean, naming each with its path; never pick one yourself.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }
        $query = trim((string)($params['query'] ?? ''));

        return match ($params['target'] ?? null) {
            self::TARGET_CATEGORY => $this->categories($query, $adminUserId),
            self::TARGET_ATTRIBUTE => ['attributes' => $this->catalog->findPromoAttributes($query, $adminUserId)],
            self::TARGET_ATTRIBUTE_OPTION => $this->options(
                trim((string)($params['attribute_code'] ?? '')),
                $query,
                $adminUserId
            ),
            default => ['error' => 'target must be one of: ' . implode(', ', self::TARGETS)],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function categories(string $query, int $adminUserId): array
    {
        return $query === ''
            ? ['error' => 'query is required for target "category"']
            : ['categories' => $this->catalog->findCategories($query, $adminUserId)];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(string $attributeCode, string $query, int $adminUserId): array
    {
        if ($attributeCode === '') {
            return ['error' => 'attribute_code is required for target "attribute_option"'];
        }
        $attribute = $this->catalog->attribute($attributeCode, $adminUserId);
        if ($attribute === null) {
            return ['error' => 'No product attribute "' . $attributeCode . '" exists'];
        }
        $matching = array_filter(
            $attribute['options'],
            static fn (string $label): bool => $query === '' || mb_stripos($label, $query) !== false
        );

        return [
            'attribute' => [
                'code' => $attribute['code'],
                'label' => $attribute['label'],
                'input' => $attribute['input'],
                'is_used_for_promo_rules' => $attribute['is_promo'],
            ],
            'options' => array_map(
                static fn (int|string $id, string $label): array => ['id' => (string)$id, 'label' => $label],
                array_keys(array_slice($matching, 0, RuleTargetCatalog::MAX_RESULTS, true)),
                array_slice($matching, 0, RuleTargetCatalog::MAX_RESULTS, true)
            ),
            'total_count' => count($matching),
        ];
    }
}

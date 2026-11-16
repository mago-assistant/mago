<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation;

use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Deletes options no product uses; an option a product still has is refused (merge_options moves those)
 */
class DeleteOptionsAction implements IrreversibleActionInterface, ValidatingActionInterface
{
    public function __construct(
        private readonly FilterAttributes $filterAttributes,
        private readonly OptionSelection $optionSelection,
        private readonly AttributeOptionManagementInterface $optionManagement
    ) {
    }

    public function getName(): string
    {
        return 'delete_options';
    }

    public function getDescription(): string
    {
        return 'Delete attribute options that no product uses';
    }

    public function getParameterSchema(): array
    {
        return [
            'attribute' => [
                'type' => 'string',
                'description' => 'Attribute code, from list_filters or audit_filters',
            ],
            'option_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'delete_options and merge_options: option ids from cleanup_options. For '
                    . 'merge_options the options to merge away',
            ],
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
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getImpacts(array $params, int $adminUserId): array
    {
        try {
            [$attribute, $labels] = $this->prepare($params);
        } catch (LocalizedException) {
            return [];
        }

        return [
            sprintf(
                'Deletes %d option(s) of "%s": %s.',
                count($labels),
                $attribute['label'],
                implode(', ', array_map(static fn (string $label) => '"' . $label . '"', $labels))
            ),
            'No product uses them. Their labels in every store view and their swatches are deleted too.',
        ];
    }

    public function findRefusal(array $params): ?array
    {
        try {
            $this->prepare($params);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }

        return null;
    }

    public function execute(array $params, int $adminUserId): array
    {
        try {
            [$attribute, $labels] = $this->prepare($params);
            foreach (array_keys($labels) as $optionId) {
                $this->optionManagement->delete(
                    FilterAttributes::PRODUCT_ENTITY_TYPE,
                    $attribute['code'],
                    $optionId
                );
            }
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'attribute' => $attribute['code'],
            'deleted' => array_values($labels),
        ];
    }

    /**
     * The attribute and the options to delete, after every check, against the products as they are now
     *
     * @param array $params
     * @return array{0: array, 1: array<int, string>} Attribute; option id => label
     * @throws LocalizedException
     */
    private function prepare(array $params): array
    {
        [$attribute, $options] = $this->optionSelection->resolve($params, 'option_ids');
        $inUse = array_intersect_key($this->filterAttributes->optionProductCounts($attribute), $options);
        if ($inUse !== []) {
            throw new LocalizedException(__(
                'Products still use %1. Merge them into another option with merge_options instead.',
                implode(', ', array_map(
                    static fn (int $optionId, int $count) => sprintf('"%s" (%d products)', $options[$optionId], $count),
                    array_keys($inUse),
                    $inUse
                ))
            ));
        }

        return [$attribute, $options];
    }
}

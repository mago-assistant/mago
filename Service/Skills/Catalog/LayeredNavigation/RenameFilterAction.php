<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Attribute as AttributeResource;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as AttributeModel;
use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Renames an attribute's default label, the filter name on the storefront where a store view has none
 *
 * Its own action, apart from update_filter, so a model filling in every field it sees cannot rename a
 * filter as a side effect of moving it.
 */
class RenameFilterAction implements ValidatingActionInterface
{
    public function __construct(
        private readonly FilterAttributes $filterAttributes,
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly AttributeResource $attributeResource
    ) {
    }

    public function getName(): string
    {
        return 'rename_filter';
    }

    public function getDescription(): string
    {
        return 'Rename an attribute\'s default label, only when the user asks for a new name';
    }

    public function getParameterSchema(): array
    {
        return [
            'attribute' => [
                'type' => 'string',
                'description' => 'Attribute code, from list_filters or audit_filters',
            ],
            'label' => [
                'type' => 'string',
                'description' => 'rename_filter: the new name the user gave; shown on the storefront where a store '
                    . 'view has no label of its own',
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
            [$attribute, $label] = $this->prepare($params);
            $model = $this->attributeRepository->get($attribute['code']);
            if (!$model instanceof AttributeModel) {
                throw new LocalizedException(__('Attribute "%1" cannot be saved here.', $attribute['code']));
            }
            $model->setData('frontend_label', $label);
            $this->attributeResource->save($model);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'attribute' => $attribute['code'],
            'old_label' => $attribute['label'],
            'label' => $label,
            'message' => 'Renamed. Store views with their own label keep it. The storefront shows it after a '
                . 'cache flush.',
        ];
    }

    /**
     * @param array $params
     * @return array{0: array, 1: string} Attribute; new label
     * @throws LocalizedException
     */
    private function prepare(array $params): array
    {
        $code = trim((string)($params['attribute'] ?? ''));
        $attribute = $code === '' ? null : $this->filterAttributes->attribute($code);
        if ($attribute === null) {
            throw new LocalizedException(
                __('There is no product attribute "%1". Use list_filters for the codes.', $code)
            );
        }
        $label = trim((string)($params['label'] ?? ''));
        if ($label === '') {
            throw new LocalizedException(__('Give "label": the new name.'));
        }

        return [$attribute, $label];
    }
}

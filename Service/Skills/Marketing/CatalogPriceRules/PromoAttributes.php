<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Store\Model\Store;

/**
 * Finds the product attributes a model names in a rule condition, by code or by admin label.
 *
 * Magento itself never checks "Use for Promo Rule Conditions" when a rule is saved, and a condition
 * on an attribute code that does not exist ends in a PHP Error inside the product collection, so
 * every attribute a condition names is resolved here first.
 */
class PromoAttributes
{
    private const BOOLEAN_INPUT = 'boolean';

    public function __construct(
        private readonly AttributeCollectionFactory $attributeCollectionFactory
    ) {
    }

    /**
     * @return PromoAttribute[] Every attribute whose code or admin label equals the name, case-insensitive;
     *                          an exact code match alone when there is one
     */
    public function findByName(string $name): array
    {
        $byCode = $this->load(ProductAttributeInterface::ATTRIBUTE_CODE, $name);
        if ($byCode !== []) {
            return $byCode;
        }

        return $this->load(ProductAttributeInterface::FRONTEND_LABEL, $name);
    }

    /**
     * @return PromoAttribute[]
     */
    private function load(string $field, string $value): array
    {
        $collection = $this->attributeCollectionFactory->create();
        $collection->addFieldToFilter($field, $value);

        return array_values(array_filter(
            array_map(
                $this->toPromoAttribute(...),
                array_filter(
                    $collection->getItems(),
                    static fn (mixed $attribute): bool => $attribute instanceof Attribute
                )
            ),
            static fn (PromoAttribute $attribute): bool => strcasecmp(
                $field === ProductAttributeInterface::ATTRIBUTE_CODE ? $attribute->code : $attribute->label,
                $value
            ) === 0
        ));
    }

    private function toPromoAttribute(Attribute $attribute): PromoAttribute
    {
        $input = (string)$attribute->getFrontendInput();

        return new PromoAttribute(
            (int)$attribute->getAttributeId(),
            (string)$attribute->getAttributeCode(),
            (string)$attribute->getDefaultFrontendLabel(),
            $input,
            (bool)$attribute->getIsUsedForPromoRules(),
            $input === self::BOOLEAN_INPUT || !$attribute->usesSource() ? [] : $this->optionsOf($attribute)
        );
    }

    /**
     * @return array<string, string> Admin label by option id
     */
    private function optionsOf(Attribute $attribute): array
    {
        $attribute->setData('store_id', Store::DEFAULT_STORE_ID);
        $options = array_filter(
            $attribute->getSource()->getAllOptions(),
            static fn (array $option): bool => is_scalar($option['value'] ?? null) && (string)$option['value'] !== ''
        );

        return array_column(
            array_map(
                static fn (array $option): array => [
                    'id' => (string)$option['value'],
                    'label' => (string)$option['label'],
                ],
                $options
            ),
            'label',
            'id'
        );
    }
}

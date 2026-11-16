<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation;

use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Merges duplicate options into one: products with any of them get the kept option, the others go
 */
class MergeOptionsAction implements IrreversibleActionInterface, ValidatingActionInterface
{
    /** SKUs named per merged option on the confirmation card */
    private const SKUS_SHOWN = 5;

    public function __construct(
        private readonly FilterAttributes $filterAttributes,
        private readonly OptionSelection $optionSelection,
        private readonly OptionMerger $optionMerger
    ) {
    }

    public function getName(): string
    {
        return 'merge_options';
    }

    public function getDescription(): string
    {
        return 'Merge duplicate options of an attribute into one: the products move to the kept option and the '
            . 'others are deleted';
    }

    public function getParameterSchema(): array
    {
        return [
            'attribute' => [
                'type' => 'string',
                'description' => 'Attribute code, from list_filters or audit_filters',
            ],
            'target_option_id' => [
                'type' => 'integer',
                'description' => 'merge_options: the option to keep, from cleanup_options',
            ],
            'option_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'delete_options and merge_options: option ids from cleanup_options. For '
                    . 'merge_options the options to merge away',
            ],
        ];
    }

    /**
     * Product values change too, so the admin must be allowed to edit products as well
     */
    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::products';
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
        return 'Afterwards say how many products changed and offer the reindex and cache flush.';
    }

    public function getImpacts(array $params, int $adminUserId): array
    {
        try {
            [$attribute, $target, $sources] = $this->prepare($params);
        } catch (LocalizedException) {
            return [];
        }

        $products = $this->filterAttributes->productsByOption($attribute, array_keys($sources));
        $impacts = [];
        foreach ($sources as $optionId => $label) {
            $skus = array_values($products[$optionId] ?? []);
            $impacts[] = $skus === []
                ? sprintf('"%s" has no products; it is only deleted.', $label)
                : sprintf(
                    '%d product(s) change from "%s" to "%s" in every store view: %s%s.',
                    count($skus),
                    $label,
                    $target,
                    implode(', ', array_slice($skus, 0, self::SKUS_SHOWN)),
                    count($skus) > self::SKUS_SHOWN ? sprintf(' and %d more', count($skus) - self::SKUS_SHOWN) : ''
                );
        }
        $impacts[] = sprintf(
            'Deletes the option(s) %s of "%s", with their store view labels and swatches.',
            implode(', ', array_map(static fn (string $label) => '"' . $label . '"', $sources)),
            $attribute['label']
        );
        $impacts[] = 'Marks the catalog_product_attribute and catalogsearch_fulltext indexers for a rebuild.';

        return $impacts;
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
            [$attribute, $target, $sources] = $this->prepare($params);
            $merged = $this->optionMerger->merge($attribute, (int)$params['target_option_id'], array_keys($sources));
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Exception) {
            return ['error' => 'The merge failed and was rolled back; no product changed.'];
        }

        $result = [
            'success' => true,
            'attribute' => $attribute['code'],
            'kept' => $target,
            'merged' => array_values($sources),
            'products_changed' => count($merged['products']),
            'message' => 'Merged. The storefront shows it after a reindex of catalog_product_attribute and '
                . 'catalogsearch_fulltext and a cache flush.',
        ];
        if ($merged['not_deleted'] !== []) {
            $result['not_deleted'] = array_values(array_intersect_key($sources, array_flip($merged['not_deleted'])));
            $result['message'] .= ' The products moved, but these options could not be deleted and are now empty: '
                . 'delete them with delete_options.';
        }

        return $result;
    }

    /**
     * The attribute, the kept option's label and the options to merge away, after every check
     *
     * @param array $params
     * @return array{0: array, 1: string, 2: array<int, string>}
     * @throws LocalizedException
     */
    private function prepare(array $params): array
    {
        [$attribute, $sources] = $this->optionSelection->resolve($params, 'option_ids');
        $targetId = (int)($params['target_option_id'] ?? 0);
        $options = $this->filterAttributes->options($attribute);
        if (!isset($options[$targetId])) {
            throw new LocalizedException(__(
                'Give "target_option_id": the option of "%1" to keep, from cleanup_options.',
                $attribute['code']
            ));
        }
        if (isset($sources[$targetId])) {
            throw new LocalizedException(__('The option to keep cannot also be merged away.'));
        }
        if ($this->filterAttributes->isConfigurableAttribute($attribute['attribute_id'])) {
            // Two variants of one configurable could end up with the same value and clash.
            throw new LocalizedException(__(
                'Configurable products use "%1" to tell their variants apart; merging its options could give '
                . 'two variants the same value. Merge these in the admin, product by product.',
                $attribute['code']
            ));
        }

        return [$attribute, $options[$targetId], $sources];
    }
}

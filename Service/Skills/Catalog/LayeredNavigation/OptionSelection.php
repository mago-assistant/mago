<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation;

use Magento\Framework\Exception\LocalizedException;

/**
 * The attribute and the options a delete or merge call names, refused when either does not exist
 */
class OptionSelection
{
    public function __construct(
        private readonly FilterAttributes $filterAttributes
    ) {
    }

    /**
     * @param array $params Call parameters with "attribute" and the option id list
     * @param string $key Parameter holding the option ids
     * @return array{0: array, 1: array<int, string>} Attribute; option id => admin label
     * @throws LocalizedException
     */
    public function resolve(array $params, string $key): array
    {
        $code = trim((string)($params['attribute'] ?? ''));
        $attribute = $code === '' ? null : $this->filterAttributes->attribute($code);
        if ($attribute === null) {
            throw new LocalizedException(
                __('There is no product attribute "%1". Use list_filters for the codes.', $code)
            );
        }
        if (!in_array($attribute['input'], FilterAttributes::OPTION_INPUTS, true)) {
            throw new LocalizedException(__('Attribute "%1" has no options.', $attribute['code']));
        }

        $requested = array_values(array_unique(array_map('intval', array_filter(
            is_array($params[$key] ?? null) ? $params[$key] : [],
            'is_numeric'
        ))));
        if ($requested === []) {
            throw new LocalizedException(__('Give "%1": the option ids from cleanup_options.', $key));
        }
        $options = $this->filterAttributes->options($attribute);
        $unknown = array_diff($requested, array_keys($options));
        if ($unknown !== []) {
            throw new LocalizedException(__(
                'Attribute "%1" has no option with id %2. Use cleanup_options for the ids.',
                $attribute['code'],
                implode(', ', $unknown)
            ));
        }

        return [$attribute, array_intersect_key($options, array_flip($requested))];
    }
}

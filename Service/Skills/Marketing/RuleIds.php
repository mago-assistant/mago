<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing;

/**
 * The website, customer group or category ids a model passed to a rule action, read one way for
 * both the caution card and the save. Magento's rule models split a comma list ("1,0") into ids
 * on save, so reading it as anything else would let the card show fewer groups than get the rule.
 */
class RuleIds
{
    /**
     * @return int[]|null null when the parameter was not given at all
     */
    public static function fromInput(mixed $ids): ?array
    {
        if ($ids === null) {
            return null;
        }
        $list = is_array($ids) ? implode(',', array_filter($ids, 'is_scalar')) : (string)$ids;

        return array_values(array_map(
            'intval',
            array_filter(
                array_map('trim', explode(',', $list)),
                static fn (string $id): bool => $id !== ''
            )
        ));
    }
}

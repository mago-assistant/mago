<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use MagoAssistant\Mago\Api\Tool\AvailabilityAwareToolInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class CatalogPriceRules extends AbstractSkill implements AvailabilityAwareToolInterface
{
    private const REQUIRED_MODULE = 'Magento_CatalogRule';

    public function __construct(
        AuthorizationInterface $authorization,
        private readonly ModuleManager $moduleManager,
        array $actions = []
    ) {
        parent::__construct($authorization, $actions);
    }

    public function isAvailable(): bool
    {
        return $this->moduleManager->isEnabled(self::REQUIRED_MODULE);
    }

    public function getName(): string
    {
        return 'catalog_price_rules';
    }

    protected function getBaseDescription(): string
    {
        return 'Manage catalog price rules: automatic discounts applied to product prices without coupon codes.';
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_CatalogRule::promo_catalog';
    }

    protected function getBaseInstructions(): string
    {
        return 'Catalog price rules differ from cart price rules: they modify the displayed product price '
            . 'on category/product pages, while cart price rules apply at checkout. '
            . 'Rules can reach products by category, SKU or attribute value (conditions); the confirmation card '
            . 'shows how many products match, so restate the conditions in plain words before calling. '
            . 'When an attribute or option name is refused as ambiguous or unknown, ask the user which one '
            . 'they mean instead of guessing. '
            . 'After creating, updating or deactivating a rule, follow the result message: when prices still '
            . 'wait for the indexer, propose apply_rules with that rule_id so they change right away. '
            . 'Include admin_url links in results.';
    }
}

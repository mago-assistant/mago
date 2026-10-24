<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;

class ProductData extends AbstractSkill
{
    public function __construct(
        AuthorizationInterface $authorization,
        private readonly EntityRouteMap $entityRouteMap,
        array $actions = []
    ) {
        parent::__construct($authorization, $actions);
    }

    public function getName(): string
    {
        return 'product_data';
    }

    /**
     * Every action here reads the catalog the Products grid shows, so its resource gates it
     * (#148). low_stock declares its own, which ToolAccess asks first.
     */
    public function getMagentoAcl(array $input = []): string
    {
        return $this->entityRouteMap->getListAclResource('product') ?? '';
    }

    protected function getBaseDescription(): string
    {
        return 'Query product catalog data.';
    }
}

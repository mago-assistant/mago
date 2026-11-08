<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;

class CategoryData extends AbstractSkill
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
        return 'category_data';
    }

    /**
     * Every action here reads the category tree the Categories screen shows, so its resource gates it.
     */
    public function getMagentoAcl(array $input = []): string
    {
        return $this->entityRouteMap->getListAclResource('category') ?? '';
    }

    protected function getBaseDescription(): string
    {
        return 'Query the category tree and the products in it.';
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Url;

/**
 * The one place per-entity admin routing knowledge lives. `AdminNavigator` and the page_form
 * skill's navigate-then-act path (task 009) both resolve an entity's edit URL through this map
 * rather than each keeping their own copy of it. It maps an entity type to its admin route and the
 * URL parameter key that route expects an id under, and answers which native Magento ACL resource
 * guards that entity - by asking `AdminRouteAcl` about the route it already holds, so the resource
 * is never a second list to keep in step with this one. Resolving a SKU or other identifier to an
 * id stays the model's own job through product_data / cms_data.
 */
class EntityRouteMap
{
    private const ROUTES = [
        'order' => 'sales/order/view',
        'invoice' => 'sales/invoice/view',
        'shipment' => 'sales/shipment/view',
        'creditmemo' => 'sales/creditmemo/view',
        'customer' => 'customer/index/edit',
        'product' => 'catalog/product/edit',
        'cms_page' => 'cms/page/edit',
        'cms_block' => 'cms/block/edit',
        'category' => 'catalog/category/edit',
    ];

    private const NEW_ROUTES = [
        'product' => 'catalog/product/new',
        'cms_page' => 'cms/page/new',
        'cms_block' => 'cms/block/new',
        'category' => 'catalog/category/add',
    ];

    private const PARAM_KEYS = [
        'order' => 'order_id',
        'invoice' => 'invoice_id',
        'shipment' => 'shipment_id',
        'creditmemo' => 'creditmemo_id',
        'customer' => 'id',
        'product' => 'id',
        'cms_page' => 'page_id',
        'cms_block' => 'block_id',
        'category' => 'id',
    ];

    public function __construct(
        private readonly AdminRouteAcl $adminRouteAcl
    ) {
    }

    public function getRoute(string $entityType): ?string
    {
        return self::ROUTES[$entityType] ?? null;
    }

    public function getNewRoute(string $entityType): ?string
    {
        return self::NEW_ROUTES[$entityType] ?? null;
    }

    public function getParamKey(string $entityType): ?string
    {
        return self::PARAM_KEYS[$entityType] ?? null;
    }

    /**
     * The resource Magento's own edit screen for this entity checks, so a tool changing an entity
     * is gated the way that screen is - whatever Magento has it be today.
     */
    public function getAclResource(string $entityType): ?string
    {
        $route = $this->getRoute($entityType);

        return $route === null ? null : $this->adminRouteAcl->forRoute($route);
    }

    /**
     * The resource Magento's own grid for this entity checks, for a tool that only reads. Reading
     * records is what the grid shows, where the edit screen can ask for more (cms/page/edit is
     * guarded by Magento_Cms::save, the grid by Magento_Cms::page). The grid is the edit route's
     * controller with its index action, for every entity type here - so it is not a second list.
     */
    public function getListAclResource(string $entityType): ?string
    {
        $route = $this->getRoute($entityType);
        if ($route === null) {
            return null;
        }

        $segments = explode('/', $route);
        if (count($segments) < 2) {
            return null;
        }

        return $this->adminRouteAcl->forRoute($segments[0] . '/' . $segments[1] . '/index');
    }

    /**
     * @return array<int,string>
     */
    public function getEntityTypes(): array
    {
        return array_keys(self::ROUTES);
    }
}

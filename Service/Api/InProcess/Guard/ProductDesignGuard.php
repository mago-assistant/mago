<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess\Guard;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\AuthorizationFactory as ProductAuthorizationFactory;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;

/**
 * Stands in for Magento's webapi_rest-only ProductAuthorization plugin: the product a save receives goes
 * through the same core check, for the admin the call acts for, so a design attribute only changes for
 * an admin with the edit product design permission.
 */
class ProductDesignGuard implements ServiceCallGuardInterface
{
    public const ACL_RESOURCE = 'Magento_Catalog::edit_product_design';
    private const SAVE_METHOD = 'save';

    public function __construct(
        private readonly ProductAuthorizationFactory $productAuthorizationFactory
    ) {
    }

    public function guard(ResolvedRoute $route, array $arguments, AuthorizationInterface $authorization): void
    {
        $product = $this->findSavedProduct($route, $arguments);
        if ($product === null) {
            return;
        }

        try {
            $this->productAuthorizationFactory->create(['authorization' => $authorization])
                ->authorizeSavingOf($product);
        } catch (AuthorizationException $refusal) {
            throw DesignChangeRefusedException::fromCoreRefusal($refusal, self::ACL_RESOURCE);
        }
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function findSavedProduct(ResolvedRoute $route, array $arguments): ?ProductInterface
    {
        if (!$route->isServiceMethod(ProductRepositoryInterface::class, self::SAVE_METHOD)) {
            return null;
        }

        $product = $arguments[0] ?? null;

        return $product instanceof ProductInterface ? $product : null;
    }
}

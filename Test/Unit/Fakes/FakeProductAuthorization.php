<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Authorization;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\ProductDesignGuard;

/**
 * Magento's product design check without the database: a product that changes its design is refused,
 * with core's message, unless the admin's ACL allows design changes. The products it saw are recorded.
 */
final class FakeProductAuthorization extends Authorization
{
    /** @var list<ProductInterface> */
    private array $checkedProducts = [];

    public function __construct(
        private readonly AuthorizationInterface $adminAuthorization,
        private readonly bool $isDesignChanged
    ) {
    }

    public function authorizeSavingOf(ProductInterface $product): void
    {
        $this->checkedProducts[] = $product;
        if ($this->isDesignChanged && !$this->adminAuthorization->isAllowed(ProductDesignGuard::ACL_RESOURCE)) {
            throw new AuthorizationException(__('Not allowed to edit the product\'s design attributes'));
        }
    }

    public function adminAuthorization(): AuthorizationInterface
    {
        return $this->adminAuthorization;
    }

    /**
     * @return list<ProductInterface>
     */
    public function checkedProducts(): array
    {
        return $this->checkedProducts;
    }
}

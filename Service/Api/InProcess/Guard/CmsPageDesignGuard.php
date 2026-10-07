<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess\Guard;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page\AuthorizationFactory as PageAuthorizationFactory;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;

/**
 * Stands in for Magento's webapi_rest-only PageAclPlugin: the page a save receives goes through the same
 * core check, for the admin the call acts for, so a page's layout, theme or layout update only changes
 * for an admin with the save design permission.
 */
class CmsPageDesignGuard implements ServiceCallGuardInterface
{
    public const ACL_RESOURCE = 'Magento_Cms::save_design';
    private const SAVE_METHOD = 'save';

    public function __construct(
        private readonly PageAuthorizationFactory $pageAuthorizationFactory
    ) {
    }

    public function guard(ResolvedRoute $route, array $arguments, AuthorizationInterface $authorization): void
    {
        $page = $this->findSavedPage($route, $arguments);
        if ($page === null) {
            return;
        }

        try {
            $this->pageAuthorizationFactory->create(['authorization' => $authorization])->authorizeFor($page);
        } catch (AuthorizationException $refusal) {
            throw DesignChangeRefusedException::fromCoreRefusal($refusal, self::ACL_RESOURCE);
        }
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function findSavedPage(ResolvedRoute $route, array $arguments): ?PageInterface
    {
        if (!$route->isServiceMethod(PageRepositoryInterface::class, self::SAVE_METHOD)) {
            return null;
        }

        $page = $arguments[0] ?? null;

        return $page instanceof PageInterface ? $page : null;
    }
}

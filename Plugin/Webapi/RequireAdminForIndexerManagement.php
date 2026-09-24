<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Plugin\Webapi;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Webapi\Controller\Rest\RequestValidator;
use Magento\Webapi\Controller\Rest\Router;
use MagoAssistant\Mago\Api\WebApi\IndexerManagementInterface;

class RequireAdminForIndexerManagement
{
    public function __construct(
        private readonly Router $router,
        private readonly Request $request,
        private readonly UserContextInterface $userContext
    ) {
    }

    /**
     * @param RequestValidator $subject
     * @param mixed $result
     * @return mixed
     * @throws AuthorizationException
     */
    public function afterValidate(RequestValidator $subject, mixed $result): mixed
    {
        $route = $this->router->match($this->request);
        if ($route->getServiceClass() !== IndexerManagementInterface::class) {
            return $result;
        }

        $isAdmin = (int)$this->userContext->getUserType() === UserContextInterface::USER_TYPE_ADMIN;
        if (!$isAdmin || !(int)$this->userContext->getUserId()) {
            throw new AuthorizationException(__('This endpoint requires an admin user token.'));
        }

        return $result;
    }
}

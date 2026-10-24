<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Plugin\Controller\Adminhtml;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Model\Config\AssistantDisabledException;
use MagoAssistant\Mago\Model\Config\EnabledGuard;

/**
 * Answers the admin chat controllers with an error while the assistant is disabled, in the
 * {"error": ...} shape the panel already shows. Wraps execute(), so the backend's ACL and form key
 * checks in dispatch() still run first. The Stream controller has its own check, as it has to
 * answer with a server-sent event instead of JSON.
 */
class RequireAssistantEnabled
{
    public function __construct(
        private readonly EnabledGuard $enabledGuard,
        private readonly ResultFactory $resultFactory
    ) {
    }

    public function aroundExecute(ActionInterface $subject, callable $proceed): ResultInterface|ResponseInterface
    {
        try {
            $this->enabledGuard->assertEnabled();
        } catch (AssistantDisabledException $e) {
            /** @var Json $result */
            $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

            return $result->setData(['error' => $e->getMessage()]);
        }

        return $proceed();
    }
}

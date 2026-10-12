<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Plugin\Webapi;

use MagoAssistant\Mago\Api\WebApi\ChatManagementInterface;
use MagoAssistant\Mago\Api\WebApi\IndexerManagementInterface;
use MagoAssistant\Mago\Model\Config\AssistantDisabledException;
use MagoAssistant\Mago\Model\Config\EnabledGuard;

/**
 * Refuses every service method behind etc/webapi.xml while the assistant is disabled. Sits on the
 * service interfaces rather than the REST request validator so SOAP is covered too, and throws
 * before the method's own error handling, so the caller gets the message as a 400 (REST) or a
 * fault (SOAP). A route added to etc/webapi.xml needs a method here; a unit test checks that.
 */
class RequireAssistantEnabled
{
    public function __construct(
        private readonly EnabledGuard $enabledGuard
    ) {
    }

    /**
     * @throws AssistantDisabledException
     */
    public function beforeSendMessage(ChatManagementInterface $subject): void
    {
        $this->enabledGuard->assertEnabled();
    }

    /**
     * @throws AssistantDisabledException
     */
    public function beforeGetConversations(ChatManagementInterface $subject): void
    {
        $this->enabledGuard->assertEnabled();
    }

    /**
     * @throws AssistantDisabledException
     */
    public function beforeGetConversation(ChatManagementInterface $subject): void
    {
        $this->enabledGuard->assertEnabled();
    }

    /**
     * @throws AssistantDisabledException
     */
    public function beforeDeleteConversation(ChatManagementInterface $subject): void
    {
        $this->enabledGuard->assertEnabled();
    }

    /**
     * @throws AssistantDisabledException
     */
    public function beforeConfirmAction(ChatManagementInterface $subject): void
    {
        $this->enabledGuard->assertEnabled();
    }

    /**
     * @throws AssistantDisabledException
     */
    public function beforeRejectAction(ChatManagementInterface $subject): void
    {
        $this->enabledGuard->assertEnabled();
    }

    /**
     * @throws AssistantDisabledException
     */
    public function beforeReindexAll(IndexerManagementInterface $subject): void
    {
        $this->enabledGuard->assertEnabled();
    }

    /**
     * @throws AssistantDisabledException
     */
    public function beforeReindex(IndexerManagementInterface $subject): void
    {
        $this->enabledGuard->assertEnabled();
    }
}

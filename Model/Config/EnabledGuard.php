<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config;

use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * The one check behind the enabled switch for every entry point outside the chat panel's stream:
 * the REST routes under /V1/mago and the admin chat controllers.
 */
class EnabledGuard
{
    public function __construct(
        private readonly ConfigRepository $configRepository
    ) {
    }

    /**
     * @return void
     * @throws AssistantDisabledException
     */
    public function assertEnabled(): void
    {
        if (!$this->configRepository->isEnabled()) {
            throw new AssistantDisabledException();
        }
    }
}

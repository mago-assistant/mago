<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Voice;

use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * The voice input part of the chat panel's JS config: whether the microphone is offered, and the
 * BCP-47 tag the browser listens in, taken from the admin user's interface locale (nl_NL is nl-NL).
 */
class PanelConfig
{
    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly LocaleResolver $localeResolver
    ) {
    }

    /**
     * @return array{voiceInput: bool, voiceLocale?: string}
     */
    public function getJsConfig(): array
    {
        if (!$this->configRepository->isVoiceInputEnabled()) {
            return ['voiceInput' => false];
        }

        return [
            'voiceInput' => true,
            'voiceLocale' => str_replace('_', '-', $this->localeResolver->getLocale()),
        ];
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * BCP-47 tags the browser's speech recogniser is asked to listen in. "auto" follows the admin
 * user's interface locale, which is wrong for the common case of an English admin who speaks
 * Dutch to it, hence the explicit list.
 */
class SpeechLanguage implements OptionSourceInterface
{
    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto', 'label' => __('Admin interface locale')],
            ['value' => 'en-US', 'label' => __('English (US)')],
            ['value' => 'en-GB', 'label' => __('English (UK)')],
            ['value' => 'nl-NL', 'label' => __('Nederlands (Dutch)')],
            ['value' => 'nl-BE', 'label' => __('Nederlands (Belgium)')],
            ['value' => 'de-DE', 'label' => __('Deutsch (German)')],
            ['value' => 'fr-FR', 'label' => __('Français (French)')],
            ['value' => 'es-ES', 'label' => __('Español (Spanish)')],
            ['value' => 'it-IT', 'label' => __('Italiano (Italian)')],
            ['value' => 'pt-PT', 'label' => __('Português (Portuguese)')],
            ['value' => 'pt-BR', 'label' => __('Português (Brazil)')],
            ['value' => 'pl-PL', 'label' => __('Polski (Polish)')],
            ['value' => 'sv-SE', 'label' => __('Svenska (Swedish)')],
            ['value' => 'da-DK', 'label' => __('Dansk (Danish)')],
        ];
    }
}

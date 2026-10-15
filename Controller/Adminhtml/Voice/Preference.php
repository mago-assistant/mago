<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Voice;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\VoicePreferenceRepositoryInterface;
use MagoAssistant\Mago\Controller\Adminhtml\Chat\FormKeyJsonValidation;
use MagoAssistant\Mago\Model\Config\Source\VoiceMode;
use MagoAssistant\Mago\Service\Error\ErrorReporter;

/**
 * Saves the logged-in admin's own voice settings from the chat panel: {"consent": true} from the
 * consent card, {"mode": "confirm"} from the voice mode menu. Only ever for the session's user.
 */
class Preference extends Action implements HttpPostActionInterface
{
    use FormKeyJsonValidation;

    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::assistant_read';

    private const MODES = [VoiceMode::DICTATE, VoiceMode::AUTO_SEND, VoiceMode::CONFIRM];

    public function __construct(
        Context $context,
        private readonly VoicePreferenceRepositoryInterface $voicePreferenceRepository,
        private readonly JsonFactory $jsonFactory,
        private readonly Json $json,
        private readonly ErrorReporter $errorReporter,
        private readonly FormKey $formKey
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $user = $this->_auth->getUser();
            $adminUserId = $user ? (int)$user->getId() : 0;
            if (!$adminUserId) {
                return $result->setData(['error' => 'Not authorized']);
            }

            $body = $this->json->unserialize((string)$this->getRequest()->getContent());
            $body = is_array($body) ? $body : [];

            if (array_key_exists('mode', $body) && !in_array($body['mode'], self::MODES, true)) {
                return $result->setData(['error' => 'Unknown voice mode']);
            }

            if (($body['consent'] ?? false) === true) {
                $this->voicePreferenceRepository->recordConsent($adminUserId);
            }

            if (array_key_exists('mode', $body)) {
                $this->voicePreferenceRepository->setMode($adminUserId, $body['mode']);
            }

            return $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            return $result->setData(['error' => $this->errorReporter->report('Voice Preference Controller', $e)]);
        }
    }
}

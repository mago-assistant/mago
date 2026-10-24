<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\ConversationRepositoryInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;

/**
 * Fallback endpoint to check if a conversation has a pending confirmation.
 * Used when SSE done event is lost due to nginx/proxy buffering.
 */
class Status extends Action implements HttpPostActionInterface
{
    use FormKeyJsonValidation;

    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::assistant_read';

    public function __construct(
        Context $context,
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly JsonFactory $jsonFactory,
        private readonly Json $json,
        private readonly FormKey $formKey,
        private readonly ErrorReporter $errorReporter
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $rawBody = $this->getRequest()->getContent();
            $postData = $this->json->unserialize($rawBody);
            $conversationId = (int)($postData['conversation_id'] ?? 0);

            if (!$conversationId) {
                return $result->setData(['pending_confirmation' => false]);
            }

            $user = $this->_auth->getUser();
            $adminUserId = $user ? (int)$user->getId() : 0;
            if (!$adminUserId) {
                return $result->setData(['error' => 'Not authorized']);
            }

            // Assert ownership before reading the conversation's messages
            $this->conversationRepository->getByIdForUser($conversationId, $adminUserId);
            $messages = $this->conversationRepository->getMessages($conversationId);
            $lastAssistant = null;
            foreach (array_reverse($messages) as $msg) {
                if (($msg['role'] ?? '') === 'assistant') {
                    $lastAssistant = $msg;
                    break;
                }
            }

            if ($lastAssistant && !empty($lastAssistant['pending_confirmation'])) {
                return $result->setData([
                    'pending_confirmation' => true,
                    'message_id' => (int)$lastAssistant['entity_id'],
                    'content' => $lastAssistant['content'] ?? '',
                ]);
            }

            return $result->setData(['pending_confirmation' => false]);
        } catch (\Throwable $e) {
            return $result->setData([
                'pending_confirmation' => false,
                'error' => $this->errorReporter->report('Status Controller', $e),
            ]);
        }
    }
}

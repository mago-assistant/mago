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
use MagoAssistant\Mago\Api\ConversationRepositoryInterface;
use MagoAssistant\Mago\Service\Flag\FlagRepository;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;

class Load extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::assistant_read';

    public function __construct(
        Context $context,
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly JsonFactory $jsonFactory,
        private readonly PrivacyService $privacyService,
        private readonly FlagRepository $flagRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $conversationId = (int)$this->getRequest()->getParam('conversation_id', 0);

            if (!$conversationId) {
                return $result->setData(['error' => 'Conversation ID is required']);
            }

            $user = $this->_auth->getUser();
            $adminUserId = $user ? (int)$user->getId() : 0;
            if (!$adminUserId) {
                return $result->setData(['error' => 'Not authorized']);
            }

            $conversation = $this->conversationRepository->getByIdForUser($conversationId, $adminUserId);
            $messages = $this->conversationRepository->getMessages($conversationId);

            // Stored messages are tokenised; rehydrate them for the admin's history view, using the
            // conversation's own vault. A no-op when the vault is empty (no persistence yet).
            $this->privacyService->beginConversation($conversationId);
            foreach ($messages as $index => $message) {
                if (isset($message['content']) && is_string($message['content'])) {
                    $messages[$index]['content'] = $this->privacyService->displayText($message['content']);
                }
            }

            // Which answers already carry a flag, so a reloaded conversation shows them as flagged
            // instead of offering to flag them again.
            $flagged = array_flip($this->flagRepository->flaggedAmong(array_column($messages, 'entity_id')));
            foreach ($messages as $index => $message) {
                $messages[$index]['flagged'] = isset($flagged[(int)($message['entity_id'] ?? 0)]);
            }

            return $result->setData([
                'entity_id' => $conversation['entity_id'],
                'title' => $this->privacyService->displayText((string)$conversation['title']),
                'messages' => $messages,
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['error' => $e->getMessage()]);
        }
    }
}

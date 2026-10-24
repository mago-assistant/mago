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
use MagoAssistant\Mago\Service\Error\ErrorReporter;
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
        private readonly FlagRepository $flagRepository,
        private readonly ErrorReporter $errorReporter
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

            // Stored messages are tokenised and go out that way, with the conversation's own vault
            // values beside them: the panel renders each message first and only then puts the
            // values in, as text, so a value can never become markup.
            $this->privacyService->beginConversation($conversationId);

            // How answers were already rated, so a reloaded conversation shows its thumbs instead of
            // offering to rate them again.
            $ratings = $this->flagRepository->ratingsAmong(array_column($messages, 'entity_id'));
            foreach ($messages as $index => $message) {
                $messages[$index]['rating'] = $ratings[(int)($message['entity_id'] ?? 0)] ?? null;
            }

            return $result->setData([
                'entity_id' => $conversation['entity_id'],
                'title' => $this->privacyService->displayText((string)$conversation['title']),
                'messages' => $messages,
                'tokens' => $this->tokenValuesOf($messages),
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['error' => $this->errorReporter->report('Load Controller', $e)]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, string>
     */
    private function tokenValuesOf(array $messages): array
    {
        return $this->privacyService->tokenValues(
            implode("\n", array_filter(array_column($messages, 'content'), 'is_string'))
        );
    }
}

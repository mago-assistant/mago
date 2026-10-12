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
use MagoAssistant\Mago\Service\Flag\FlagRepository;

/**
 * Rates an answer with a thumbs up or down, adds the optional note to that rating, or takes it off
 * again. The panel calls this from the thumbs under an assistant message; rating reads nothing but
 * the conversation the admin already has open, so a read grant is enough.
 */
class Flag extends Action implements HttpPostActionInterface
{
    use FormKeyJsonValidation;

    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::assistant_read';

    /** A note is a sentence about the answer, not a place to paste a log */
    private const MAX_NOTE_LENGTH = 2000;

    public function __construct(
        Context $context,
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly FlagRepository $flagRepository,
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
            $postData = $this->json->unserialize((string)$this->getRequest()->getContent());
            $messageId = (int)($postData['message_id'] ?? 0);

            if ($messageId <= 0) {
                return $result->setData(['error' => 'message_id is required']);
            }

            $user = $this->_auth->getUser();
            $adminUserId = $user ? (int)$user->getId() : 0;
            if ($adminUserId === 0) {
                return $result->setData(['error' => 'Not authorized']);
            }

            // Throws when the message is not in a conversation of this admin, which is the whole
            // ownership check: feedback must not be a way to read someone else's conversation.
            $this->conversationRepository->getMessageForUser($messageId, $adminUserId);

            if (($postData['remove'] ?? false) === true) {
                return $this->flagRepository->unflag($messageId, $adminUserId)
                    ? $result->setData(['rating' => null])
                    : $result->setData(['error' => 'This feedback can only be removed under Answer Feedback']);
            }

            $rating = (string)($postData['rating'] ?? FlagRepository::RATING_DOWN);
            if (!in_array($rating, [FlagRepository::RATING_UP, FlagRepository::RATING_DOWN], true)) {
                return $result->setData(['error' => 'rating must be up or down']);
            }

            $note = mb_substr(trim((string)($postData['note'] ?? '')), 0, self::MAX_NOTE_LENGTH);
            $flagId = $this->flagRepository->flag($messageId, $adminUserId, $note, $rating);

            if ($flagId === null) {
                return $result->setData(['error' => 'Only an assistant answer can be rated']);
            }

            // What is stored, not what was asked: feedback someone already resolved keeps its rating,
            // and the panel should show that rather than a thumb that did not stick.
            $stored = $this->flagRepository->getById($flagId);

            return $result->setData([
                'rating' => (string)($stored['rating'] ?? $rating),
                'note_saved' => $note !== '' && ($stored['note'] ?? null) === $note,
                'flag_id' => $flagId,
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['error' => $this->errorReporter->report('Flag Controller', $e)]);
        }
    }
}

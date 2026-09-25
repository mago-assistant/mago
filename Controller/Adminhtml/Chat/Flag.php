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
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Flag\FlagRepository;

/**
 * Flags an answer, or takes the flag off again. The panel calls this from the flag button under an
 * assistant message; flagging reads nothing but the conversation the admin already has open, so a
 * read grant is enough.
 */
class Flag extends Action implements HttpPostActionInterface
{
    use FormKeyJsonValidation;

    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::assistant_read';

    /** A note is a sentence about what went wrong, not a place to paste a log */
    private const MAX_NOTE_LENGTH = 2000;

    public function __construct(
        Context $context,
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly FlagRepository $flagRepository,
        private readonly JsonFactory $jsonFactory,
        private readonly Json $json,
        private readonly ErrorLogger $errorLogger,
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

            if (!$messageId) {
                return $result->setData(['error' => 'message_id is required']);
            }

            $user = $this->_auth->getUser();
            $adminUserId = $user ? (int)$user->getId() : 0;
            if (!$adminUserId) {
                return $result->setData(['error' => 'Not authorized']);
            }

            // Throws when the message is not in a conversation of this admin, which is the whole
            // ownership check: a flag must not be a way to read someone else's conversation.
            $this->conversationRepository->getMessageForUser($messageId, $adminUserId);

            if (!empty($postData['remove'])) {
                $this->flagRepository->unflag($messageId);

                return $result->setData(['flagged' => false]);
            }

            $note = mb_substr(trim((string)($postData['note'] ?? '')), 0, self::MAX_NOTE_LENGTH);
            $flag = $this->flagRepository->flag($messageId, $adminUserId, $note);

            if ($flag === null) {
                return $result->setData(['error' => 'Only an assistant answer can be flagged']);
            }

            return $result->setData(['flagged' => true, 'flag_id' => $flag['id']]);
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('Flag Controller', $e->getMessage());

            return $result->setData(['error' => $e->getMessage()]);
        }
    }
}

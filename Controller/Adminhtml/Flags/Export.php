<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Flags;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Service\Flag\FlagRepository;

/**
 * Downloads one flag, or every flag matching the grid selection, as a JSON bundle.
 *
 * The bundle is the stored snapshot, unedited: the conversation around the answer, the tool calls,
 * and where they were still there at flag time the payloads that went to and came back from the
 * provider. It is meant to be attached to an issue, so it can carry store data — the screen says so
 * before the download starts.
 *
 * Sent straight from memory: FileFactory would first write the bundle to var/ and leave it there,
 * a copy of the conversation that outlives deleting the flag.
 */
class Export extends Action implements HttpGetActionInterface
{
    use ReadsConversations;

    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::flags_export';

    public function __construct(
        Context $context,
        private readonly FlagRepository $flagRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $flagId = (int)$this->getRequest()->getParam('id');
        $flag = $flagId ? $this->flagRepository->getById($flagId) : null;

        if (!$flag) {
            $this->messageManager->addErrorMessage((string)__('This feedback no longer exists.'));

            return $this->resultRedirectFactory->create()->setPath('mago/flags/index');
        }

        $bundle = [
            'flag' => [
                'id' => (int)$flag['entity_id'],
                'status' => (string)$flag['status'],
                'rating' => (string)$flag['rating'],
                'note' => $flag['note'] ?? null,
                'flagged_at' => (string)$flag['created_at'],
            ],
            'snapshot' => $this->flagRepository->snapshot($flag),
        ];

        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);

        return $result
            ->setHeader('Content-Type', 'application/json', true)
            ->setHeader('Content-Disposition', 'attachment; filename="mago-flag-' . $flagId . '.json"', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setContents(
                (string)json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
    }
}

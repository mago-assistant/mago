<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Flags;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Service\Flag\FlagRepository;

/**
 * Downloads one flag, or every flag matching the grid selection, as a JSON bundle.
 *
 * The bundle is the stored snapshot, unedited: the conversation around the answer, the tool calls,
 * and where they were still there at flag time the payloads that went to and came back from the
 * provider. It is meant to be attached to an issue, so it can carry store data — the screen says so
 * before the download starts.
 */
class Export extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::flags_export';

    public function __construct(
        Context $context,
        private readonly FlagRepository $flagRepository,
        private readonly FileFactory $fileFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface|ResponseInterface
    {
        $flagId = (int)$this->getRequest()->getParam('id');
        $flag = $flagId ? $this->flagRepository->getById($flagId) : null;

        if (!$flag) {
            $this->messageManager->addErrorMessage((string)__('This flagged answer no longer exists.'));

            return $this->resultRedirectFactory->create()->setPath('mago/flags/index');
        }

        $bundle = [
            'flag' => [
                'id' => (int)$flag['entity_id'],
                'status' => (string)$flag['status'],
                'note' => $flag['note'] ?? null,
                'flagged_at' => (string)$flag['created_at'],
            ],
            'snapshot' => $this->flagRepository->snapshot($flag),
        ];

        return $this->fileFactory->create(
            'mago-flag-' . $flagId . '.json',
            (string)json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'var',
            'application/json'
        );
    }
}

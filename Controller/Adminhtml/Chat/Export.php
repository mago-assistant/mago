<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Export\ExportRefusedException;
use MagoAssistant\Mago\Service\Export\ToolCallExport;

/**
 * Downloads the full result of a read-only tool call from one of the admin's own conversations,
 * as CSV or Excel (#298). The chat panel links here from the call's line.
 */
class Export extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::assistant_read';

    private const HTTP_FORBIDDEN = 403;

    private const HTTP_SERVER_ERROR = 500;

    public function __construct(
        Context $context,
        private readonly ToolCallExport $toolCallExport,
        private readonly ErrorReporter $errorReporter
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $user = $this->_auth->getUser();
        try {
            $file = $this->toolCallExport->export(
                (int)$this->getRequest()->getParam('conversation_id'),
                (string)$this->getRequest()->getParam('tool_call_id'),
                (string)$this->getRequest()->getParam('format'),
                $user ? (int)$user->getId() : 0
            );
        } catch (ExportRefusedException $e) {
            return $this->refusal($e->getMessage(), self::HTTP_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->refusal($this->errorReporter->report('Export Controller', $e), self::HTTP_SERVER_ERROR);
        }

        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', $file->contentType, true)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $file->fileName . '"', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setContents($file->contents);

        return $result;
    }

    /**
     * The panel downloads in the background and shows the reason in the chat, so the admin stays on
     * the page they were on. A link opened on its own goes back there with the reason as a message.
     * A failure the export did not foresee (a database error, a stored period that no longer
     * parses) is logged, and the admin gets a reference to it instead of an error page.
     */
    private function refusal(string $reason, int $httpCode): ResultInterface
    {
        $request = $this->getRequest();
        if ($request instanceof Http && $request->isAjax()) {
            /** @var Json $json */
            $json = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            return $json->setHttpResponseCode($httpCode)->setData(['error' => $reason]);
        }

        $this->messageManager->addErrorMessage($reason);
        return $this->resultRedirectFactory->create()->setRefererOrBaseUrl();
    }
}

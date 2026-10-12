<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Skills;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::skills_read';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly ToolRegistry $toolRegistry
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $skillName = $this->getRequest()->getParam('skill_name');
        $tool = $skillName ? $this->toolRegistry->getToolByName($skillName) : null;

        if (!$tool) {
            $this->messageManager->addErrorMessage(__('Skill not found.'));
            return $this->resultRedirectFactory->create()->setPath('mago/skills/index');
        }

        $resultPage = $this->pageFactory->create();
        $resultPage->setActiveMenu('MagoAssistant_Mago::skills');
        $resultPage->getConfig()->getTitle()->prepend((string)__('Edit Skill: %1', $tool->getName()));
        return $resultPage;
    }
}

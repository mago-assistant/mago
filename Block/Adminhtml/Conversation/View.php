<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Block\Adminhtml\Conversation;

use Magento\Backend\Block\Widget\Container;
use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

class View extends Container
{
    public function __construct(
        Context $context,
        private readonly AdminSession $adminSession,
        private readonly ResourceConnection $resourceConnection,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected $_template = 'MagoAssistant_Mago::conversations/container.phtml';

    protected function _construct(): void
    {
        parent::_construct();

        $conversationId = (int)$this->getRequest()->getParam('id');

        $this->buttonList->add(
            'back',
            [
                'label' => __('Back'),
                'onclick' => sprintf("setLocation('%s')", $this->getUrl('mago/conversations/index')),
                'class' => 'back',
            ]
        );

        if ($this->_authorization->isAllowed('MagoAssistant_Mago::conversations_delete') && $conversationId) {
            $this->buttonList->add(
                'delete',
                [
                    'label' => __('Delete'),
                    // Deleting changes state, so the controller only takes POST: deleteConfirm posts
                    // the id with the form key, the way the flag view and Magento's own pages do.
                    'onclick' => sprintf(
                        'deleteConfirm(%s, %s, %s)',
                        $this->jsLiteral((string)__('Are you sure you want to delete this conversation?')),
                        $this->jsLiteral($this->getUrl('mago/conversations/delete')),
                        $this->jsLiteral(['data' => ['id' => $conversationId]])
                    ),
                    'class' => 'delete',
                ]
            );
        }

        if ($conversationId && $this->isOwnConversation($conversationId)) {
            $this->buttonList->add(
                'continue',
                [
                    'label' => __('Continue Conversation'),
                    'onclick' => sprintf(
                        "setLocation('%s')",
                        $this->getUrl('mago/conversations/continueChat', ['id' => $conversationId])
                    ),
                    'class' => 'primary',
                ]
            );
        }
    }

    private function isOwnConversation(int $conversationId): bool
    {
        $currentUserId = (int)($this->adminSession->getUser()?->getId() ?? 0);
        if ($currentUserId === 0) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_conversation');
        $ownerId = (int)$connection->fetchOne(
            $connection->select()->from($table, ['admin_user_id'])->where('entity_id = ?', $conversationId)
        );

        return $currentUserId === $ownerId;
    }

    /**
     * A value as a JavaScript literal that is also safe inside the button's onclick attribute.
     *
     * @param string|array<string, mixed> $value
     */
    private function jsLiteral(string|array $value): string
    {
        return (string)json_encode($value, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}

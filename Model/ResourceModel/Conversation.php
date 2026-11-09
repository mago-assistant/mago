<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * The conversation table as a resource model.
 *
 * Like Flag, this exists only because the conversations grid collection has to be given a concrete
 * resource model (#310); the module itself reads and writes conversations through the connection.
 */
class Conversation extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('mago_conversation', 'entity_id');
    }
}

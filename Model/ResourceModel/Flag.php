<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * The flag table as a resource model.
 *
 * The module talks to its tables through the connection rather than through models, and
 * FlagRepository still does. This exists for one reason: a UI grid collection has to be given a
 * concrete resource model, and Magento will not take an abstract one.
 */
class Flag extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('mago_flag', 'entity_id');
    }
}

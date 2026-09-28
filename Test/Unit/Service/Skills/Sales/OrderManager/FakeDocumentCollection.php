<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Select;

final class FakeDocumentCollection extends AbstractDb
{
    public function __construct(Select $select)
    {
        $this->_select = $select;
    }

    public function getResource(): null
    {
        return null;
    }

    public function getSize(): int
    {
        return 0;
    }

    public function setOrder($field, $direction = self::SORT_ORDER_DESC): self
    {
        return $this;
    }

    public function setPageSize($size): self
    {
        return $this;
    }

    public function addFieldToFilter($field, $condition = null): self
    {
        return $this;
    }
}

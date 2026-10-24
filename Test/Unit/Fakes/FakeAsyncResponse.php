<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\AsynchronousOperations\Api\Data\AsyncResponseExtensionInterface;
use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;

final class FakeAsyncResponse implements AsyncResponseInterface
{
    /**
     * @param array<int, mixed> $requestItems
     */
    public function __construct(
        private string $bulkUuid,
        private bool $isErrors = false,
        private array $requestItems = []
    ) {
    }

    public function getBulkUuid()
    {
        return $this->bulkUuid;
    }

    public function setBulkUuid($bulkUuid)
    {
        $this->bulkUuid = (string)$bulkUuid;

        return $this;
    }

    public function getRequestItems()
    {
        return $this->requestItems;
    }

    public function setRequestItems($requestItems)
    {
        $this->requestItems = (array)$requestItems;

        return $this;
    }

    public function setErrors($isErrors = false)
    {
        $this->isErrors = (bool)$isErrors;

        return $this;
    }

    public function isErrors()
    {
        return $this->isErrors;
    }

    public function getExtensionAttributes()
    {
        return null;
    }

    public function setExtensionAttributes(AsyncResponseExtensionInterface $extensionAttributes)
    {
        return $this;
    }
}

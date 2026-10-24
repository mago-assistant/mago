<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface;
use Magento\Framework\Reflection\DataObjectProcessor;

/**
 * Turns an async response into the array Magento's DataObjectProcessor builds from it.
 */
final class FakeAsyncResponseProcessor extends DataObjectProcessor
{
    public function __construct()
    {
    }

    public function buildOutputDataArray($dataObject, $dataObjectType)
    {
        if (!$dataObject instanceof AsyncResponseInterface) {
            throw new \InvalidArgumentException('Only an async response can be processed.');
        }

        return [
            'bulk_uuid' => $dataObject->getBulkUuid(),
            'request_items' => $dataObject->getRequestItems(),
            'errors' => $dataObject->isErrors(),
        ];
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Plugin\Controller\Adminhtml;

use Magento\Framework\Controller\Result\Json;

/**
 * Keeps the data it was given instead of serialising it, so a test can read it back
 */
final class RecordingJsonResult extends Json
{
    private mixed $recordedData = null;

    public function __construct()
    {
    }

    /**
     * @param mixed $data
     * @param bool $cycleCheck
     * @param array<string, mixed> $options
     * @return $this
     */
    public function setData($data, $cycleCheck = false, $options = [])
    {
        $this->recordedData = $data;

        return $this;
    }

    public function getRecordedData(): mixed
    {
        return $this->recordedData;
    }
}

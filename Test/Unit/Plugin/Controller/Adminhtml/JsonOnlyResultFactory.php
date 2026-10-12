<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Plugin\Controller\Adminhtml;

use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Hands out recording JSON results and remembers the last one, without an object manager
 */
final class JsonOnlyResultFactory extends ResultFactory
{
    public ?RecordingJsonResult $lastResult = null;

    public function __construct()
    {
    }

    /**
     * @param string $type
     * @param array<string, mixed> $arguments
     * @return ResultInterface
     */
    public function create($type, array $arguments = [])
    {
        if ($type !== self::TYPE_JSON) {
            throw new \InvalidArgumentException('Only JSON results are expected, got ' . $type);
        }
        $this->lastResult = new RecordingJsonResult();

        return $this->lastResult;
    }
}

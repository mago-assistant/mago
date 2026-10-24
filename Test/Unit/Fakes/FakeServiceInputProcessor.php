<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\Webapi\ServiceInputProcessor;

/**
 * Passes the route's input values on as the service arguments, in order.
 */
final class FakeServiceInputProcessor extends ServiceInputProcessor
{
    public function __construct()
    {
    }

    public function process($serviceClassName, $serviceMethodName, array $inputArray)
    {
        return array_values($inputArray);
    }
}

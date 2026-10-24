<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\Webapi\Validator\EntityArrayValidator\InputArraySizeLimitValue;

final class FakeInputArraySizeLimitValue extends InputArraySizeLimitValue
{
    private ?int $value = null;

    public function __construct()
    {
    }

    public function set(?int $value): void
    {
        $this->value = $value;
    }

    public function get(): ?int
    {
        return $this->value;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Service\Tool\Verify\AdminAreaInterface;

final class FakeAdminArea implements AdminAreaInterface
{
    private bool $isEntered = false;

    public function enter(): void
    {
        $this->isEntered = true;
    }

    public function isEntered(): bool
    {
        return $this->isEntered;
    }
}

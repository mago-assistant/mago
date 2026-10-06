<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Config;

use MagoAssistant\Mago\Model\Config\AssistantDisabledException;
use MagoAssistant\Mago\Model\Config\EnabledGuard;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnabledGuardTest extends TestCase
{
    #[Test]
    public function itRefusesWhenTheAssistantIsDisabled(): void
    {
        $guard = new EnabledGuard((new FakeConfigRepository())->withEnabled(false));

        $this->expectException(AssistantDisabledException::class);
        $this->expectExceptionMessage('The assistant is currently disabled.');

        $guard->assertEnabled();
    }

    #[Test]
    public function itPassesWhenTheAssistantIsEnabled(): void
    {
        $guard = new EnabledGuard((new FakeConfigRepository())->withEnabled(true));

        $guard->assertEnabled();

        $this->addToAssertionCount(1);
    }
}

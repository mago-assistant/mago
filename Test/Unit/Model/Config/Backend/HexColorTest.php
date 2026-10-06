<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Config\Backend;

use Magento\Framework\Exception\ValidatorException;
use MagoAssistant\Mago\Model\Config\Backend\HexColor;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeEventManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HexColorTest extends TestCase
{
    #[Test]
    public function itLetsAHexColorThroughToTheSave(): void
    {
        $eventManager = new FakeEventManager();
        $backend = $this->backendWithValue('#1A2b3C', $eventManager);

        $backend->beforeSave();

        self::assertContains('model_save_before', $eventManager->dispatchedEvents());
    }

    #[Test]
    #[DataProvider('invalidColors')]
    public function itRefusesToSaveAnythingButAHexColor(string $value): void
    {
        $eventManager = new FakeEventManager();
        $backend = $this->backendWithValue($value, $eventManager);

        $this->expectException(ValidatorException::class);

        try {
            $backend->beforeSave();
        } finally {
            self::assertSame([], $eventManager->dispatchedEvents());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidColors(): array
    {
        return [
            'css injection' => ['#fff;}</style><script>alert(1)</script>'],
            'named color' => ['red'],
            'short hex' => ['#fff'],
            'empty' => [''],
        ];
    }

    /**
     * Config\Value's constructor needs a full model context; beforeSave() only uses the event manager.
     */
    private function backendWithValue(string $value, FakeEventManager $eventManager): HexColor
    {
        $backend = (new \ReflectionClass(HexColor::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($backend, '_eventManager'))->setValue($backend, $eventManager);
        $backend->setValue($value);

        return $backend;
    }
}

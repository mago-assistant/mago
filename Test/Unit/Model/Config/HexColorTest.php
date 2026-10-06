<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Config;

use MagoAssistant\Mago\Model\Config\HexColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HexColorTest extends TestCase
{
    #[Test]
    #[DataProvider('validColors')]
    public function itAcceptsSixDigitHexColors(string $value): void
    {
        self::assertTrue(HexColor::isValid($value));
    }

    #[Test]
    #[DataProvider('invalidColors')]
    public function itRejectsAnythingElse(string $value): void
    {
        self::assertFalse(HexColor::isValid($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validColors(): array
    {
        return [
            'upper case' => ['#F26322'],
            'lower case' => ['#f26322'],
            'mixed case' => ['#aBcDeF'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidColors(): array
    {
        return [
            'empty' => [''],
            'css injection' => ['#fff;}</style><script>alert(1)</script>'],
            'short hex' => ['#fff'],
            'eight digit hex' => ['#F26322FF'],
            'missing hash' => ['F26322'],
            'named color' => ['red'],
            'trailing newline' => ["#F26322\n"],
        ];
    }
}

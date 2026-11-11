<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Manifest;

use MagoAssistant\Mago\Service\Manifest\Placeholders;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlaceholdersTest extends TestCase
{
    private Placeholders $placeholders;

    /** @var array<string, mixed> */
    private array $params = ['sku' => 'AB 12', 'qty' => 3, 'empty' => '', 'tags' => ['a', 'b'], 'flag' => false];

    /** @var array<string, array<string, mixed>> */
    private array $steps = ['load' => ['id' => 7, 'item' => ['name' => 'Chair'], 'items' => []]];

    protected function setUp(): void
    {
        $this->placeholders = new Placeholders();
    }

    #[Test]
    public function valueReturnsTheTypedValueOfASinglePlaceholder(): void
    {
        self::assertSame(3, $this->placeholders->value('{qty}', $this->params, $this->steps));
        self::assertSame(['a', 'b'], $this->placeholders->value('{tags}', $this->params, $this->steps));
        self::assertSame(7, $this->placeholders->value('{steps.load.id}', $this->params, $this->steps));
        self::assertSame(
            ['name' => 'Chair'],
            $this->placeholders->value('{steps.load.item}', $this->params, $this->steps)
        );
        self::assertSame($this->steps['load'], $this->placeholders->value('{steps.load}', $this->params, $this->steps));
    }

    #[Test]
    public function valueIsNullForTextAroundAPlaceholderOrAnUnknownPath(): void
    {
        self::assertNull($this->placeholders->value('x{qty}', $this->params, $this->steps));
        self::assertNull($this->placeholders->value('{qty} {sku}', $this->params, $this->steps));
        self::assertNull($this->placeholders->value('{missing}', $this->params, $this->steps));
        self::assertNull($this->placeholders->value('{steps.load.nope.deeper}', $this->params, $this->steps));
        self::assertNull($this->placeholders->value('plain', $this->params, $this->steps));
    }

    #[Test]
    public function renderReplacesEveryPlaceholderWithItsScalarText(): void
    {
        self::assertSame('AB 12 x3', $this->placeholders->render('{sku} x{qty}', $this->params, $this->steps));
        self::assertSame(
            'Chair #7',
            $this->placeholders->render('{steps.load.item.name} #{steps.load.id}', $this->params, $this->steps)
        );
        self::assertSame('plain', $this->placeholders->render('plain', $this->params, $this->steps));
    }

    #[Test]
    public function renderDropsWhatIsNotScalar(): void
    {
        self::assertSame('[]', $this->placeholders->render('[{tags}]', $this->params, $this->steps));
        self::assertSame('[]', $this->placeholders->render('[{missing}]', $this->params, $this->steps));
        self::assertSame('[]', $this->placeholders->render('[{steps.load.item}]', $this->params, $this->steps));
        self::assertSame('[]', $this->placeholders->render('[{flag}]', $this->params, $this->steps));
    }

    #[Test]
    public function renderUrlEncodesForAPath(): void
    {
        $render = fn (bool $urlEncode): string
            => $this->placeholders->render('products/{sku}', $this->params, $this->steps, $urlEncode);

        self::assertSame('products/AB%2012', $render(true));
        self::assertSame('products/AB 12', $render(false));
    }

    #[Test]
    public function namesListsThePlaceholdersAnExpressionUses(): void
    {
        self::assertSame(['sku', 'steps.load.id'], $this->placeholders->names('x/{sku}/{steps.load.id}/{sku}'));
        self::assertSame([], $this->placeholders->names('plain'));
    }

    #[Test]
    public function isSetTellsWhetherAnExpressionHasSomethingNonEmpty(): void
    {
        self::assertTrue($this->placeholders->isSet('{sku}', $this->params, $this->steps));
        self::assertTrue($this->placeholders->isSet('{tags}', $this->params, $this->steps));
        self::assertTrue($this->placeholders->isSet('{steps.load.id}', $this->params, $this->steps));
        self::assertFalse($this->placeholders->isSet('{empty}', $this->params, $this->steps));
        self::assertFalse($this->placeholders->isSet('{flag}', $this->params, $this->steps));
        self::assertFalse($this->placeholders->isSet('{missing}', $this->params, $this->steps));
        self::assertFalse($this->placeholders->isSet('{steps.load.items}', $this->params, $this->steps));
    }

    #[Test]
    public function isSetOnACompositeExpressionLooksAtTheRenderedText(): void
    {
        self::assertTrue($this->placeholders->isSet('{empty}{sku}', $this->params, $this->steps));
        self::assertFalse($this->placeholders->isSet('{empty} {missing}', $this->params, $this->steps));
        self::assertTrue($this->placeholders->isSet('literal', $this->params, $this->steps));
    }
}

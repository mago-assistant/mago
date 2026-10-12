<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess;

use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Service\Api\InProcess\OutputNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutputNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed}>
     */
    public static function scalarOutputs(): array
    {
        return [
            'a cancel that worked' => [true],
            'a cancel that was declined' => [false],
            'a new invoice id' => [12],
            'an option id as string' => ['3'],
            'nothing' => [null],
        ];
    }

    #[Test]
    #[DataProvider('scalarOutputs')]
    public function itPutsAScalarAnswerUnderResult(mixed $output): void
    {
        $normalized = (new OutputNormalizer(new Json()))->normalize($output);

        self::assertSame(['result' => $output], $normalized);
    }

    #[Test]
    public function itKeepsAnArrayAnswerAsItIs(): void
    {
        $output = ['items' => [['sku' => '24-MB01', 'price' => 34.5]], 'total_count' => 1];

        $normalized = (new OutputNormalizer(new Json()))->normalize($output);

        self::assertSame($output, $normalized);
    }

    #[Test]
    public function itTurnsPhrasesIntoTheTextRestWouldHaveSent(): void
    {
        $normalized = (new OutputNormalizer(new Json()))->normalize(['label' => new Phrase('Color')]);

        self::assertSame(['label' => 'Color'], $normalized);
    }
}

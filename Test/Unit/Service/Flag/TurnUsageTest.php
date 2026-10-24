<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Flag;

use MagoAssistant\Mago\Service\Flag\TurnUsage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TurnUsageTest extends TestCase
{
    #[Test]
    public function itIsNullWhenTheTurnMadeNoProviderCall(): void
    {
        $usage = TurnUsage::fromRows([], $this->decode(...));

        self::assertNull($usage);
    }

    #[Test]
    public function itSumsTheTokensOfEveryCallInTheTurn(): void
    {
        $usage = TurnUsage::fromRows([
            $this->row(['input_tokens' => 1200, 'output_tokens' => 40]),
            $this->row(['input_tokens' => 1500, 'output_tokens' => 210]),
        ], $this->decode(...));

        $summary = $usage->toArray();

        self::assertSame(2700, $summary['input_tokens']);
        self::assertSame(250, $summary['output_tokens']);
    }

    #[Test]
    public function itKeepsTheSkillsOfTheToolCallsEvenThoughTheAnsweringCallHasNone(): void
    {
        $usage = TurnUsage::fromRows([
            $this->row(['skill_names' => 'get_orders,get_customer']),
            $this->row(['skill_names' => 'get_orders']),
            $this->row(['skill_names' => null]),
        ], $this->decode(...));

        $summary = $usage->toArray();

        self::assertSame('get_orders,get_customer', $summary['skill_names']);
    }

    #[Test]
    public function itHasNoSkillsWhenNoCallUsedATool(): void
    {
        $usage = TurnUsage::fromRows([$this->row(['skill_names' => null])], $this->decode(...));

        $summary = $usage->toArray();

        self::assertSame('', $summary['skill_names']);
    }

    #[Test]
    public function itNamesTheModelOfTheCallThatProducedTheAnswer(): void
    {
        $usage = TurnUsage::fromRows([
            $this->row(['provider' => 'openai', 'model' => 'gpt-small']),
            $this->row(['provider' => 'anthropic', 'model' => 'claude-sonnet']),
        ], $this->decode(...));

        $summary = $usage->toArray();

        self::assertSame('anthropic', $summary['provider']);
        self::assertSame('claude-sonnet', $summary['model']);
    }

    #[Test]
    public function itKeepsThePayloadsOfEachCallInOrder(): void
    {
        $usage = TurnUsage::fromRows([
            $this->row(['request_payload' => '{"call":1}', 'response_payload' => null]),
            $this->row(['request_payload' => '{"call":2}', 'response_payload' => '{"ok":true}']),
        ], $this->decode(...));

        $calls = $usage->toArray()['calls'];

        self::assertSame(['call' => 1], $calls[0]['request_payload']);
        self::assertNull($calls[0]['response_payload']);
        self::assertSame(['call' => 2], $calls[1]['request_payload']);
        self::assertSame(['ok' => true], $calls[1]['response_payload']);
    }

    private function decode(string $value): mixed
    {
        return $value === '' ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides): array
    {
        return array_merge([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'skill_names' => null,
            'request_payload' => null,
            'response_payload' => null,
            'created_at' => '2026-09-30 10:00:00',
        ], $overrides);
    }
}

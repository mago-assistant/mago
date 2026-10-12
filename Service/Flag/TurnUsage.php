<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Flag;

/**
 * The provider side of one turn, built from the mago_usage_log rows that turn wrote.
 *
 * A turn that used tools makes several provider calls and logs one row per call. The last row is the
 * call that produced the text answer and carries no tool calls, so reading a single row loses the
 * skills that were used and most of the tokens. This sums and merges all of them instead.
 */
final class TurnUsage
{
    private const SKILL_SEPARATOR = ',';

    /**
     * @param list<array<string, mixed>> $calls One entry per provider call, oldest first
     * @param array<string, mixed> $answeringCall The last call, the one that produced the answer
     */
    private function __construct(
        private readonly array $calls,
        private readonly array $answeringCall
    ) {
    }

    /**
     * @param array<array<string, mixed>> $rows mago_usage_log rows, oldest first
     * @param callable(string): mixed $decode Turns a stored payload column into its value
     */
    public static function fromRows(array $rows, callable $decode): ?self
    {
        if ($rows === []) {
            return null;
        }

        $calls = array_map(
            static fn (array $row): array => [
                'provider' => (string)($row['provider'] ?? ''),
                'model' => (string)($row['model'] ?? ''),
                'input_tokens' => (int)($row['input_tokens'] ?? 0),
                'output_tokens' => (int)($row['output_tokens'] ?? 0),
                'skill_names' => (string)($row['skill_names'] ?? ''),
                'request_payload' => $decode((string)($row['request_payload'] ?? '')),
                'response_payload' => $decode((string)($row['response_payload'] ?? '')),
                'logged_at' => (string)($row['created_at'] ?? ''),
            ],
            array_values($rows)
        );

        return new self($calls, $calls[count($calls) - 1]);
    }

    /**
     * Every skill any call of the turn used, once each, in the order they were first used.
     *
     * @return list<string>
     */
    private function skills(): array
    {
        $names = explode(
            self::SKILL_SEPARATOR,
            implode(self::SKILL_SEPARATOR, array_column($this->calls, 'skill_names'))
        );

        return array_values(array_unique(array_filter(
            array_map('trim', $names),
            static fn (string $skill): bool => $skill !== ''
        )));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->answeringCall['provider'],
            'model' => $this->answeringCall['model'],
            'input_tokens' => array_sum(array_column($this->calls, 'input_tokens')),
            'output_tokens' => array_sum(array_column($this->calls, 'output_tokens')),
            'skill_names' => implode(self::SKILL_SEPARATOR, $this->skills()),
            'calls' => $this->calls,
        ];
    }
}

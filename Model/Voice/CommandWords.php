<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Voice;

/**
 * Spoken command words: the built-in English ones, which always work so a mistyped or emptied
 * setting can never leave voice input without a way to send, answer a card or stop, and the
 * parsing of the shop's own words from the Voice Input settings.
 */
final class CommandWords
{
    public const COMMANDS = ['yes', 'no', 'stop'];

    private const ENGLISH = [
        'yes' => ['yes', 'send', 'send it', 'send now', 'submit', 'allow', 'ok', 'okay', 'confirm', 'go ahead', 'do it', 'approve'],
        'no' => ['no', 'discard', 'clear', 'delete', 'start over', 'scratch that', 'reject', 'nope', 'cancel', 'deny', 'not now', 'skip'],
        'stop' => ['stop', 'stop listening', 'microphone off'],
    ];

    /**
     * The shop's comma-separated words for a command, lower-cased, followed by the English ones
     *
     * @return string[]
     */
    public static function withEnglish(string $command, string $configured): array
    {
        $words = array_merge(self::split(',', $configured), self::ENGLISH[$command] ?? []);

        return array_values(array_unique($words));
    }

    /**
     * "one/first, two/second, …" as number => words; a group left empty keeps its position
     *
     * @return array<int, string[]>
     */
    public static function numbers(string $configured): array
    {
        $numbers = [];
        foreach (explode(',', $configured) as $index => $group) {
            $words = self::split('/', $group);
            if ($words !== []) {
                $numbers[$index + 1] = $words;
            }
        }

        return $numbers;
    }

    /**
     * @return string[]
     */
    private static function split(string $separator, string $value): array
    {
        $words = array_map(static fn (string $word): string => mb_strtolower(trim($word)), explode($separator, $value));

        return array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
    }
}

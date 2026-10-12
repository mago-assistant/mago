<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config;

/**
 * Colors from config end up unescaped inside <style> blocks, so only a plain #RRGGBB is accepted.
 */
final class HexColor
{
    private const PATTERN = '/^#[0-9a-fA-F]{6}\z/';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}

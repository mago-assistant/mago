<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Manifest;

/**
 * The templating a manifest uses: {name} is the call parameter of that name, {steps.x.y.z} walks
 * into the response of an earlier step. Pure.
 */
class Placeholders
{
    private const PATTERN = '/\{([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)\}/i';

    /**
     * The value a single placeholder expression stands for, with its original type; null when the
     * expression is not exactly one placeholder or resolves to nothing.
     *
     * @param array<string, mixed> $params
     * @param array<string, array<string, mixed>> $steps
     */
    public function value(string $expression, array $params, array $steps): mixed
    {
        if (preg_match('/^' . substr(self::PATTERN, 1, -2) . '$/i', $expression, $m) !== 1) {
            return null;
        }

        return $this->lookup($m[1], $params, $steps);
    }

    /**
     * The expression with every placeholder replaced by its scalar value; a placeholder that
     * resolves to nothing, to an array or to false becomes an empty string.
     *
     * @param array<string, mixed> $params
     * @param array<string, array<string, mixed>> $steps
     */
    public function render(string $expression, array $params, array $steps, bool $urlEncode = false): string
    {
        return (string)preg_replace_callback(
            self::PATTERN,
            function (array $m) use ($params, $steps, $urlEncode): string {
                $value = $this->lookup($m[1], $params, $steps);
                $text = is_scalar($value) ? (string)$value : '';

                return $urlEncode ? rawurlencode($text) : $text;
            },
            $expression
        );
    }

    /**
     * The placeholder paths an expression uses, e.g. ["entity_id", "steps.load.id"].
     *
     * @return list<string>
     */
    public function names(string $expression): array
    {
        preg_match_all(self::PATTERN, $expression, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Whether the expression resolves to something non-empty, for a step's `when`.
     *
     * @param array<string, mixed> $params
     * @param array<string, array<string, mixed>> $steps
     */
    public function isSet(string $expression, array $params, array $steps): bool
    {
        $value = $this->value($expression, $params, $steps);
        if ($value === null) {
            return trim($this->render($expression, $params, $steps)) !== '';
        }

        return $value !== '' && $value !== [] && $value !== false;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, array<string, mixed>> $steps
     */
    private function lookup(string $path, array $params, array $steps): mixed
    {
        $segments = explode('.', $path);
        if ($segments[0] === 'steps') {
            array_shift($segments);
            $current = $steps;
        } else {
            $current = $params;
        }

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}

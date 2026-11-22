<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Tool;

use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Acl\ToolAccess;

/**
 * Decides whether an admin may make a tool call, on the arguments the call will actually run with.
 *
 * The chat asks before it runs a tool, the export before it runs a stored call again, so both
 * refuse exactly the same calls: the assistant's skill permission first, then the Magento ACL the
 * call declares (ToolAccess).
 */
class ToolCallGate
{
    /** C0 controls except tab, line feed and carriage return, plus DEL */
    private const CONTROL_CHARACTER = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolAccess $toolAccess
    ) {
    }

    /**
     * Why the admin user may not perform this invocation, or null when allowed. Decided on the
     * input the action takes, so no undeclared key can sway it.
     *
     * @param array<string, mixed> $input
     */
    public function denialReason(ToolInterface $tool, array $input, ?int $adminUserId): ?string
    {
        $input = $this->inputForAction($tool, $input);
        if (!$this->toolRegistry->isCallAllowed($tool, $input, $adminUserId)) {
            return sprintf(
                'Access denied: your skill permissions do not allow this action with the %s tool',
                $tool->getName()
            );
        }

        return $this->toolAccess->denialReason($tool, $input, $adminUserId);
    }

    /**
     * The parameters the chosen action actually takes. A skill offers one flat schema for all its
     * actions, and the model fills in every key it is shown, so a status change arrives carrying
     * capture, carrier_code and the credit memo adjustments. On the confirmation card that is worse
     * than noise: the admin is asked to approve a status change while reading "capture: true".
     * The run gets the same cut, so the card and the save never see different arguments.
     *
     * @param array<array-key,mixed> $input
     * @return array<array-key,mixed>
     */
    public function inputForAction(object $tool, array $input): array
    {
        $action = (string)($input['action'] ?? '');
        if ($action === '' || !method_exists($tool, 'getParameterSchemaForActions')) {
            return $input;
        }

        $schema = $tool->getParameterSchemaForActions([$action]);
        $known = array_keys($schema['properties'] ?? []);
        if ($known === []) {
            return $input;
        }

        return array_filter(
            $input,
            static fn (mixed $value, string|int $key): bool => in_array((string)$key, $known, true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Where a write argument holds an invisible control character, or null when none does
     *
     * A model sometimes writes a character it cannot produce as a control character instead: gpt-4o
     * was seen sending "over \u007f50" for "over €50". Nothing a write stores needs one (tab, line
     * feed and carriage return are kept), and stored it shows as a box or vanishes on the storefront,
     * on a confirmation card that looked right. Returned as a dotted path to the argument.
     *
     * @param array<array-key,mixed> $input
     * @param string $path Path of $input within the arguments, empty at the top
     */
    public function findControlCharacter(array $input, string $path = ''): ?string
    {
        foreach ($input as $key => $value) {
            $keyPath = ltrim($path . '.' . $key, '.');
            if (is_array($value)) {
                $found = $this->findControlCharacter($value, $keyPath);
                if ($found !== null) {
                    return $found;
                }
            } elseif (is_string($value) && preg_match(self::CONTROL_CHARACTER, $value)) {
                return $keyPath;
            }
        }

        return null;
    }

    /**
     * The refusal a write with a control character gets, naming the argument
     *
     * @param string $path What findControlCharacter() returned
     * @return array{error: string}
     */
    public function controlCharacterRefusal(string $path): array
    {
        return ['error' => sprintf(
            'Argument "%s" contains an invisible control character where a character (such as € or a quote) '
            . 'was probably meant. Nothing was saved. Send the call again with the character written out.',
            $path
        )];
    }
}

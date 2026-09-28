<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\Tool\PresentableToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Read-only tool from "another module" that names itself and writes its own status line
 */
final class FakePresentableTool implements PresentableToolInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $displayName,
        private readonly ?string $statusMessage
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->name . ' description';
    }

    public function getParameterSchema(): array
    {
        return ['type' => 'object', 'properties' => ['action' => ['type' => 'string']]];
    }

    public function execute(array $params): array
    {
        return ['checked' => true];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return true;
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getFieldClassification(string $action = ''): array
    {
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getMagentoAcl(array $input = []): string
    {
        return '';
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getStatusMessage(string $action, array $input): ?string
    {
        return $this->statusMessage === null ? null : sprintf($this->statusMessage, $action);
    }
}

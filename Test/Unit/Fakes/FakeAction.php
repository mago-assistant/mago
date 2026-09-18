<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

final class FakeAction implements ActionInterface
{
    /**
     * @param array<string, array<string, mixed>> $parameterSchema
     * @param array<string, mixed>|null $result What execute() answers; null for the default echo
     * @param array<string, array{0:string,1?:string}>|null $fieldClassification null for
     *        wildcard-public, so non-privacy tests see the fake's output unfiltered
     */
    public function __construct(
        private readonly string $name,
        private readonly bool $isReadOnly,
        private readonly array $parameterSchema = [],
        private readonly string $instructions = '',
        private readonly ?array $result = null,
        private readonly ?array $fieldClassification = null
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
        return $this->parameterSchema;
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return $this->isReadOnly;
    }

    public function execute(array $params, int $adminUserId): array
    {
        return $this->result ?? ['executed' => $this->name, 'admin_user_id' => $adminUserId];
    }

    public function getInstructions(): string
    {
        return $this->instructions;
    }

    public function getFieldClassification(): array
    {
        return $this->fieldClassification ?? [PiiClass::ANY => [PiiClass::PUBLIC]];
    }
}

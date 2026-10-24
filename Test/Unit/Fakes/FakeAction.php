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
    /** @var array<string, mixed>|null The params of the last execute(), null while it never ran */
    private ?array $executedParams = null;

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
        private readonly ?array $fieldClassification = null,
        private readonly ?string $aclResource = null
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
        return $this->aclResource;
    }

    public function isReadOnly(): bool
    {
        return $this->isReadOnly;
    }

    public function execute(array $params, int $adminUserId): array
    {
        $this->executedParams = $params;

        return $this->result ?? ['executed' => $this->name, 'admin_user_id' => $adminUserId];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function executedParams(): ?array
    {
        return $this->executedParams;
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

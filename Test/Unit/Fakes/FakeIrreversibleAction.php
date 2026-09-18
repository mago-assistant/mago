<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * A write action that cannot be undone, with a fixed impact list
 */
final class FakeIrreversibleAction implements IrreversibleActionInterface
{
    /**
     * @param string[] $impacts
     */
    public function __construct(
        private readonly string $name,
        private readonly array $impacts = [],
        private readonly ?\Throwable $impactsFailure = null
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
        return [];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function execute(array $params, int $adminUserId): array
    {
        return ['executed' => $this->name];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getFieldClassification(): array
    {
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getImpacts(array $params, int $adminUserId): array
    {
        if ($this->impactsFailure) {
            throw $this->impactsFailure;
        }

        return $this->impacts;
    }
}

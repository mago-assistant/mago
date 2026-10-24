<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * A reversible write action that changes something to weigh first, with a fixed caution list
 */
final class FakeCautionedAction implements CautionedActionInterface
{
    /**
     * @param string[] $cautions
     */
    public function __construct(
        private readonly string $name,
        private readonly array $cautions = []
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

    public function getCautions(array $params, int $adminUserId): array
    {
        return $this->cautions;
    }
}

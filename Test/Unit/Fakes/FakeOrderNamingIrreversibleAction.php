<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * A write action that cannot be undone and names the order it was called for in its impact list,
 * the way order_manager's cancel action does
 */
final class FakeOrderNamingIrreversibleAction implements IrreversibleActionInterface
{
    public function __construct(
        private readonly string $name
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
        return ['order_number' => ['type' => 'string']];
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
        return ['Order #' . (string)($params['order_number'] ?? '') . ' is canceled and cannot be reopened.'];
    }
}

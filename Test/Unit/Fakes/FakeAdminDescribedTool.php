<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\Tool\AdminDescribedToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Read-only tool from "another module" with a model description and its own admin description
 */
final class FakeAdminDescribedTool implements AdminDescribedToolInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly string $adminDescription
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getParameterSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $params): array
    {
        return [];
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
        return 'Magento_Backend::admin';
    }

    public function getAdminDescription(): string
    {
        return $this->adminDescription;
    }
}

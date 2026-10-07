<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\Tool\HighImpactToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * A write tool that names cautions for its calls, or fails to (#245)
 */
final class FakeHighImpactTool implements HighImpactToolInterface
{
    /**
     * @param string[] $cautions
     */
    public function __construct(
        private readonly string $name,
        private readonly array $cautions,
        private readonly ?\Throwable $failure = null
    ) {
    }

    public function getCautions(array $input, int $adminUserId): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->cautions;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'Fake high-impact write';
    }

    public function getParameterSchema(): array
    {
        return ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]];
    }

    public function execute(array $params): array
    {
        return ['success' => true];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return false;
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
}

<?php
declare(strict_types=1);

namespace {{Vendor}}\{{Module}}\Service\Tool;

use MagoAssistant\Mago\Api\Tool\ToolInterface;

class {{ClassName}} implements ToolInterface
{
    public function getName(): string
    {
        return '{{tool_name}}';
    }

    public function getDescription(): string
    {
        return '';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function getMagentoAcl(array $input = []): string
    {
        return '{{acl}}';
    }

    public function isReadOnly(): bool
    {
        return {{is_read_only}};
    }

    public function isReadOnlyAction(array $input): bool
    {
        return {{is_read_only}};
    }

    public function getFieldClassification(string $action = ''): array
    {
        return [];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params): array
    {
        return [];
    }
}

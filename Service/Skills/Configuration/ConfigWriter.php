<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Configuration;

use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Cache\TypeListInterface;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;

class ConfigWriter implements ToolInterface
{
    private const BLOCKED_PATTERNS = [
        '*key*', '*secret*', '*password*', '*token*', '*credential*',
        'payment/*', '*api_key*', '*private*', '*encrypt*',
    ];

    public function __construct(
        private readonly ConfigResource $configResource,
        private readonly TypeListInterface $cacheTypeList,
        private readonly StoreScopeContext $scopeContext
    ) {
    }

    public function getName(): string
    {
        return 'config_writer';
    }

    public function getDescription(): string
    {
        return 'Modify Magento store configuration values. Requires merchant confirmation before execution. Sensitive paths are blocked.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'The configuration path to set (e.g. "general/store_information/name")',
                ],
                'value' => [
                    'type' => 'string',
                    'description' => 'The value to set',
                ],
                'scope' => [
                    'type' => 'string',
                    'description' => 'Scope to write: "default" (every website and store view without an override), '
                        . '"websites" or "stores". Verify the intended scope with the user on multi-store installations.',
                    'enum' => ['default', 'websites', 'stores'],
                ],
                'scope_id' => [
                    'type' => 'integer',
                    'description' => 'Website id for scope "websites", store view id for scope "stores" '
                        . '(take the id from the store scope list). 0 for default.',
                ],
            ],
            'required' => ['path', 'value'],
        ];
    }

    public function execute(array $params): array
    {
        $path = $params['path'] ?? '';
        $value = $params['value'] ?? '';

        if (!$path) {
            return ['error' => 'Path parameter is required'];
        }

        if ($this->isBlockedPath($path)) {
            return ['error' => 'Cannot modify this configuration path for security reasons'];
        }

        $scope = (string)($params['scope'] ?? StoreScopeContext::SCOPE_DEFAULT);
        $scopeId = (int)($params['scope_id'] ?? 0);

        $scopeError = $this->scopeContext->validateScope($scope, $scopeId);
        if ($scopeError !== null) {
            return ['error' => $scopeError];
        }

        $scopeLabel = $this->scopeContext->describeScope($scope, $scopeId);

        $this->configResource->saveConfig($path, $value, $scope, $scopeId);
        $this->cacheTypeList->cleanType('config');

        return [
            'success' => true,
            'path' => $path,
            'value' => $value,
            'scope' => $scope,
            'scope_id' => $scopeId,
            'scope_label' => $scopeLabel,
            'message' => sprintf('Configuration "%s" has been set to "%s" on %s', $path, $value, $scopeLabel),
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return $this->isReadOnly();
    }

    public function getInstructions(): string
    {
        return 'Decide the scope before calling this tool. The default scope (scope_id 0) changes the value for every '
            . 'website and store view that does not override it. On a multi-store installation, when the user did not '
            . 'name a website or store view and the setting could differ per store view (store name and contact '
            . 'details, locale, currency, URLs, email addresses, design), ask which scope they mean first. When the '
            . 'user names a website or store view, pass scope "websites" or "stores" with the id from the store scope '
            . 'list; never guess an id. Use config_reader first when you need to know whether a deeper scope already '
            . 'overrides the value. Repeat the resulting scope_label in your answer.';
    }

    public function getFieldClassification(string $action = ''): array
    {
        // Config values are dynamic paths; wildcard-public preserves today's denylist behavior,
        // the config egress surface itself is tracked as issue #106 (denylist to allowlist).
        return [
            'message' => [PiiClass::PUBLIC],
            PiiClass::ANY => [PiiClass::PUBLIC],
        ];
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Config::config';
    }

    private function isBlockedPath(string $path): bool
    {
        $pathLower = strtolower($path);
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            $regex = '/^' . str_replace(['*', '/'], ['.*', '\/'], $pattern) . '$/';
            if (preg_match($regex, $pathLower)) {
                return true;
            }
        }
        $segments = explode('/', $pathLower);
        $blockedWords = ['key', 'secret', 'password', 'token', 'credential', 'private', 'encrypt'];
        foreach ($segments as $segment) {
            foreach ($blockedWords as $word) {
                if (str_contains($segment, $word)) {
                    return true;
                }
            }
        }
        if (str_starts_with($pathLower, 'payment/')) {
            return true;
        }
        return false;
    }
}

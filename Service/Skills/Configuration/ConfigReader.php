<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Configuration;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;

class ConfigReader implements ToolInterface
{
    private const BLOCKED_PATTERNS = [
        '*key*', '*secret*', '*password*', '*token*', '*credential*',
        'payment/*', '*api_key*', '*private*', '*encrypt*',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreScopeContext $scopeContext
    ) {
    }

    public function getName(): string
    {
        return 'config_reader';
    }

    public function getDescription(): string
    {
        return 'Read Magento store configuration values. Provide the config path (e.g. "general/store_information/name", "web/secure/base_url"). Sensitive paths containing keys, secrets, passwords, tokens or payment config are blocked.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'The configuration path to read (e.g. "general/store_information/name")',
                ],
                'scope' => [
                    'type' => 'string',
                    'description' => 'Scope to read: "default" (global value), "websites" or "stores". '
                        . 'Defaults to "default".',
                    'enum' => ['default', 'websites', 'stores'],
                ],
                'scope_id' => [
                    'type' => 'integer',
                    'description' => 'Website id for scope "websites", store view id for scope "stores" '
                        . '(take the id from the store scope list). 0 for default.',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $params): array
    {
        $path = $params['path'] ?? '';
        if (!$path) {
            return ['error' => 'Path parameter is required'];
        }

        if ($this->isBlockedPath($path)) {
            return ['error' => 'Access to this configuration path is restricted for security reasons'];
        }

        $scope = (string)($params['scope'] ?? StoreScopeContext::SCOPE_DEFAULT);
        $scopeId = (int)($params['scope_id'] ?? 0);

        $scopeError = $this->scopeContext->validateScope($scope, $scopeId);
        if ($scopeError !== null) {
            return ['error' => $scopeError];
        }

        $value = $this->scopeConfig->getValue($path, $scope, $scopeId);

        $result = [
            'path' => $path,
            'value' => $value,
            'scope' => $scope,
            'scope_id' => $scopeId,
            'scope_label' => $this->scopeContext->describeScope($scope, $scopeId),
        ];

        if ($scope === StoreScopeContext::SCOPE_DEFAULT && !$this->scopeContext->hasSingleStoreView()) {
            $overrides = $this->collectOverrides($path, $value);
            $result['overrides'] = $overrides;
            $result['note'] = $overrides === []
                ? 'No website or store view overrides this value; the default applies everywhere.'
                : 'The websites and store views listed in "overrides" use a different value than the default scope. '
                    . 'Mention them to the user.';
        }

        return $result;
    }

    /**
     * Websites and store views whose effective value differs from what they inherit.
     *
     * A store view is only listed when it differs from its own website, so a single website override
     * is reported once instead of once per store view under it.
     *
     * @param string $path
     * @param mixed $defaultValue
     * @return array<int, array{scope:string,scope_id:int,scope_label:string,value:mixed}>
     */
    private function collectOverrides(string $path, mixed $defaultValue): array
    {
        $overrides = [];
        foreach ($this->scopeContext->getWebsites() as $website) {
            $websiteValue = $this->scopeConfig->getValue($path, StoreScopeContext::SCOPE_WEBSITES, $website['id']);
            if ($this->differs($websiteValue, $defaultValue)) {
                $overrides[] = $this->override(StoreScopeContext::SCOPE_WEBSITES, $website['id'], $websiteValue);
            }
            foreach ($website['groups'] as $group) {
                foreach ($group['stores'] as $store) {
                    $storeValue = $this->scopeConfig->getValue($path, StoreScopeContext::SCOPE_STORES, $store['id']);
                    if ($this->differs($storeValue, $websiteValue)) {
                        $overrides[] = $this->override(StoreScopeContext::SCOPE_STORES, $store['id'], $storeValue);
                    }
                }
            }
        }

        return $overrides;
    }

    private function differs(mixed $a, mixed $b): bool
    {
        return json_encode($a) !== json_encode($b);
    }

    /**
     * @return array{scope:string,scope_id:int,scope_label:string,value:mixed}
     */
    private function override(string $scope, int $scopeId, mixed $value): array
    {
        return [
            'scope' => $scope,
            'scope_id' => $scopeId,
            'scope_label' => $this->scopeContext->describeScope($scope, $scopeId),
            'value' => $value,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return $this->isReadOnly();
    }

    public function getInstructions(): string
    {
        return 'Scope: "default" (scope_id 0) is the global value and the fallback for every website and store view. '
            . '"websites" with a website id or "stores" with a store view id returns the effective value at that level. '
            . 'When the user names a website or store view, read at that scope using the id from the store scope list. '
            . 'A default-scope read on a multi-store installation also returns "overrides": every website or store view '
            . 'that uses a different value. Report those overrides to the user instead of presenting the default as '
            . 'the only value.';
    }

    public function getFieldClassification(string $action = ''): array
    {
        // Config values are dynamic paths; wildcard-public preserves today's denylist behavior,
        // the config egress surface itself is tracked as issue #106 (denylist to allowlist).
        return [
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
        // Also check path segments
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

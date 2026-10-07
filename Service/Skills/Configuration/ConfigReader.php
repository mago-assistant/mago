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
    public const MASKED_VALUE = 'masked_value';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreScopeContext $scopeContext,
        private readonly ConfigPathAccess $pathAccess
    ) {
    }

    public function getName(): string
    {
        return 'config_reader';
    }

    public function getDescription(): string
    {
        return 'Read Magento store configuration values. Provide the config path (e.g. "general/store_information/name", "web/secure/base_url"). Credentials, payment config and Mago\'s own settings are blocked; sensitive values such as contact addresses come back masked.';
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
        $path = $this->pathAccess->normalise($params['path'] ?? '');
        if (!$path) {
            return ['error' => 'Path parameter is required'];
        }

        if ($this->pathAccess->isBlocked($path)) {
            return ['error' => 'Access to this configuration path is restricted for security reasons'];
        }

        if (!$this->pathAccess->isAllowed($path)) {
            return ['error' => 'Access denied: you do not have the permission for this configuration section'];
        }

        $scope = (string)($params['scope'] ?? StoreScopeContext::SCOPE_DEFAULT);
        $scopeId = (int)($params['scope_id'] ?? 0);

        $scopeError = $this->scopeContext->validateScope($scope, $scopeId);
        if ($scopeError !== null) {
            return ['error' => $scopeError];
        }

        $value = $this->scopeConfig->getValue($path, $scope, $scopeId);
        // A section or group path returns its whole subtree, and the blocklist only saw the path:
        // "carriers/ups" would hand over the password that "carriers/ups/password" is refused for.
        if (is_array($value)) {
            return $this->notASettingResult();
        }

        $result = [
            'path' => $path,
            'value' => $value,
            'scope' => $scope,
            'scope_id' => $scopeId,
            'scope_label' => $this->scopeContext->describeScope($scope, $scopeId),
        ];

        if ($scope === StoreScopeContext::SCOPE_DEFAULT && !$this->scopeContext->hasSingleStoreView()) {
            $overrides = $this->collectOverrides($path, $value);
            if ($overrides === null) {
                return $this->notASettingResult();
            }
            $result['overrides'] = $overrides;
            $result['note'] = $overrides === []
                ? 'No website or store view overrides this value; the default applies everywhere.'
                : 'The websites and store views listed in "overrides" use a different value than the default scope. '
                    . 'Mention them to the user.';
        }

        return $this->pathAccess->isSensitive($path) ? $this->masked($result) : $result;
    }

    /**
     * A sensitive value goes out under "masked_value", which the privacy filter turns into a vault
     * token: the admin reads the real value, the model only the token.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function masked(array $result): array
    {
        $result = $this->renameValue($result);
        if (isset($result['overrides']) && is_array($result['overrides'])) {
            $result['overrides'] = array_map(
                fn (mixed $override): mixed => is_array($override) ? $this->renameValue($override) : $override,
                $result['overrides']
            );
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $entry
     * @return array<array-key, mixed>
     */
    private function renameValue(array $entry): array
    {
        return array_combine(
            array_map(
                static fn (int|string $key): int|string => $key === 'value' ? self::MASKED_VALUE : $key,
                array_keys($entry)
            ),
            $entry
        );
    }

    /**
     * Websites and store views whose effective value differs from what they inherit.
     *
     * A store view is only listed when it differs from its own website, so a single website override
     * is reported once instead of once per store view under it.
     *
     * @param string $path
     * @param mixed $defaultValue
     * @return array<int, array{scope:string,scope_id:int,scope_label:string,value:mixed}>|null Null when a
     *         scope returns a subtree: the path is a section or group
     */
    private function collectOverrides(string $path, mixed $defaultValue): ?array
    {
        $overrides = [];
        foreach ($this->scopeContext->getWebsites() as $website) {
            $websiteValue = $this->scopeConfig->getValue($path, StoreScopeContext::SCOPE_WEBSITES, $website['id']);
            if (is_array($websiteValue)) {
                return null;
            }
            if ($this->differs($websiteValue, $defaultValue)) {
                $overrides[] = $this->override(StoreScopeContext::SCOPE_WEBSITES, $website['id'], $websiteValue);
            }
            foreach ($website['groups'] as $group) {
                foreach ($group['stores'] as $store) {
                    $storeValue = $this->scopeConfig->getValue($path, StoreScopeContext::SCOPE_STORES, $store['id']);
                    if (is_array($storeValue)) {
                        return null;
                    }
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
            . 'the only value. A sensitive setting (a contact address, a carrier account) comes back as '
            . '"masked_value": write that value out exactly as you received it; the administrator reads the real one.';
    }

    public function getFieldClassification(string $action = ''): array
    {
        return [
            self::MASKED_VALUE => [PiiClass::TOKENISE, 'config'],
            PiiClass::ANY => [PiiClass::PUBLIC],
        ];
    }

    public function getMagentoAcl(array $input = []): string
    {
        return $this->pathAccess->aclResourceFor($this->pathAccess->normalise($input['path'] ?? ''));
    }

    /**
     * @return array{error: string}
     */
    private function notASettingResult(): array
    {
        return ['error' => 'This path is a configuration section or group. Name a single setting, '
            . 'for example general/store_information/name'];
    }
}

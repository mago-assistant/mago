<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Configuration;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\Mago\Api\Tool\HighImpactToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;

class ConfigWriter implements HighImpactToolInterface
{
    public function __construct(
        private readonly ConfigValueSaver $valueSaver,
        private readonly TypeListInterface $cacheTypeList,
        private readonly StoreScopeContext $scopeContext,
        private readonly ConfigPathAccess $pathAccess,
        private readonly ConfigWriteImpact $impact,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'config_writer';
    }

    public function getDescription(): string
    {
        return 'Modify Magento store configuration values. Requires merchant confirmation before execution. Credentials, payment config and Mago\'s own settings are blocked.';
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
        $path = $this->pathAccess->normalise($params['path'] ?? '');
        $value = $params['value'] ?? '';

        if (!$path) {
            return ['error' => 'Path parameter is required'];
        }

        if (!is_scalar($value)) {
            return ['error' => 'The value must be text, a number or true/false'];
        }
        $value = (string)$value;

        if ($this->pathAccess->isBlocked($path)) {
            return ['error' => 'Cannot modify this configuration path for security reasons'];
        }

        if (!$this->pathAccess->isAllowed($path)) {
            return ['error' => 'Access denied: you do not have the permission for this configuration section'];
        }

        if (!$this->pathAccess->isDeclared($path)) {
            return ['error' => 'This path is not a setting under Stores > Configuration, so it cannot be set here'];
        }

        $scope = (string)($params['scope'] ?? StoreScopeContext::SCOPE_DEFAULT);
        $scopeId = (int)($params['scope_id'] ?? 0);

        $scopeError = $this->scopeContext->validateScope($scope, $scopeId);
        if ($scopeError !== null) {
            return ['error' => $scopeError];
        }

        $scopeLabel = $this->scopeContext->describeScope($scope, $scopeId);

        try {
            $this->valueSaver->save($path, $value, $scope, $scopeId);
        } catch (LocalizedException $e) {
            return ['error' => $e->getMessage()];
        }
        $this->cacheTypeList->cleanType('config');

        $result = [
            'success' => true,
            'path' => $path,
            'value' => $value,
            'scope' => $scope,
            'scope_id' => $scopeId,
            'scope_label' => $scopeLabel,
            'message' => sprintf('Configuration "%s" has been set to "%s" on %s', $path, $value, $scopeLabel),
        ];
        // A sensitive value goes back to the model only under masked_value, which becomes a token.
        if ($this->pathAccess->isSensitive($path)) {
            unset($result['value']);
            $result[ConfigReader::MASKED_VALUE] = $value;
            $result['message'] = sprintf('Configuration "%s" has been set on %s', $path, $scopeLabel);
        }

        return $result;
    }

    /**
     * A change to a setting where a write proposed from text the assistant read would hurt most
     * comes to the admin with what it changes, from what, and a reminder to check they asked for
     * it (#245). Paths this tool refuses anyway need no caution.
     *
     * @param array $input
     * @param int $adminUserId
     * @return string[]
     */
    public function getCautions(array $input, int $adminUserId): array
    {
        $path = $this->pathAccess->normalise($input['path'] ?? '');
        $scope = (string)($input['scope'] ?? StoreScopeContext::SCOPE_DEFAULT);
        $scopeId = (int)($input['scope_id'] ?? 0);
        if ($path === '' || $this->pathAccess->isBlocked($path)
            || $this->scopeContext->validateScope($scope, $scopeId) !== null
        ) {
            return [];
        }

        $reason = $this->impact->reasonFor($path);
        if ($reason === null) {
            return [];
        }
        $scopeLabel = $this->scopeContext->describeScope($scope, $scopeId);
        // The card is stored with the conversation; a sensitive value stays out of it.
        $change = $this->pathAccess->isSensitive($path)
            ? sprintf('On %s it changes to the value shown above.', $scopeLabel)
            : $this->describeChange($path, $scope, $scopeId, $scopeLabel, $input['value'] ?? null);

        return [
            $reason,
            $change,
            'Allow it only if you asked for this change yourself: text the assistant read, such as a '
                . 'product, a review or a page, can try to make it propose one.',
        ];
    }

    /**
     * @param string $path
     * @param string $scope
     * @param int $scopeId
     * @param string $scopeLabel
     * @param mixed $newValue
     * @return string
     */
    private function describeChange(
        string $path,
        string $scope,
        int $scopeId,
        string $scopeLabel,
        mixed $newValue
    ): string {
        $current = $this->scopeConfig->getValue($path, $scope, $scopeId);

        return sprintf(
            'On %s it changes from "%s" to "%s".',
            $scopeLabel,
            is_scalar($current) ? (string)$current : '',
            is_scalar($newValue) ? (string)$newValue : ''
        );
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
        return [
            ConfigReader::MASKED_VALUE => [PiiClass::TOKENISE, 'config'],
            'message' => [PiiClass::PUBLIC],
            PiiClass::ANY => [PiiClass::PUBLIC],
        ];
    }

    public function getMagentoAcl(array $input = []): string
    {
        return $this->pathAccess->aclResourceFor($this->pathAccess->normalise($input['path'] ?? ''));
    }
}

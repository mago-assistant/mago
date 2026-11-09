<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Manifest;

use Magento\Framework\Module\Manager as ModuleManager;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Api\Tool\ActionAclAwareToolInterface;
use MagoAssistant\Mago\Api\Tool\ActionScopedToolInterface;
use MagoAssistant\Mago\Api\Tool\AvailabilityAwareToolInterface;
use MagoAssistant\Mago\Api\Tool\IrreversibleToolInterface;
use MagoAssistant\Mago\Api\Tool\PresentableToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

/**
 * A tool another module declared in its etc/mago_tools.xml. Each action is one or more calls to
 * that module's REST routes, made through the internal API client as the admin user, so the
 * route's own ACL applies on top of the resource the manifest names. Nothing here knows what the
 * module is about; the manifest carries every word the model and the panel see.
 *
 * @phpstan-import-type ToolDef from \MagoAssistant\Mago\Model\Manifest\Config\Converter
 * @phpstan-import-type ActionDef from \MagoAssistant\Mago\Model\Manifest\Config\Converter
 * @phpstan-import-type ParamDef from \MagoAssistant\Mago\Model\Manifest\Config\Converter
 * @phpstan-import-type StepDef from \MagoAssistant\Mago\Model\Manifest\Config\Converter
 */
class ManifestTool implements
    ActionScopedToolInterface,
    ActionAclAwareToolInterface,
    IrreversibleToolInterface,
    PresentableToolInterface,
    AvailabilityAwareToolInterface
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const LIST_WRAPPERS = ['items', 'total_count'];

    /**
     * @param ToolDef $definition
     */
    public function __construct(
        private readonly array $definition,
        private readonly InternalApiClientInterface $apiClient,
        private readonly Placeholders $placeholders,
        private readonly SecureAdminUrl $adminUrl,
        private readonly ModuleManager $moduleManager
    ) {
    }

    public function getName(): string
    {
        return $this->definition['name'];
    }

    /**
     * The manifest as the converter read it, for checks that look at the routes behind the actions.
     *
     * @return ToolDef
     */
    public function getDefinition(): array
    {
        return $this->definition;
    }

    public function getDescription(): string
    {
        return $this->describe($this->definition['actions']);
    }

    public function getDescriptionForActions(array $actionNames): string
    {
        return $this->describe($this->selectActions($actionNames));
    }

    public function getParameterSchema(): array
    {
        return $this->schema($this->definition['actions']);
    }

    public function getParameterSchemaForActions(array $actionNames): array
    {
        return $this->schema($this->selectActions($actionNames));
    }

    public function isReadOnly(): bool
    {
        foreach ($this->definition['actions'] as $action) {
            if (!$action['readonly']) {
                return false;
            }
        }

        return true;
    }

    public function isReadOnlyAction(array $input): bool
    {
        $action = $this->action($input);

        return $action !== null ? $action['readonly'] : $this->isReadOnly();
    }

    public function getInstructions(): string
    {
        $parts = [];
        if ($this->definition['instructions'] !== '') {
            $parts[] = $this->definition['instructions'];
        }
        foreach ($this->definition['actions'] as $action) {
            if ($action['instructions'] !== '') {
                $parts[] = '## ' . $action['name'] . "\n" . $action['instructions'];
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * The manifest's field rules. The privacy filter matches keys at every depth and applies the
     * catch-all to a wrapper key too, so a catch-all of strip would drop a search result's whole
     * `items` list; the list wrappers a route answers with are therefore public unless the
     * manifest says otherwise. What is inside them still answers to the named rules.
     */
    public function getFieldClassification(string $action = ''): array
    {
        $definition = $this->definition['actions'][$action] ?? null;
        if ($definition === null) {
            return [];
        }
        $classification = [];
        foreach ($definition['fields'] as $field => $rule) {
            $classification[$field === '*' ? PiiClass::ANY : $field] = $rule;
        }
        if (isset($classification[PiiClass::ANY])) {
            foreach (self::LIST_WRAPPERS as $wrapper) {
                $classification[$wrapper] ??= [PiiClass::PUBLIC];
            }
        }

        return $classification;
    }

    /**
     * The action's own resource when it names one, else the tool's. An empty or unknown input
     * resolves to the tool's resource, which the manifest requires.
     */
    public function getMagentoAcl(array $input = []): string
    {
        return $this->action($input)['acl'] ?? $this->definition['acl'];
    }

    public function getActionAcl(array $input): ?string
    {
        return $this->action($input)['acl'] ?? null;
    }

    public function isIrreversibleAction(array $input): bool
    {
        return $this->action($input)['irreversible'] ?? false;
    }

    public function getImpacts(array $input, int $adminUserId): array
    {
        $action = $this->action($input);
        if ($action === null || !$action['irreversible']) {
            return [];
        }
        $impact = $action['impact'] ?? '';

        return $impact === ''
            ? ['This cannot be undone.']
            : [$this->placeholders->render($impact, $this->paramsOf($action, $input), [])];
    }

    public function getDisplayName(): string
    {
        return $this->definition['title'] !== ''
            ? $this->definition['title']
            : ucfirst(str_replace('_', ' ', $this->getName()));
    }

    public function getStatusMessage(string $action, array $input): ?string
    {
        $definition = $this->definition['actions'][$action] ?? null;
        if ($definition === null || ($definition['status'] ?? '') === '') {
            return null;
        }

        return $this->placeholders->render((string)$definition['status'], $this->paramsOf($definition, $input), []);
    }

    public function isAvailable(): bool
    {
        return $this->moduleManager->isEnabled($this->definition['module']);
    }

    public function execute(array $params): array
    {
        $action = $this->action($params);
        if ($action === null) {
            return ['error' => 'Unknown action: ' . (string)($params['action'] ?? '')];
        }

        $values = $this->paramsOf($action, $params);
        foreach ($action['params'] as $name => $param) {
            if ($param['required'] && !$this->placeholders->isSet('{' . $name . '}', $values, [])) {
                return ['error' => 'Parameter "' . $name . '" is required for ' . $action['name']];
            }
        }

        $adminUserId = (int)($params['_admin_user_id'] ?? 0);
        $storeCode = $this->storeCode($action, $values);
        $steps = [];
        $last = [];
        foreach ($action['steps'] as $step) {
            if ($step['when'] !== null && !$this->placeholders->isSet($step['when'], $values, $steps)) {
                $steps[$step['name']] = [];
                continue;
            }
            $response = $this->call($action, $step, $values, $steps, $adminUserId, $storeCode);
            if (isset($response['error'])) {
                return ['error' => $response['error']];
            }
            $steps[$step['name']] = $response;
            $last = $response;
        }

        $result = $last;
        if ($action['result'] !== null) {
            $picked = $this->placeholders->value($action['result'], $values, $steps);
            $result = is_array($picked) ? $picked : ['value' => $picked];
        }
        $result = $this->pick($result, $action['pick']);

        $links = $this->links($action, $result);
        if ($links !== []) {
            $result['_links'] = $links;
        }

        return $result;
    }

    /**
     * @param ActionDef $action
     * @param StepDef $step
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    private function call(
        array $action,
        array $step,
        array $values,
        array $steps,
        int $adminUserId,
        ?string $storeCode
    ): array {
        $path = $this->placeholders->render($step['path'], $values, $steps, true);

        if ($step['method'] === 'GET') {
            return $this->apiClient->get($path, $this->query($action, $step, $values), $adminUserId, $storeCode);
        }
        if ($step['method'] === 'DELETE') {
            return $this->apiClient->delete($path, $adminUserId, $storeCode);
        }

        $body = $this->body($action, $step, $values, $steps);
        if ($step['body'] !== null && $step['body'] !== '') {
            $body = [$step['body'] => $body];
        }

        return $step['method'] === 'PUT'
            ? $this->apiClient->put($path, $body, $adminUserId, $storeCode)
            : $this->apiClient->post($path, $body, $adminUserId, $storeCode);
    }

    /**
     * Query parameters of a GET step: searchCriteria from the params that name a criteria field,
     * plus every param placed in the query as is.
     *
     * @param ActionDef $action
     * @param StepDef $step
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function query(array $action, array $step, array $values): array
    {
        $filters = [];
        $query = [];
        $pageSize = self::DEFAULT_PAGE_SIZE;
        foreach ($action['params'] as $name => $param) {
            $value = $values[$name] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if ($param['in'] === 'page_size') {
                $pageSize = max(1, (int)$value);
            } elseif ($param['in'] === 'query') {
                $query[$name] = $value;
            } elseif ($param['criteria'] !== null) {
                $text = is_scalar($value) ? (string)$value : implode(',', array_map('strval', (array)$value));
                $filter = [
                    'field' => $param['criteria'],
                    'value' => $param['wrap'] !== null ? $param['wrap'] . $text . $param['wrap'] : $text,
                ];
                if ($param['condition'] !== null) {
                    $filter['condition_type'] = $param['condition'];
                }
                $filters[] = $filter;
            }
        }

        $sortOrders = null;
        if ($step['sort'] !== null && $step['sort'] !== '') {
            [$field, $direction] = array_pad(explode(':', $step['sort'], 2), 2, 'ASC');
            $sortOrders = [['field' => $field, 'direction' => strtoupper($direction)]];
        }

        $declaresCriteria = array_filter(
            $action['params'],
            static fn (array $param): bool => $param['criteria'] !== null || $param['in'] === 'page_size'
        ) !== [];
        if ($filters === [] && $sortOrders === null && !$declaresCriteria) {
            return $query;
        }

        return $this->apiClient->buildSearchCriteria($filters, $pageSize, 1, $sortOrders) + $query;
    }

    /**
     * Body of a writing step: the declared fields when the step lists them, else every body
     * parameter the call carries (path parameters included, as Magento expects the id in the entity).
     *
     * @param ActionDef $action
     * @param StepDef $step
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    private function body(array $action, array $step, array $values, array $steps): array
    {
        if ($step['fields'] !== []) {
            $body = [];
            foreach ($step['fields'] as $field) {
                $value = $this->placeholders->value($field['value'], $values, $steps)
                    ?? $this->placeholders->render($field['value'], $values, $steps);
                if ($value === '') {
                    continue;
                }
                $body[$field['name']] = $this->cast($value, $field['as']);
            }

            return $body;
        }

        $body = [];
        foreach ($action['params'] as $name => $param) {
            if (!in_array($param['in'], ['body', 'path'], true) || !array_key_exists($name, $values)) {
                continue;
            }
            $body[$name] = $values[$name];
        }

        return $body;
    }

    private function cast(mixed $value, string $as): mixed
    {
        return match ($as) {
            'int' => (int)(is_scalar($value) ? $value : 0),
            'string' => is_scalar($value) ? (string)$value : '',
            'list' => is_array($value) ? array_values($value) : [$value],
            default => $value,
        };
    }

    /**
     * The call's parameters with the manifest defaults filled in; only the ones the action declares.
     *
     * Some providers make the model fill in every parameter of the schema, with 0, "" or false for
     * the ones it has no value for. For an optional parameter that zero value therefore counts as
     * "not given": it is left out, or replaced by the manifest default. A required parameter keeps
     * it, so a genuine 0 can still be sent where the manifest asks for one.
     *
     * @param ActionDef $action
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function paramsOf(array $action, array $input): array
    {
        $values = [];
        foreach ($action['params'] as $name => $param) {
            $value = $input[$name] ?? null;
            $given = $value !== null && $value !== '' && ($param['required'] || !$this->isZeroValue($value));
            if ($given) {
                $values[$name] = $value;
            } elseif ($param['default'] !== null) {
                $values[$name] = $param['default'];
            }
        }

        return $values;
    }

    private function isZeroValue(mixed $value): bool
    {
        return $value === 0 || $value === 0.0 || $value === false || $value === [] || $value === '0';
    }

    /**
     * @param ActionDef $action
     * @param array<string, mixed> $values
     */
    private function storeCode(array $action, array $values): ?string
    {
        foreach ($action['params'] as $name => $param) {
            if ($param['in'] === 'store' && isset($values[$name]) && is_scalar($values[$name])) {
                return (string)$values[$name];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $pick
     * @return array<string, mixed>
     */
    private function pick(array $result, array $pick): array
    {
        if ($pick === []) {
            return $result;
        }
        $keys = array_flip($pick);
        if (isset($result['items']) && is_array($result['items'])) {
            $result['items'] = array_map(
                fn($item) => is_array($item) ? array_intersect_key($item, $keys) : $item,
                $result['items']
            );
            return array_intersect_key($result, $keys + ['items' => true, 'total_count' => true]);
        }

        return array_intersect_key($result, $keys);
    }

    /**
     * Admin links for the record, or one per item of a list, where the record carries the id field.
     *
     * @param ActionDef $action
     * @param array<string, mixed> $result
     * @return list<array{label: string, url: string}>
     */
    private function links(array $action, array $result): array
    {
        if ($action['links'] === []) {
            return [];
        }
        $records = isset($result['items']) && is_array($result['items']) ? $result['items'] : [$result];
        $links = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            foreach ($action['links'] as $link) {
                $id = $record[$link['from']] ?? null;
                if ($id === null || $id === '') {
                    continue;
                }
                $links[] = [
                    'label' => $this->placeholders->render($link['label'], $record, []),
                    'url' => $this->adminUrl->getUrl($link['route'], [$link['param'] => $id]),
                ];
            }
        }

        return $links;
    }

    /**
     * @param array<string, ActionDef> $actions
     */
    private function describe(array $actions): string
    {
        $parts = [];
        foreach ($actions as $action) {
            $parts[] = '"' . $action['name'] . '" (' . $action['description'] . ')';
        }

        return $this->definition['description'] . ' Actions: ' . implode(', ', $parts) . '.';
    }

    /**
     * @param array<string, ActionDef> $actions
     * @return array<string, mixed>
     */
    private function schema(array $actions): array
    {
        $properties = [];
        foreach ($actions as $action) {
            foreach ($action['params'] as $name => $param) {
                if (isset($properties[$name])) {
                    continue;
                }
                $properties[$name] = $this->paramSchema($param);
            }
        }

        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => array_values(array_map(fn(array $action) => $action['name'], $actions)),
                    'description' => 'The action to perform',
                ],
            ] + $properties,
            'required' => ['action'],
        ];
    }

    /**
     * @param ParamDef $param
     * @return array<string, mixed>
     */
    private function paramSchema(array $param): array
    {
        // A provider in strict mode makes the model send every property; null is the one value an
        // optional parameter can carry that cannot be mistaken for a filter or a field.
        $schema = ['type' => $param['required'] ? $param['type'] : [$param['type'], 'null']];
        if ($param['description'] !== '') {
            $schema['description'] = $param['description'];
        }
        if ($param['enum'] !== []) {
            $schema['enum'] = $param['enum'];
        }
        if ($param['type'] === 'array') {
            $schema['items'] = ['type' => $param['items'] ?? 'string'];
        }

        return $schema;
    }

    /**
     * @param string[] $actionNames
     * @return array<string, ActionDef>
     */
    private function selectActions(array $actionNames): array
    {
        return array_intersect_key($this->definition['actions'], array_flip($actionNames));
    }

    /**
     * @param array<string, mixed> $input
     * @return ActionDef|null
     */
    private function action(array $input): ?array
    {
        $name = $input['action'] ?? null;

        return is_string($name) ? ($this->definition['actions'][$name] ?? null) : null;
    }
}

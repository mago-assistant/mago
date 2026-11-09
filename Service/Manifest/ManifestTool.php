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
use MagoAssistant\Mago\Service\Skills\ResultLimit;
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
     * Where a parameter is a filter rather than a value: a zero there is a provider filling in a
     * schema property it has no value for, not a request to filter on zero.
     */
    private const FILTER_LOCATIONS = ['criteria', 'page_size', 'store', 'query'];

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
     * manifest says otherwise. What is inside them still answers to the named rules. An action
     * with links also classifies the links it adds itself.
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
        if ($definition['links'] !== []) {
            // The admin links the action adds, classified as the built-in tools classify theirs: the
            // signed url holds the admin secret key, so the model sees a token and the admin the url.
            $classification['_links'] ??= [PiiClass::PUBLIC];
            $classification['label'] ??= [PiiClass::PUBLIC];
            $classification['url'] ??= [PiiClass::TOKENISE, 'url'];
        }

        return $classification;
    }

    /**
     * The tool's resource, for every action. An action that names a resource of its own adds it
     * through getActionAcl(); ToolAccess checks both, so an action never loosens the tool's gate.
     */
    public function getMagentoAcl(array $input = []): string
    {
        return $this->definition['acl'];
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
            // A path parameter is always required: an empty one would leave a hole in the route.
            if (($param['required'] || $param['in'] === 'path')
                && !$this->placeholders->isSet('{' . $name . '}', $values, [])) {
                return ['error' => 'Parameter "' . $name . '" is required for ' . $action['name']];
            }
            if (array_key_exists($name, $values)) {
                $typed = $this->typed($values[$name], $param['type']);
                if ($typed === null) {
                    return ['error' => 'Parameter "' . $name . '" must be of type ' . $param['type']];
                }
                $values[$name] = $typed;
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
        // A route that answers with a bare list is a list: the same shape as a search result, so
        // pick, links and the privacy rules apply per row.
        if ($result !== [] && array_is_list($result)) {
            $result = ['items' => $result, 'total_count' => count($result)];
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
                $pageSize = min(max(1, (int)$value), ResultLimit::MAX);
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
     * the ones it has no value for. Null and an empty string always count as "not given". A zero
     * value (0, false, an empty list) counts as not given only where the parameter is a filter
     * (FILTER_LOCATIONS): a filter on zero is never meant, while a body field set to 0 or false is a
     * value the model chose, also against a manifest default.
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
            $filter = in_array($param['in'], self::FILTER_LOCATIONS, true);
            $given = $value !== null && $value !== '' && !($filter && $this->isZeroValue($value));
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
     * The value as the declared type, or null when it cannot be read as one: a path parameter typed
     * integer must not carry "search" into the route, and a wrong type must not reach a service.
     */
    private function typed(mixed $value, string $type): mixed
    {
        switch ($type) {
            case 'integer':
                if (is_int($value)) {
                    return $value;
                }
                return is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int)$value : null;
            case 'number':
                return is_int($value) || is_float($value) ? $value : (is_numeric($value) ? (float)$value : null);
            case 'boolean':
                if (is_bool($value)) {
                    return $value;
                }
                $map = ['true' => true, 'false' => false, '1' => true, '0' => false];
                return is_scalar($value) && array_key_exists((string)$value, $map) ? $map[(string)$value] : null;
            case 'array':
                return is_array($value) ? $value : null;
            default:
                return is_scalar($value) ? (string)$value : null;
        }
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

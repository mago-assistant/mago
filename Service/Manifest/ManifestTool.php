<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Manifest;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Api\Tool\ActionAclAwareToolInterface;
use MagoAssistant\Mago\Api\Tool\ActionScopedToolInterface;
use MagoAssistant\Mago\Api\Tool\AvailabilityAwareToolInterface;
use MagoAssistant\Mago\Api\Tool\CategorisedToolInterface;
use MagoAssistant\Mago\Api\Tool\IrreversibleToolInterface;
use MagoAssistant\Mago\Api\Tool\PresentableToolInterface;
use MagoAssistant\Mago\Api\Tool\ValidatingToolInterface;
use MagoAssistant\Mago\Service\Api\InProcess\ApiCall;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;
use MagoAssistant\Mago\Service\Api\InProcess\RouteResolver;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Skills\ResultLimit;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

/**
 * A tool another module declared in its etc/mago_tools.xml. Each action is one or more calls to
 * that module's REST routes, made through the internal API client as the admin user, so the
 * route's own ACL applies on top of the resource the manifest names. Nothing here knows what the
 * module is about; the manifest carries every word the model and the panel see.
 *
 * The model is not trusted: what it sends is checked against the declared type, enum and item
 * type before any call, and nothing it sends can name a route the manifest does not.
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
    AvailabilityAwareToolInterface,
    CategorisedToolInterface,
    ValidatingToolInterface
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const ERROR_KEY = 'error';
    private const DIRECTIVE_KEY = 'client_directive';
    private const ROUTE_PROBE = '0';
    private const OTHER_ROUTE = 'A value is the name of another route, so nothing was called. Use the id or code '
        . 'of a record for ';
    private const RESULT_KEY = 'result';

    /**
     * A value privacy mode has replaced by a token (mago://order_1 or [order_1]). The chat puts the
     * real value back before execute(); before the confirmation card the tool only sees the token.
     */
    private const TOKEN = '/^(mago:\/\/[^\s"\'<>\[\]]+_\d+|\[[^\s"\'<>\[\]]+_\d+\])$/';
    private const SEARCH_CRITERIA = SearchCriteriaInterface::class;

    /**
     * The largest whole number a float still represents exactly; beyond it a cast to int is a guess.
     */
    private const MAX_SAFE_INTEGER = 9007199254740992;

    /** @var array<string, ResolvedRoute|null> Routes looked up during this request, by method and path */
    private array $routes = [];

    /**
     * @param ToolDef $definition
     */
    public function __construct(
        private readonly array $definition,
        private readonly InternalApiClientInterface $apiClient,
        private readonly Placeholders $placeholders,
        private readonly SecureAdminUrl $adminUrl,
        private readonly ModuleManager $moduleManager,
        private readonly RouteResolver $routeResolver
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
     * The manifest's field rules, plus the rules for the structure this tool adds itself: its own
     * error message, the `items` / `total_count` wrapper of a list and the `_links` it builds. The
     * privacy filter matches keys by name at every depth, so those structure rules would also open
     * a route's own field of the same name; guardStructure() takes such a field out of the result
     * before the filter sees it.
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

        return $this->forcedRules($definition) + $classification + $this->gapRules($definition);
    }

    /**
     * Rules the manifest cannot change. The error message is this tool's own: under a catch-all of
     * strip the filter would drop it, and a failed write would read as done. The url of a link is
     * signed with the admin secret key, so the model only ever sees a token for it.
     *
     * @param ActionDef $action
     * @return array<string, array{0: string, 1?: string}>
     */
    private function forcedRules(array $action): array
    {
        $rules = [self::ERROR_KEY => [PiiClass::PUBLIC]];
        if ($action['links'] !== []) {
            $rules['url'] = [PiiClass::TOKENISE, 'url'];
        }

        return $rules;
    }

    /**
     * Rules for the rest of the structure this tool adds, where the manifest gave none: the `items`
     * and `total_count` of a list, the bare `result` of a write, and the label of a link.
     *
     * @param ActionDef $action
     * @return array<string, array{0: string, 1?: string}>
     */
    private function gapRules(array $action): array
    {
        // `result` is how a route's bare answer (true, an id) arrives; guardStructure() keeps it only
        // when it is that
        $rules = [
            'items' => [PiiClass::PUBLIC],
            'total_count' => [PiiClass::PUBLIC],
            self::RESULT_KEY => [PiiClass::PUBLIC],
        ];
        if ($action['links'] !== []) {
            $rules['_links'] = [PiiClass::PUBLIC];
            $rules['label'] = [PiiClass::PUBLIC];
        }

        return array_diff_key($rules, $action['fields']);
    }

    /**
     * Whether the manifest lets a field of this name reach the model as text.
     *
     * @param ActionDef $action
     */
    private function isPublicField(array $action, string $field): bool
    {
        $rule = $action['fields'][$field] ?? $action['fields']['*'] ?? null;

        // What the filter never lets through as text is not public here either (a link label)
        return ($rule[0] ?? null) === PiiClass::PUBLIC && !isset(PrivacyFilter::NEVER_PUBLIC[$field]);
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
            : [$this->placeholders->render($impact, $this->shown($action, $input), [])];
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

        return $this->placeholders->render((string)$definition['status'], $this->shown($definition, $input), []);
    }

    /**
     * The declaring module, in full: without its vendor two modules could share a category, or
     * land in one of the built-in ones.
     */
    public function getCategory(): string
    {
        return $this->definition['module'];
    }

    public function isAvailable(): bool
    {
        return $this->moduleManager->isEnabled($this->definition['module']);
    }

    /**
     * Before the confirmation card: a write the tool would refuse anyway must not be put to the
     * admin first.
     */
    public function findRefusal(array $input): ?array
    {
        $action = $this->action($input);
        if ($action === null || $action['steps'] === []) {
            return null;
        }
        // The card comes before the tokens are replaced by their values, so a token is not judged here
        [$values, $refusal] = $this->resolve($action, $input, true);
        $refusal ??= $this->routeRefusal($action, $values);

        return $refusal === null ? null : $this->failed($refusal);
    }

    public function execute(array $params): array
    {
        $action = $this->action($params);
        if ($action === null) {
            $name = $params['action'] ?? '';

            return $this->failed('Unknown action: ' . (is_scalar($name) ? (string)$name : ''));
        }
        if ($action['steps'] === []) {
            return $this->failed('The action ' . $action['name'] . ' has no route to call.');
        }

        [$values, $refusal] = $this->resolve($action, $params);
        $refusal ??= $this->routeRefusal($action, $values);
        if ($refusal !== null) {
            return $this->failed($refusal);
        }

        $adminUserId = (int)($params['_admin_user_id'] ?? 0);
        $storeCode = $this->storeCode($action, $values);
        $steps = [];
        $written = [];
        $ran = [];
        $last = null;
        $lastWasRead = false;
        foreach ($action['steps'] as $step) {
            if ($step['when'] !== null && !$this->placeholders->isSet($step['when'], $values, $steps)) {
                $steps[$step['name']] = [];
                continue;
            }
            $response = $this->call($action, $step, $values, $steps, $adminUserId, $storeCode);
            if ($this->isFailure($response)) {
                return $this->failed($this->afterEarlierSteps($response[self::ERROR_KEY], $step, $written));
            }
            $steps[$step['name']] = $response;
            $ran[] = $step['name'];
            $last = $response;
            $lastWasRead = $step['method'] === 'GET';
            if ($step['method'] !== 'GET') {
                $written[] = $step['name'];
            }
        }
        if ($last === null) {
            return $this->failed('Nothing was done: no step of ' . $action['name'] . ' applies to what was sent.');
        }

        $result = $last;
        // A result that names a step `when` skipped falls back to the last step that ran
        $named = explode('.', trim((string)$action['result'], '{}'))[1] ?? '';
        if ($action['result'] !== null && in_array($named, $ran, true)) {
            $picked = $this->placeholders->value($action['result'], $values, $steps);
            $result = is_array($picked) ? $picked : ['value' => $picked];
        }
        // A route that answers with a bare list is a list: the same shape as a search result, so
        // pick, links and the privacy rules apply per row. An empty answer to a read is an empty list.
        if (array_is_list($result) && ($result !== [] || $lastWasRead)) {
            $result = ['items' => $result, 'total_count' => count($result)];
        }
        $isList = $this->isList($result);

        // The links are read from the whole record, before pick narrows it: a label may name a
        // public field the result itself leaves out.
        $links = $this->links($action, $result, $isList);
        // A route's bare answer, as the API client hands it over: {"result": true} or an id. Judged
        // on what the route sent, before pick could make a record's own `result` field look like one.
        $bare = array_keys($result) === [self::RESULT_KEY]
            && (is_bool($result[self::RESULT_KEY]) || is_int($result[self::RESULT_KEY])
                || is_float($result[self::RESULT_KEY]));
        if (!$bare) {
            $result = $this->pick($result, $action['pick'], $isList);
        }
        $result = $this->guardStructure($action, $result, $isList, $bare);
        if ($links !== []) {
            $result['_links'] = $links;
        }

        return $result;
    }

    /**
     * @return array{error: string}
     */
    private function failed(string $message): array
    {
        return [self::ERROR_KEY => $message];
    }

    /**
     * A failed call is exactly ['error' => message], the shape the API client hands back. A record
     * that merely has a field called `error` (a log entry, a sync job) is a result, not a failure.
     *
     * @param array<array-key, mixed> $response
     */
    private function isFailure(array $response): bool
    {
        return count($response) === 1
            && is_string($response[self::ERROR_KEY] ?? null)
            && $response[self::ERROR_KEY] !== '';
    }

    /**
     * A later step that fails does not undo the steps before it. The model has to know which ones
     * were carried out, or it reports the whole action as failed and repeats them.
     *
     * @param StepDef $step
     * @param list<string> $written
     */
    private function afterEarlierSteps(string $message, array $step, array $written): string
    {
        if ($written === []) {
            return $message;
        }
        $several = count($written) > 1;

        return 'Step ' . $step['name'] . ' failed: ' . $message . ' The earlier step' . ($several ? 's ' : ' ')
            . implode(', ', $written) . ($several ? ' were' : ' was')
            . ' already carried out and not undone; do not repeat ' . ($several ? 'them' : 'it') . '.';
    }

    /**
     * A search result (`items` next to `total_count`, as Magento's getList answers, or the wrapper
     * this tool puts around a bare list). A single record that has an `items` field of its own, such
     * as an order or a return with its lines, is a record.
     *
     * @param array<array-key, mixed> $result
     */
    private function isList(array $result): bool
    {
        return is_array($result['items'] ?? null) && array_key_exists('total_count', $result);
    }

    /**
     * @param ActionDef $action
     * @param StepDef $step
     * @param array<string, mixed> $values
     * @param array<string, array<array-key, mixed>> $steps
     * @return array<array-key, mixed>
     */
    private function call(
        array $action,
        array $step,
        array $values,
        array $steps,
        int $adminUserId,
        ?string $storeCode
    ): array {
        // A placeholder in the path that resolves to nothing, or to a list, would leave a hole in
        // the route and could match another one (rma/{id}/items with an empty id is rma//items).
        foreach ($this->placeholders->names($step['path']) as $name) {
            $segment = $this->placeholders->value('{' . $name . '}', $values, $steps);
            if (!is_int($segment) && !is_float($segment) && (!is_string($segment) || $segment === '')) {
                return $this->failed('No value for {' . $name . '} in the route of ' . $action['name']);
            }
        }
        $path = $this->placeholders->render($step['path'], $values, $steps, true);
        if (!$this->staysOnItsRoute($action, $step, $path, $values, $steps)) {
            return $this->failed(self::OTHER_ROUTE . $action['name'] . '.');
        }

        if ($step['method'] === 'GET') {
            return $this->apiClient->get(
                $path,
                $this->query($action, $step, $values, $path),
                $adminUserId,
                $storeCode
            );
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
    private function query(array $action, array $step, array $values, string $path): array
    {
        $filters = [];
        $query = [];
        $pageSize = self::DEFAULT_PAGE_SIZE;
        foreach ($action['params'] as $name => $param) {
            if (!array_key_exists($name, $values) || $values[$name] === []) {
                continue;
            }
            $value = $values[$name];
            if ($param['in'] === 'page_size') {
                $pageSize = min(max(1, (int)$value), ResultLimit::MAX);
            } elseif ($param['in'] === 'query') {
                $query[$name] = $value;
            } elseif ($param['criteria'] !== null) {
                $text = implode(',', array_map($this->asText(...), is_array($value) ? $value : [$value]));
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
            $sortOrders = [['field' => $field, 'direction' => strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC']];
        }

        $declaresCriteria = array_filter(
            $action['params'],
            static fn (array $param): bool => $param['criteria'] !== null || $param['in'] === 'page_size'
        ) !== [];
        if ($filters === [] && $sortOrders === null && !$declaresCriteria
            && !$this->takesSearchCriteria($step['method'], $path)) {
            return $query;
        }

        return $this->apiClient->buildSearchCriteria($filters, $pageSize, 1, $sortOrders) + $query;
    }

    /**
     * A typed value as a filter reads it: false is 0, not the empty string PHP makes of it.
     */
    private function asText(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Body of a writing step: the declared fields when the step lists them, else every body
     * parameter the call carries (path parameters included, as Magento expects the id in the entity).
     *
     * @param ActionDef $action
     * @param StepDef $step
     * @param array<string, mixed> $values
     * @param array<string, array<array-key, mixed>> $steps
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
                $cast = $this->cast($value, $field['as']);
                if ($cast !== null) {
                    $body[$field['name']] = $cast;
                }
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

    /**
     * The value as the step field asks for it, or null when it cannot be read that way: a field
     * that would otherwise be sent as a made-up 0 or an empty text is left out of the body.
     */
    private function cast(mixed $value, string $as): mixed
    {
        return match ($as) {
            // As strict as an integer parameter: 5.7 is not 5, and a text is not 0
            'int' => is_bool($value) ? (int)$value : $this->integer($value),
            'string' => is_scalar($value) ? $this->asText($value) : null,
            'list' => is_array($value) ? array_values($value) : [$value],
            default => $value,
        };
    }

    /**
     * What the model sent for the action, checked and typed, with the manifest defaults filled in.
     *
     * Null and an empty string mean "not given": an optional parameter is nullable in the schema,
     * so a model that has to send every property sends null. Anything else is a value, 0 and false
     * included, and it replaces the default. A value that is not of the declared type, not one of
     * the declared enum values, or a list whose items are not of the declared item type is refused
     * before any call.
     *
     * @param ActionDef $action
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: ?string} The values and, when refused, why
     */
    private function resolve(array $action, array $input, bool $beforeTokens = false): array
    {
        $values = [];
        foreach ($action['params'] as $name => $param) {
            $raw = $input[$name] ?? null;
            if ($raw === null || $raw === '') {
                $raw = $this->defaultOf($param);
            }
            if ($raw === null) {
                // A parameter in the path of a step that always runs is required: an empty one would
                // leave a hole in the route, after earlier steps may have written
                if ($param['required'] || $param['in'] === 'path' || $this->isInAPath($action, (string)$name, true)) {
                    return [[], 'Parameter "' . $name . '" is required for ' . $action['name']];
                }
                continue;
            }
            if ($beforeTokens && $this->holdsToken($raw)) {
                continue;
            }

            $value = $this->typed($raw, $param['type'], $param['items'] ?? 'string');
            if ($value === null) {
                return [[], 'Parameter "' . $name . '" must be ' . $this->typeName($param)];
            }
            if ($param['enum'] !== [] && !$this->isOneOf($value, $param)) {
                return [[], 'Parameter "' . $name . '" must be one of: ' . implode(', ', $param['enum'])];
            }
            // An empty list is a value for a body field ("no tags"), but as a filter it filters on nothing
            if ($value === [] && $param['required'] && $param['criteria'] !== null) {
                return [[], 'Parameter "' . $name . '" is required for ' . $action['name'] . ' and cannot be empty'];
            }
            // Checked here and not at the call, so no earlier step has written by then
            if ((is_bool($value) || is_array($value)) && $this->isInAPath($action, (string)$name)) {
                return [[], 'Parameter "' . $name . '" is part of the route and must be a single text or number'];
            }
            if ($param['in'] === 'store' && !is_string($value) && !is_int($value)) {
                return [[], 'Parameter "' . $name . '" must be a store code'];
            }
            if ($param['in'] === 'page_size' && $value < 1) {
                // A page size below one is no page size: the manifest default, or the tool's own
                $value = $this->integer($this->defaultOf($param));
                if ($value === null || $value < 1) {
                    continue;
                }
            }
            $values[$name] = $value;
        }

        return [$values, null];
    }

    /**
     * @param ActionDef $action
     * @param bool $alwaysRuns Only steps without a `when`
     */
    private function isInAPath(array $action, string $name, bool $alwaysRuns = false): bool
    {
        foreach ($action['steps'] as $step) {
            if ($alwaysRuns && $step['when'] !== null) {
                continue;
            }
            if (in_array($name, $this->placeholders->names($step['path']), true)) {
                return true;
            }
        }

        return false;
    }

    private function holdsToken(mixed $value): bool
    {
        foreach (is_array($value) ? $value : [$value] as $entry) {
            if (is_string($entry) && preg_match(self::TOKEN, $entry) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checked before the first step, and before the confirmation card: the steps whose path is
     * made of parameters only. A path built from an earlier step's answer is checked at its call.
     *
     * @param ActionDef $action
     * @param array<string, mixed> $values
     */
    private function routeRefusal(array $action, array $values): ?string
    {
        foreach ($action['steps'] as $step) {
            $names = $this->placeholders->names($step['path']);
            if ($names === [] || array_diff($names, array_keys($values)) !== []) {
                continue;
            }
            foreach ($names as $name) {
                if ($this->holdsToken($values[$name])) {
                    continue 2;
                }
            }
            $path = $this->placeholders->render($step['path'], $values, [], true);
            if (!$this->staysOnItsRoute($action, $step, $path, $values, [])) {
                return self::OTHER_ROUTE . $action['name'] . '.';
            }
        }

        return null;
    }

    /**
     * The manifest default as the model would have sent it: a list for an array parameter.
     *
     * @param ParamDef $param
     */
    private function defaultOf(array $param): mixed
    {
        if ($param['default'] === null || $param['default'] === '') {
            return null;
        }
        if ($param['type'] !== 'array') {
            return $param['default'];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $param['default'])),
            static fn (string $entry): bool => $entry !== ''
        ));
    }

    /**
     * Defaults the manifest declares that the tool would refuse itself: every call that leaves the
     * parameter out would fail. For mago:tool:verify.
     *
     * @return list<string>
     */
    public function invalidDefaults(string $action): array
    {
        $problems = [];
        foreach ($this->definition['actions'][$action]['params'] ?? [] as $name => $param) {
            $type = $param['type'] === 'array' ? ($param['items'] ?? 'string') : $param['type'];
            foreach ($param['enum'] as $entry) {
                if ($this->typed($entry, $type) === null) {
                    $problems[] = 'enum value "' . $entry . '" of parameter "' . $name . '" is not a value of type '
                        . $type . ', so it can never be sent';
                }
            }
            $default = $this->defaultOf($param);
            if ($default === null) {
                continue;
            }
            $value = $this->typed($default, $param['type'], $param['items'] ?? 'string');
            if ($value === null) {
                $problems[] = 'default="' . $param['default'] . '" of parameter "' . $name . '" is not '
                    . ($param['type'] === 'array' ? $this->typeName($param) : 'a value ' . $this->typeName($param));
            } elseif ($param['enum'] !== [] && !$this->isOneOf($value, $param)) {
                $problems[] = 'default="' . $param['default'] . '" of parameter "' . $name
                    . '" is not one of its enum values';
            }
        }

        return $problems;
    }

    /**
     * @param ParamDef $param
     */
    private function typeName(array $param): string
    {
        return $param['type'] === 'array'
            ? 'a list of ' . ($param['items'] ?? 'string') . ' values'
            : 'of type ' . $param['type'];
    }

    /**
     * Compared as the declared type, so enum="1.0" allows the number 1.
     *
     * @param ParamDef $param
     */
    private function isOneOf(mixed $value, array $param): bool
    {
        $type = $param['type'] === 'array' ? ($param['items'] ?? 'string') : $param['type'];
        $allowed = array_map($this->asText(...), $this->enumOf($param['enum'], $type));
        foreach (is_array($value) ? $value : [$value] as $entry) {
            if (!in_array($this->asText($entry), $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The value as the declared type, or null when it cannot be read as one: a path parameter typed
     * integer must not carry "search" into the route, and a wrong type must not reach a service.
     */
    private function typed(mixed $value, string $type, string $items = 'string'): mixed
    {
        switch ($type) {
            case 'integer':
                return $this->integer($value);
            case 'number':
                if (is_int($value) || (is_float($value) && is_finite($value))) {
                    return $value;
                }
                return is_string($value) && is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
            case 'boolean':
                if (is_bool($value)) {
                    return $value;
                }
                $map = ['true' => true, 'false' => false, '1' => true, '0' => false];
                return (is_int($value) || is_string($value)) && array_key_exists((string)$value, $map)
                    ? $map[(string)$value]
                    : null;
            case 'array':
                if (!is_array($value) || !array_is_list($value)) {
                    return null;
                }
                $list = [];
                foreach ($value as $entry) {
                    $typed = is_array($entry) ? null : $this->typed($entry, $items);
                    if ($typed === null) {
                        return null;
                    }
                    $list[] = $typed;
                }
                return $list;
            default:
                return is_string($value) || is_int($value) || is_float($value) ? (string)$value : null;
        }
    }

    /**
     * A model may answer with 5.0 or "5" where the schema asks for an integer; anything that is not
     * a whole number PHP can hold exactly is refused rather than rounded.
     */
    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) && floor($value) === $value && abs($value) <= self::MAX_SAFE_INTEGER
                ? (int)$value
                : null;
        }

        return is_string($value) && preg_match('/^-?\d{1,18}\z/', $value) === 1 ? (int)$value : null;
    }

    /**
     * The declared parameters as sent, for the status line and the impact text the admin reads.
     * Nothing is called with these, so they are shown as they came in.
     *
     * @param ActionDef $action
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function shown(array $action, array $input): array
    {
        $values = [];
        foreach ($action['params'] as $name => $param) {
            $value = $input[$name] ?? null;
            if (is_bool($value)) {
                $values[$name] = $value ? 'yes' : 'no';
            } elseif (is_scalar($value) && $value !== '') {
                $values[$name] = $value;
            } elseif (is_array($value) && $value !== []) {
                $values[$name] = implode(', ', array_map($this->asText(...), $value));
            } elseif ($param['default'] !== null) {
                $values[$name] = $param['default'];
            }
        }

        return $values;
    }

    /**
     * Magento matches a literal route before one with a variable: with path="faq/{code}" the value
     * "search" would run /V1/faq/search instead of /V1/faq/:code, and its answer would be read with
     * this action's field rules. The route a call reaches may therefore not depend on a value the
     * model or an earlier step supplied: with a neutral value in that place it has to be the same
     * route. A parameter with an enum is exempt, since its values are the manifest's own
     * (rma/{id}/{transition} with transition one of approve, reject).
     *
     * @param ActionDef $action
     * @param StepDef $step
     * @param array<string, mixed> $values
     * @param array<string, array<array-key, mixed>> $steps
     */
    private function staysOnItsRoute(array $action, array $step, string $path, array $values, array $steps): bool
    {
        $free = array_filter(
            $this->placeholders->names($step['path']),
            static fn (string $name): bool => ($action['params'][$name]['enum'] ?? []) === []
        );
        if ($free === []) {
            return true;
        }
        $actual = $this->routeOf($step['method'], $path);
        if ($actual === null) {
            // No such route: the call itself answers with that
            return true;
        }
        foreach ($free as $name) {
            $template = str_replace('{' . $name . '}', self::ROUTE_PROBE, $step['path']);
            $probe = $this->placeholders->render($template, $values, $steps, true);
            if ($this->routeOf($step['method'], $probe) !== $actual) {
                return false;
            }
        }

        return true;
    }

    private function routeOf(string $method, string $path): ?string
    {
        $route = $this->route($method, $path);

        return $route === null
            ? null
            : $route->serviceClass . '::' . $route->serviceMethod . ' ' . $route->routePath;
    }

    private function route(string $method, string $path): ?ResolvedRoute
    {
        $key = $method . ' ' . $path;
        if (!array_key_exists($key, $this->routes)) {
            try {
                $this->routes[$key] = $this->routeResolver->resolve(new ApiCall($method, $path, [], [], 0, null));
            } catch (\Throwable) {
                $this->routes[$key] = null;
            }
        }

        return $this->routes[$key];
    }

    /**
     * Whether the route is a search (its service takes search criteria). Called without criteria
     * such a route answers with every row there is, so it always gets a page size, also when the
     * manifest declares no filter, page size or sort for it.
     */
    private function takesSearchCriteria(string $method, string $path): bool
    {
        $route = $this->route($method, $path);
        try {
            if ($route === null || !method_exists($route->serviceClass, $route->serviceMethod)) {
                return false;
            }
            $service = new \ReflectionMethod($route->serviceClass, $route->serviceMethod);
            foreach ($service->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && is_a($type->getName(), self::SEARCH_CRITERIA, true)) {
                    return true;
                }
                // Typed in the docblock only, as many service contracts are
                $documented = '/@param\s+\S*SearchCriteriaInterface\s+\$' . $parameter->getName() . '\b/';
                if ($type === null && preg_match($documented, (string)$service->getDocComment()) === 1) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * @param ActionDef $action
     * @param array<string, mixed> $values
     */
    private function storeCode(array $action, array $values): ?string
    {
        foreach ($action['params'] as $name => $param) {
            $value = $values[$name] ?? null;
            if ($param['in'] === 'store' && (is_string($value) || is_int($value)) && $value !== '') {
                return (string)$value;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $result
     * @param list<string> $pick
     * @return array<array-key, mixed>
     */
    private function pick(array $result, array $pick, bool $isList): array
    {
        if ($pick === []) {
            return $result;
        }
        $keys = array_flip($pick);
        if (!$isList) {
            return array_intersect_key($result, $keys);
        }
        $result['items'] = array_map(
            static fn (mixed $item): mixed => is_array($item) ? array_intersect_key($item, $keys) : $item,
            $result['items']
        );

        return array_intersect_key($result, $keys + ['items' => true, 'total_count' => true]);
    }

    /**
     * Keeps the structure rules to the structure. A field of the route that is called like something
     * this tool adds (`error`, and `items`, `total_count`, `label`, `url` or `_links` where the
     * manifest gave the name no rule of its own) is left out at every depth, and the list this tool
     * wraps keeps named fields only, so bare strings in it do not pass as public text. Runs before
     * the tool adds its own `_links`.
     *
     * @param ActionDef $action
     * @param array<array-key, mixed> $result
     * @return array<array-key, mixed>
     */
    private function guardStructure(array $action, array $result, bool $isList, bool $bare): array
    {
        $reserved = $this->reservedKeys($action);
        // `items` without a rule of its own: the rows of a list, or the lines of a record (an order,
        // a return). Either way only named fields are kept, so a bare text does not pass as public.
        $ownItems = in_array('items', $reserved, true);

        $guarded = [];
        foreach ($result as $key => $value) {
            if ($isList && $key === 'total_count') {
                // The count of a list is this tool's to pass on, whatever rule `items` has
                if (is_int($value)) {
                    $guarded[$key] = $value;
                }
            } elseif ($ownItems && $key === 'items' && is_array($value)) {
                $guarded[$key] = $this->rows($value, $reserved);
            } elseif ($isList && $key !== 'items' && !isset($action['fields'][$key])) {
                // Next to its rows a search result only carries what the manifest named (not
                // search_criteria)
                continue;
            } elseif ($bare || !is_string($key) || !in_array($key, $reserved, true)) {
                $guarded[$key] = is_array($value) ? $this->withoutKeys($value, $reserved) : $value;
            }
        }

        return $guarded;
    }

    /**
     * The names a route's own field may not have without a rule of its own in the manifest.
     *
     * @param ActionDef $action
     * @return list<string>
     */
    private function reservedKeys(array $action): array
    {
        // A client_directive is carried to the admin's browser unfiltered; only a tool may send one
        $reserved = [self::ERROR_KEY, self::DIRECTIVE_KEY];
        if ($this->isPublicField($action, PiiClass::ANY)) {
            return $reserved;
        }
        $reserved = [...$reserved, ...array_keys($this->gapRules($action))];
        if ($action['links'] !== [] && !isset($action['fields']['url'])) {
            $reserved[] = 'url';
        }

        return $reserved;
    }

    /**
     * The rows of the list this tool wraps. A row is a record with named fields; anything without a
     * name (a bare value, or a value in a list within the list) would inherit the wrapper's rule in
     * the filter, so it is left out.
     *
     * @param array<array-key, mixed> $list
     * @param list<string> $reserved
     * @return list<array<array-key, mixed>>
     */
    private function rows(array $list, array $reserved): array
    {
        $rows = [];
        foreach ($list as $row) {
            if (is_array($row)) {
                $rows[] = $this->row($row, $reserved);
            }
        }

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<string> $reserved
     * @return array<array-key, mixed>
     */
    private function row(array $row, array $reserved): array
    {
        $kept = [];
        foreach ($row as $key => $value) {
            if (is_int($key)) {
                if (is_array($value)) {
                    $kept[] = $this->row($value, $reserved);
                }
                continue;
            }
            if (!in_array($key, $reserved, true)) {
                $kept[$key] = is_array($value) ? $this->withoutKeys($value, $reserved) : $value;
            }
        }

        return $kept;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string> $keys
     * @return array<array-key, mixed>
     */
    private function withoutKeys(array $node, array $keys): array
    {
        $kept = [];
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, $keys, true)) {
                continue;
            }
            $kept[$key] = is_array($value) ? $this->withoutKeys($value, $keys) : $value;
        }

        return $kept;
    }

    /**
     * Admin links: one for the record, or one per row of a list, where it carries the id field. A
     * label reaches the model as text, so it is rendered from the record's public fields only: a
     * placeholder naming a tokenised, stripped or undeclared field stays empty (and fails verify),
     * or the label would carry a name past the rule the manifest gave it.
     *
     * @param ActionDef $action
     * @param array<array-key, mixed> $result
     * @return list<array{label: string, url: string}>
     */
    private function links(array $action, array $result, bool $isList): array
    {
        if ($action['links'] === []) {
            return [];
        }
        $links = [];
        foreach ($isList ? $result['items'] : [$result] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $public = array_filter(
                $record,
                fn (mixed $value, int|string $field): bool => is_scalar($value)
                    && $this->isPublicField($action, (string)$field),
                ARRAY_FILTER_USE_BOTH
            );
            foreach ($action['links'] as $link) {
                $id = $record[$link['from']] ?? null;
                if ((!is_int($id) && !is_string($id)) || $id === '') {
                    continue;
                }
                $links[] = [
                    'label' => $this->placeholders->render($link['label'], $public, []),
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
     * One schema for the given actions, in the plain form every provider accepts: one type per
     * parameter, only `action` required. A parameter several actions share is described once, and
     * the model is told which action means what. What an action requires is in its description and
     * is checked before the call (and before the confirmation card, for a write).
     *
     * @param array<string, ActionDef> $actions
     * @return array<string, mixed>
     */
    private function schema(array $actions): array
    {
        $declared = [];
        foreach ($actions as $action) {
            foreach ($action['params'] as $name => $param) {
                $declared[$name][$action['name']] = $param;
            }
        }

        $properties = [];
        foreach ($declared as $name => $perAction) {
            $properties[$name] = $this->paramSchema(reset($perAction), $this->describeParam($perAction));
        }

        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => array_values(array_map(static fn (array $action) => $action['name'], $actions)),
                    'description' => 'The action to perform',
                ],
            ] + $properties,
            'required' => ['action'],
        ];
    }

    /**
     * The description of one parameter name. Which actions require it is said here, since the
     * schema itself can only require what every action needs.
     *
     * @param array<string, ParamDef> $perAction The declarations of one parameter name, by action
     */
    private function describeParam(array $perAction): string
    {
        $descriptions = array_filter(
            array_map(static fn (array $param): string => $param['description'], $perAction)
        );
        $text = (string)reset($descriptions);
        if (count(array_unique($descriptions)) > 1) {
            $parts = [];
            foreach ($descriptions as $action => $description) {
                $parts[] = $action . ': ' . $description;
            }
            $text = implode('; ', $parts);
        }
        $requiredBy = array_keys(array_filter(
            $perAction,
            static fn (array $param): bool => $param['required'] || $param['in'] === 'path'
        ));
        if ($requiredBy === []) {
            return $text;
        }

        return ltrim($text . ' ') . '(required for ' . implode(', ', $requiredBy) . ')';
    }

    /**
     * @param ParamDef $param
     * @return array<string, mixed>
     */
    private function paramSchema(array $param, string $description): array
    {
        $schema = ['type' => $param['type']];
        if ($description !== '') {
            $schema['description'] = $description;
        }
        if ($param['type'] === 'array') {
            $itemType = $param['items'] ?? 'string';
            $schema['items'] = ['type' => $itemType];
            if ($param['enum'] !== []) {
                $schema['items']['enum'] = $this->enumOf($param['enum'], $itemType);
            }

            return $schema;
        }
        if ($param['enum'] !== []) {
            $schema['enum'] = $this->enumOf($param['enum'], $param['type']);
        }

        return $schema;
    }

    /**
     * The enum in the type the model has to send, or no value of that type could match it.
     *
     * @param list<string> $enum
     * @return list<mixed>
     */
    private function enumOf(array $enum, string $type): array
    {
        $values = [];
        foreach ($enum as $entry) {
            $values[] = $this->typed($entry, $type) ?? $entry;
        }

        return $values;
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

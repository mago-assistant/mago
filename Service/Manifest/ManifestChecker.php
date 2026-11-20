<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Manifest;

use Magento\Framework\Module\Manager as ModuleManager;
use MagoAssistant\Mago\Service\Api\InProcess\ApiCall;
use MagoAssistant\Mago\Service\Api\InProcess\RouteResolver;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Tool\Verify\AclResourceIndex;
use MagoAssistant\Mago\Service\Tool\Verify\ToolCheck;

/**
 * The checks mago:tool:verify adds for a manifest tool. The schema guards the shape of a manifest;
 * these guard what it means: that every step's route exists and is one an admin may call, that
 * every placeholder resolves to something, that what the model is shown matches what the tool
 * accepts, and that text reaches the model only where the author said so. Nothing is executed; a
 * route is matched the way a call would be, with a stand-in value in every placeholder.
 */
class ManifestChecker
{
    private const STAND_IN = '1';
    private const TODO_MARKER = 'TODO';
    private const RESERVED_PARAMS = ['action', 'steps'];
    private const SORT_PATTERN = '/^[A-Za-z0-9_.]+(:(asc|desc))?$/i';

    /**
     * Route resources that are not ACL resources: `anonymous` is open to everyone, an admin
     * included; `self` is the customer's own data and can never be an admin's.
     */
    private const OPEN_RESOURCE = 'anonymous';
    private const CUSTOMER_RESOURCE = 'self';

    /**
     * Field names that usually hold text a person typed. Verify cannot know who writes a field, so
     * it warns when one of these is public and leaves the decision to the author.
     */
    private const FREE_TEXT_WORDS = [
        'comment', 'message', 'description', 'note', 'text', 'remark', 'body', 'reason',
    ];

    /**
     * Last words that make a field an identifier or a code rather than typed text (reason_id).
     */
    private const IDENTIFIER_WORDS = ['id', 'ids', 'code', 'type', 'at', 'count'];

    public function __construct(
        private readonly RouteResolver $routeResolver,
        private readonly AclResourceIndex $aclResources,
        private readonly ModuleManager $moduleManager,
        private readonly Placeholders $placeholders
    ) {
    }

    /**
     * The checks for the action named in $params, or for every action without one.
     *
     * @param array<string, mixed> $params
     * @return ToolCheck[]
     */
    public function check(ManifestTool $tool, array $params): array
    {
        $definition = $tool->getDefinition();
        $checks = [
            $this->checkModule($definition['module']),
            ToolCheck::pass('Manifest format version ' . $definition['schema_version']),
        ];

        $actions = $definition['actions'];
        $named = $params['action'] ?? null;
        if ($named !== null) {
            if (!is_string($named) || !isset($actions[$named])) {
                $checks[] = ToolCheck::failure(
                    'There is no action "' . (is_scalar($named) ? (string)$named : '') . '"; the tool has: '
                    . implode(', ', array_keys($actions))
                );

                return $checks;
            }
            $actions = [$named => $actions[$named]];
        }

        $checks = [...$checks, ...$this->checkSharedParameters($definition['actions'])];
        $todo = $this->findTodoMarkers($definition);
        if ($todo !== []) {
            $checks[] = ToolCheck::failure(
                'The manifest still carries what mago:tool:manifest left for you to decide: '
                . implode(', ', array_slice($todo, 0, 5)) . (count($todo) > 5 ? ', ...' : '')
                . '. The model reads these words, and a TODO criteria field filters on nothing'
            );
        }

        foreach ($actions as $action) {
            $checks = [
                ...$checks,
                ...$this->checkDeclarations($tool, $action),
                ...$this->checkPlaceholders($action),
                ...$this->checkLinkLabels($action),
                ...$this->checkPrivacy($action),
                ...$this->checkSilentNoOps($action),
            ];
            if ($action['steps'] === []) {
                $checks[] = ToolCheck::failure(
                    'Action "' . $action['name'] . '" has no route: give it method and path, or <step> elements'
                );
                continue;
            }
            foreach ($action['steps'] as $step) {
                $checks[] = $this->checkStep($definition['acl'], $action, $step);
            }
        }

        return $checks;
    }

    private function checkModule(string $module): ToolCheck
    {
        return $this->moduleManager->isEnabled($module)
            ? ToolCheck::pass('Declaring module ' . $module . ' is enabled')
            : ToolCheck::failure(
                'Declaring module ' . $module . ' is not enabled, so the tool is left out of the registry'
            );
    }

    /**
     * The model is shown one schema for the whole tool, so a parameter name carries one type and
     * one set of allowed values. Two actions that disagree would have the model send the first
     * declaration to both, and the second call refuses it.
     *
     * @param array<string, array<string, mixed>> $actions
     * @return ToolCheck[]
     */
    private function checkSharedParameters(array $actions): array
    {
        $seen = [];
        $clashes = [];
        foreach ($actions as $action) {
            foreach ($action['params'] as $name => $param) {
                $first = $seen[$name] ?? null;
                if ($first === null) {
                    $seen[$name] = ['param' => $param, 'action' => $action['name']];
                    continue;
                }
                if ($first['param']['type'] !== $param['type']) {
                    $clashes[$name] = 'Parameter "' . $name . '" is ' . $first['param']['type'] . ' in action "'
                        . $first['action'] . '" and ' . $param['type'] . ' in action "' . $action['name']
                        . '"; the model is shown one schema per tool, so give one of them its own name';
                } elseif ($this->sorted($first['param']['enum']) !== $this->sorted($param['enum'])
                    || ($first['param']['items'] ?? 'string') !== ($param['items'] ?? 'string')) {
                    $clashes[$name] ??= 'Parameter "' . $name . '" allows different values in action "'
                        . $first['action'] . '" and in action "' . $action['name'] . '" (enum or items); the '
                        . 'model is shown one schema per tool, so give one of them its own name';
                }
            }
        }

        return array_values(array_map(ToolCheck::failure(...), $clashes));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * Whether the draft's marker is still there: "TODO: ..." or a TODO_ stand-in, not the word
     * TODO in a description of a task module.
     */
    private function isTodo(string $text): bool
    {
        return str_starts_with(ltrim($text), self::TODO_MARKER . ':') || str_contains($text, self::TODO_MARKER . '_');
    }

    /**
     * Declarations that the schema allows and the runtime would silently ignore or always refuse.
     *
     * @param array<string, mixed> $action
     * @return ToolCheck[]
     */
    private function checkDeclarations(ManifestTool $tool, array $action): array
    {
        $label = 'Action "' . $action['name'] . '"';
        $checks = [];
        if ($action['readonly_declared'] && !$action['readonly']) {
            $checks[] = ToolCheck::failure(
                $label . ' is marked readonly but has a step that writes (POST, PUT or '
                . 'DELETE); it is treated as a write, so the admin confirms it. Remove readonly="true"'
            );
        }
        foreach ($tool->invalidDefaults($action['name']) as $problem) {
            $checks[] = ToolCheck::failure($label . ': ' . $problem);
        }

        if (($action['acl'] ?? '') !== '' && !$this->aclResources->has((string)$action['acl'])) {
            $checks[] = ToolCheck::failure(
                $label . ': acl="' . $action['acl'] . '" is declared in no acl.xml, so no restricted admin '
                . 'could ever run it'
            );
        }

        $methods = array_column($action['steps'], 'method');
        $unsent = [];
        foreach ($action['params'] as $name => $param) {
            if (in_array($name, self::RESERVED_PARAMS, true)) {
                $checks[] = ToolCheck::failure(
                    $label . ': a parameter cannot be called "' . $name . '", the tool uses that name itself'
                );
            }
            if ($param['type'] === 'array' && ($param['in'] === 'path' || $this->isInAPath($action, (string)$name))) {
                $checks[] = ToolCheck::failure(
                    $label . ': parameter "' . $name . '" is a list and cannot be one segment of a path'
                );
            }
            if ($param['in'] === 'page_size' && $param['type'] !== 'integer') {
                $checks[] = ToolCheck::failure(
                    $label . ': parameter "' . $name . '" is the page size and must be type="integer"'
                );
            }
            if ($param['type'] === 'boolean' && $this->isInAPath($action, (string)$name)) {
                $checks[] = ToolCheck::failure(
                    $label . ': parameter "' . $name . '" is a boolean and cannot be a segment of a path'
                );
            }
            if ($param['in'] === 'store' && $param['type'] !== 'string') {
                $checks[] = ToolCheck::failure(
                    $label . ': parameter "' . $name . '" sets the store and must be type="string": a call runs in '
                    . 'a store by its code'
                );
            }
            if (!$this->isSent($action, (string)$name, $param)) {
                $unsent[] = $name;
            }
        }
        $demanded = array_values(array_filter(
            $unsent,
            static fn (int|string $name): bool => (bool)$action['params'][$name]['required']
        ));
        if ($demanded !== [] && $methods !== []) {
            $checks[] = ToolCheck::failure(
                $label . ' requires ' . implode(', ', $demanded) . ' and never sends it: the admin would have to '
                . 'give and confirm a value that no call carries (a DELETE takes no query parameters or body)'
            );
        }
        $unsent = array_values(array_diff($unsent, $demanded));
        if ($unsent !== [] && $methods !== []) {
            $checks[] = ToolCheck::warning(
                $label . ' never sends ' . implode(', ', $unsent) . ': a body parameter needs a POST or PUT step '
                . 'without <field> elements or a <field value="{name}"> of its own, a criteria, query or page_size '
                . 'parameter a GET step. The admin would confirm a value that is not used'
            );
        }
        if (($action['impact'] ?? '') !== '' && !$action['irreversible']) {
            $checks[] = ToolCheck::warning(
                $label . ' has an impact text that is never shown: it belongs to irreversible="true"'
            );
        }
        foreach ($action['steps'] as $step) {
            if (($step['sort'] ?? '') !== '' && preg_match(self::SORT_PATTERN, (string)$step['sort']) !== 1) {
                $checks[] = ToolCheck::failure(
                    $label . ': sort="' . $step['sort'] . '" must be a field, or field:asc or field:desc'
                );
            }
        }

        return $checks;
    }

    /**
     * @param array<string, mixed> $action
     */
    private function isInAPath(array $action, string $name): bool
    {
        foreach ($action['steps'] as $step) {
            if (in_array($name, $this->placeholders->names($step['path']), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Things that read as if they work and do nothing: a `when` that is more than one placeholder
     * always runs, and `pick` and a link's `from` only see the fields of the record itself.
     *
     * @param array<string, mixed> $action
     * @return ToolCheck[]
     */
    private function checkSilentNoOps(array $action): array
    {
        $label = 'Action "' . $action['name'] . '"';
        $checks = [];
        foreach ($action['steps'] as $step) {
            $when = (string)($step['when'] ?? '');
            if ($when !== '' && preg_match('/^\{[^{}]+\}$/', $when) !== 1) {
                $checks[] = ToolCheck::warning(
                    $label . ': when="' . $when . '" is more than one placeholder. A step runs when the text is '
                    . 'not empty, so this one always runs; there are no comparisons or negations'
                );
            }
        }
        $nested = array_filter(
            [...$action['pick'], ...array_column($action['links'], 'from')],
            static fn (string $field): bool => str_contains($field, '.')
        );
        if ($nested !== []) {
            $checks[] = ToolCheck::warning(
                $label . ': ' . implode(', ', array_unique($nested)) . ' names a field inside another. <pick> '
                . 'and a link\'s from only read the fields of the record itself, so nothing is found'
            );
        }

        return $checks;
    }

    /**
     * Whether any step would carry the parameter: by its own place, or because a placeholder names it.
     *
     * @param array<string, mixed> $action
     * @param array<string, mixed> $param
     */
    private function isSent(array $action, string $name, array $param): bool
    {
        if ($param['in'] === 'store') {
            return true;
        }
        $isFilter = $param['criteria'] !== null || in_array($param['in'], ['page_size', 'query'], true);
        foreach ($action['steps'] as $step) {
            $expressions = [$step['path'], (string)($step['when'] ?? ''), ...array_column($step['fields'], 'value')];
            if (in_array($name, $this->placeholders->names(implode(' ', $expressions)), true)) {
                return true;
            }
            if ($isFilter && $step['method'] === 'GET') {
                return true;
            }
            $inBody = in_array($param['in'], ['body', 'path'], true) && !$isFilter;
            if ($inBody && in_array($step['method'], ['POST', 'PUT'], true) && $step['fields'] === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every placeholder has to name something that exists when it is rendered: a parameter of the
     * action, or a field of a step that ran before. One that names nothing renders empty without
     * a word, and a `when` on it skips its step for ever.
     *
     * @param array<string, mixed> $action
     * @return ToolCheck[]
     */
    private function checkPlaceholders(array $action): array
    {
        $label = 'Action "' . $action['name'] . '"';
        $params = array_map('strval', array_keys($action['params']));
        $problems = [];

        foreach (['status', 'impact'] as $attribute) {
            $unknown = array_diff($this->placeholders->names((string)($action[$attribute] ?? '')), $params);
            if ($unknown !== []) {
                $problems[] = $attribute . ' uses {' . implode('}, {', $unknown)
                    . '}, which is no parameter of the action';
            }
        }

        $earlier = [];
        foreach ($action['steps'] as $step) {
            // A parameter missing from the path is reported with the route, in checkStep()
            $inPath = array_filter(
                $this->placeholders->names($step['path']),
                static fn (string $name): bool => str_starts_with($name, 'steps.')
            );
            $expressions = [(string)($step['when'] ?? ''), ...array_column($step['fields'], 'value')];
            foreach ([...$inPath, ...$this->placeholders->names(implode(' ', $expressions))] as $name) {
                $problem = $this->placeholderProblem($name, $params, $earlier, in_array($name, $inPath, true));
                if ($problem !== null) {
                    $problems[] = 'step "' . $step['name'] . '" uses {' . $name . '}, ' . $problem;
                }
            }
            $earlier[] = $step['name'];
        }

        $result = $action['result'] ?? null;
        if ($result !== null) {
            $names = $this->placeholders->names($result);
            $step = explode('.', $names[0] ?? '')[1] ?? '';
            $wholeStep = count(explode('.', $names[0] ?? '')) === 2;
            if (count($names) !== 1 || $result !== '{' . $names[0] . '}' || !$wholeStep
                || !in_array($step, $earlier, true)) {
                $problems[] = 'result="' . $result . '" must be one placeholder naming a step, such as {steps.'
                    . ($earlier[0] ?? 'save') . '}';
            }
        }

        return array_map(
            static fn (string $problem): ToolCheck => ToolCheck::failure($label . ': ' . $problem),
            array_values(array_unique($problems))
        );
    }

    /**
     * @param list<string> $params
     * @param list<string> $earlierSteps
     */
    private function placeholderProblem(string $name, array $params, array $earlierSteps, bool $inPath): ?string
    {
        $segments = explode('.', $name);
        if ($segments[0] !== 'steps') {
            return in_array($name, $params, true)
                ? null
                : 'which no <param> of the action declares; it would be left empty';
        }
        if (count($segments) < ($inPath ? 3 : 2)) {
            return 'which names no field of a step: write {steps.<step>.<field>}';
        }

        return in_array($segments[1], $earlierSteps, true)
            ? null
            : 'but no step called "' . $segments[1] . '" runs before it';
    }

    /**
     * A link label reaches the model as text, whatever the rule of the fields it names. It may
     * therefore only name fields the action classifies public; the tool renders anything else empty.
     *
     * @param array<string, mixed> $action
     * @return ToolCheck[]
     */
    private function checkLinkLabels(array $action): array
    {
        $checks = [];
        foreach ($action['links'] as $link) {
            $hidden = array_filter(
                $this->placeholders->names($link['label']),
                fn (string $field): bool => str_contains($field, '.') || !$this->isPublicField($action, $field)
            );
            if ($hidden !== []) {
                $checks[] = ToolCheck::failure(
                    'Action "' . $action['name'] . '": the link label "' . $link['label'] . '" uses {'
                    . implode('}, {', $hidden) . '}, which the action does not classify public. A label reaches '
                    . 'the model as text, so it is rendered without that value; name a public field instead'
                );
            }
        }

        return $checks;
    }

    /**
     * What of a result reaches the model. Text a customer can write is where a prompt injection
     * arrives, so public free text is pointed out; so are rules that do not do what they seem to.
     *
     * @param array<string, mixed> $action
     * @return ToolCheck[]
     */
    private function checkPrivacy(array $action): array
    {
        $label = 'Action "' . $action['name'] . '"';
        $fields = $action['fields'];
        if ($fields === []) {
            return [ToolCheck::warning(
                $label . ' classifies no field, so the model sees nothing of its result but an error. Declare '
                . '<field name="..." class="public|tokenise|strip"/> for what it may see'
            )];
        }

        $checks = [];
        foreach ($fields as $field => $rule) {
            $token = (string)($rule[1] ?? '');
            if ($rule[0] === PiiClass::TOKENISE && preg_match(PiiClass::TOKEN_TYPE_PATTERN, $token) !== 1) {
                $checks[] = ToolCheck::failure(
                    $label . ': field "' . $field . '" is tokenised ' . ($token === ''
                        ? 'without a token type'
                        : 'with token="' . $token . '", which is no valid token type')
                    . '; use lowercase letters only, e.g. token="name"'
                );
            }
        }
        if (($fields['*'][0] ?? null) !== PiiClass::STRIP && !$this->isPublicField($action, '*')) {
            $checks[] = ToolCheck::warning(
                $label . ' has no <fields class="strip"/>. Without it the names of nested fields the action does '
                . 'not classify still reach the model, and in a map keyed by data (an e-mail address, a name) '
                . 'those names are data. Add <fields class="strip"/>'
            );
        }
        if ($this->isPublicField($action, '*')) {
            $checks[] = ToolCheck::warning(
                $label . ' classifies every field it does not name as public '
                . '(<fields class="public"/>): whatever the route returns reaches the model as text. Name the '
                . 'public fields and strip the rest'
            );
        }
        $freeText = [];
        foreach (array_keys($fields) as $field) {
            $words = explode('_', strtolower((string)$field));
            if (array_intersect($words, self::FREE_TEXT_WORDS) !== []
                && !in_array(end($words), self::IDENTIFIER_WORDS, true)
                && $this->isPublicField($action, (string)$field)) {
                $freeText[] = (string)$field;
            }
        }
        if ($freeText !== []) {
            $checks[] = ToolCheck::warning(
                $label . ' sends ' . implode(', ', $freeText) . ' to the model as text. If a '
                . 'customer or another outside party can write there, classify it tokenise: that text is where a '
                . 'prompt injection arrives'
            );
        }
        if (isset($fields['error'])) {
            $checks[] = ToolCheck::warning(
                $label . ' declares a field called error. That name is the tool\'s own error message, so a '
                . 'route field called error is always left out of the result'
            );
        }
        if ($action['links'] !== [] && ($fields['url'][0] ?? 'tokenise') !== 'tokenise') {
            $checks[] = ToolCheck::warning(
                $label . ' has links and a rule for a field called url. A link\'s url is signed with the admin '
                . 'key, so every url of this action is tokenised whatever that rule says'
            );
        }
        $unseen = array_filter(
            $action['pick'],
            static fn (string $field): bool => !in_array(
                ($fields[$field] ?? $fields['*'] ?? ['strip'])[0],
                ['public', 'tokenise'],
                true
            )
        );
        if ($unseen !== []) {
            $checks[] = ToolCheck::warning(
                $label . ' picks ' . implode(', ', $unseen) . ', which no rule lets through: the model does not '
                . 'see it. Give it a <field> rule'
            );
        }

        return $checks;
    }

    /**
     * @param array<string, mixed> $action
     */
    private function isPublicField(array $action, string $field): bool
    {
        $rule = $action['fields'][$field] ?? $action['fields']['*'] ?? null;

        return ($rule[0] ?? null) === 'public' && !isset(PrivacyFilter::NEVER_PUBLIC[$field]);
    }

    /**
     * Where the draft the scaffolder wrote still says TODO. The model is given these words as the
     * tool's description, and a TODO criteria field would filter on a column that does not exist.
     *
     * @param array<string, mixed> $definition
     * @return list<string>
     */
    private function findTodoMarkers(array $definition): array
    {
        $found = [];
        foreach (['description', 'instructions'] as $field) {
            if ($this->isTodo((string)($definition[$field] ?? ''))) {
                $found[] = 'the tool ' . $field;
            }
        }
        foreach ($definition['actions'] as $action) {
            foreach (['description', 'instructions', 'impact', 'status', 'acl'] as $field) {
                if ($this->isTodo((string)($action[$field] ?? ''))) {
                    $found[] = 'action "' . $action['name'] . '" ' . $field;
                }
            }
            foreach ($action['params'] as $name => $param) {
                foreach (['description', 'criteria'] as $field) {
                    if ($this->isTodo((string)($param[$field] ?? ''))) {
                        $found[] = 'parameter "' . $name . '" ' . $field;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $action
     * @param array{name: string, method: string, path: string} $step
     */
    private function checkStep(string $toolAcl, array $action, array $step): ToolCheck
    {
        $label = 'Action "' . $action['name'] . '"' . ($step['name'] === 'main' ? '' : ' step "' . $step['name'] . '"');
        $unknown = array_filter(
            $this->placeholders->names($step['path']),
            static fn (string $name): bool => !isset($action['params'][$name]) && !str_starts_with($name, 'steps.')
        );
        if ($unknown !== []) {
            return ToolCheck::failure(
                $label . ': the path uses {' . implode('}, {', $unknown) . '}, which no <param> of the action '
                . 'declares; the call would leave that segment empty'
            );
        }
        // A field of an earlier step stands in as one segment, like a parameter does
        $path = $this->placeholders->render(
            (string)preg_replace('/\{steps\.[^}]+\}/', self::STAND_IN, $step['path']),
            $this->standIns($action),
            [],
            true
        );

        try {
            $route = $this->routeResolver->resolve(new ApiCall($step['method'], $path, [], [], 0, null));
        } catch (\Throwable $exception) {
            return ToolCheck::failure(
                $label . ': no route matches ' . $step['method'] . ' /V1/' . $path . ' in any webapi.xml ('
                . $exception->getMessage() . ')'
            );
        }

        if (in_array(self::CUSTOMER_RESOURCE, $route->aclResources, true)) {
            return ToolCheck::failure(
                $label . ': route ' . $route->routePath . ' is the customer\'s own ("self"); an admin can never '
                . 'call it, so it cannot be a chat tool'
            );
        }
        $resources = array_values(array_diff($route->aclResources, [self::OPEN_RESOURCE]));
        $undeclared = array_filter(
            $resources,
            fn (string $resource): bool => !$this->aclResources->has($resource)
        );
        if ($undeclared !== []) {
            return ToolCheck::failure(
                $label . ': route ' . $route->routePath . ' is gated by ' . implode(', ', $undeclared)
                . ', which no acl.xml declares'
            );
        }

        $manifestAcls = array_values(array_filter([$toolAcl, $action['acl'] ?? null]));
        if ($resources !== [] && array_intersect($manifestAcls, $resources) === []) {
            return ToolCheck::warning(
                $label . ': the manifest gates it by ' . implode(' and ', $manifestAcls) . ', the route '
                . $route->routePath . ' by ' . implode(', ', $resources) . '; all apply, so an admin '
                . 'needs each of them'
            );
        }

        return ToolCheck::pass(
            $label . ': ' . $step['method'] . ' ' . $route->routePath . ' -> ' . $route->serviceClass . '::'
            . $route->serviceMethod . '()'
        );
    }

    /**
     * A value for every declared parameter, so each {param} in a path resolves to something.
     *
     * @param array<string, mixed> $action
     * @return array<string, string>
     */
    private function standIns(array $action): array
    {
        $values = [];
        foreach ($action['params'] as $name => $param) {
            // A path segment that is one of a few words (approve, reject) is matched as its first
            $values[(string)$name] = $param['enum'][0] ?? self::STAND_IN;
        }

        return $values;
    }
}

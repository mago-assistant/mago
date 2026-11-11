<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Manifest;

use Magento\Framework\Module\Manager as ModuleManager;
use MagoAssistant\Mago\Model\Manifest\Config\Reader;
use MagoAssistant\Mago\Service\Api\InProcess\ApiCall;
use MagoAssistant\Mago\Service\Api\InProcess\RouteResolver;
use MagoAssistant\Mago\Service\Tool\Verify\AclResourceIndex;
use MagoAssistant\Mago\Service\Tool\Verify\ToolCheck;

/**
 * The checks mago:tool:verify adds for a manifest tool: whether the declaring module is enabled,
 * whether every step's route exists in the store's webapi.xml, and whether the resource the
 * manifest names agrees with the one the route is gated by. Nothing is executed; a route is
 * matched the way a call would be, with a placeholder value in every path parameter.
 */
class ManifestChecker
{
    private const PLACEHOLDER_VALUE = '1';
    private const TODO_MARKER = 'TODO';

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
        $checks = [$this->checkModule($definition['module']), $this->checkSchemaVersion($definition['schema_version'])];

        $actionName = $params['action'] ?? null;
        $actions = is_string($actionName) && isset($definition['actions'][$actionName])
            ? [$definition['actions'][$actionName]]
            : $definition['actions'];

        $checks = [...$checks, ...$this->checkParameterTypes($definition['actions'])];
        $todo = $this->findTodoMarkers($definition);
        if ($todo !== []) {
            $checks[] = ToolCheck::failure(
                'The manifest still carries what mago:tool:manifest left for you to decide: '
                . implode(', ', array_slice($todo, 0, 5)) . (count($todo) > 5 ? ', ...' : '')
                . '. The model reads these words, and a TODO criteria field filters on nothing'
            );
        }

        foreach ($actions as $action) {
            if ($action['readonly_declared'] && !$action['readonly']) {
                $checks[] = ToolCheck::failure(
                    'Action "' . $action['name'] . '" is marked readonly but has a step that writes (POST, PUT or '
                    . 'DELETE); it is treated as a write, so the admin confirms it. Remove readonly="true"'
                );
            }
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

    /**
     * The model is shown one schema for the whole tool, so a parameter name carries one type. Two
     * actions declaring the same name with different types would have the model send the first
     * type to both, and the second call refuses it.
     *
     * @param array<string, array<string, mixed>> $actions
     * @return ToolCheck[]
     */
    private function checkParameterTypes(array $actions): array
    {
        $seen = [];
        $clashes = [];
        foreach ($actions as $action) {
            foreach ($action['params'] as $name => $param) {
                $type = $param['type'];
                if (isset($seen[$name]) && $seen[$name]['type'] !== $type) {
                    $clashes[$name] = 'Parameter "' . $name . '" is ' . $seen[$name]['type'] . ' in action "'
                        . $seen[$name]['action'] . '" and ' . $type . ' in action "' . $action['name']
                        . '"; the model is shown one schema per tool, so give one of them its own name';
                    continue;
                }
                $seen[$name] = ['type' => $type, 'action' => $action['name']];
            }
        }

        return array_values(array_map(ToolCheck::failure(...), $clashes));
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
        if (str_contains($definition['description'], self::TODO_MARKER)) {
            $found[] = 'the tool description';
        }
        foreach ($definition['actions'] as $action) {
            foreach (['description', 'instructions', 'impact', 'status'] as $field) {
                if (str_contains((string)($action[$field] ?? ''), self::TODO_MARKER)) {
                    $found[] = 'action "' . $action['name'] . '" ' . $field;
                }
            }
            foreach ($action['params'] as $name => $param) {
                foreach (['description', 'criteria'] as $field) {
                    if (str_contains((string)($param[$field] ?? ''), self::TODO_MARKER)) {
                        $found[] = 'parameter "' . $name . '" ' . $field;
                    }
                }
            }
        }

        return $found;
    }

    private function checkSchemaVersion(string $version): ToolCheck
    {
        return $version === Reader::SCHEMA_VERSION
            ? ToolCheck::pass('Manifest format version ' . $version)
            : ToolCheck::warning(
                'Manifest format version ' . $version . ' is older than the current ' . Reader::SCHEMA_VERSION
                . '; it is still read, but check the release notes for what changed'
            );
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
        $path = $this->placeholders->render($step['path'], $this->placeholderValues($action), [], true);

        try {
            $route = $this->routeResolver->resolve(new ApiCall($step['method'], $path, [], [], 0, null));
        } catch (\Throwable $exception) {
            return ToolCheck::failure(
                $label . ': no route matches ' . $step['method'] . ' /V1/' . $path . ' in any webapi.xml ('
                . $exception->getMessage() . ')'
            );
        }

        $undeclared = array_filter(
            $route->aclResources,
            fn (string $resource): bool => !$this->aclResources->has($resource)
        );
        if ($undeclared !== []) {
            return ToolCheck::failure(
                $label . ': route ' . $route->routePath . ' is gated by ' . implode(', ', $undeclared)
                . ', which no acl.xml declares'
            );
        }

        $manifestAcls = array_values(array_filter([$toolAcl, $action['acl'] ?? null]));
        if ($route->aclResources !== [] && array_intersect($manifestAcls, $route->aclResources) === []) {
            return ToolCheck::warning(
                $label . ': the manifest gates it by ' . implode(' and ', $manifestAcls) . ', the route '
                . $route->routePath . ' by ' . implode(', ', $route->aclResources) . '; all apply, so an admin '
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
    private function placeholderValues(array $action): array
    {
        $values = [];
        foreach (array_keys($action['params']) as $name) {
            $values[(string)$name] = self::PLACEHOLDER_VALUE;
        }

        return $values;
    }
}

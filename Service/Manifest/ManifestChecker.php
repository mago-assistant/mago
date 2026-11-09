<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Manifest;

use Magento\Framework\Module\Manager as ModuleManager;
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
        $checks = [$this->checkModule($definition['module'])];

        $actionName = $params['action'] ?? null;
        $actions = is_string($actionName) && isset($definition['actions'][$actionName])
            ? [$definition['actions'][$actionName]]
            : $definition['actions'];

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

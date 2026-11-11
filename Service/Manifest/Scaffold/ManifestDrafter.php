<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Manifest\Scaffold;

use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use MagoAssistant\Mago\Model\Manifest\Config\Reader;
use MagoAssistant\Mago\Service\Tool\Scaffold\InvalidScaffoldException;

/**
 * A first etc/mago_tools.xml for a module, drafted from the module's own webapi.xml and acl.xml:
 * one tool per top-level REST resource, one action per route, the path parameters as parameters,
 * and the body fields read from the service contract when its interface is loadable. Everything
 * the author still has to decide is marked TODO; the draft validates against the schema as is.
 *
 * @phpstan-type RouteDef array{method: string, path: string, class: string, serviceMethod: string, acl: list<string>}
 */
class ManifestDrafter
{
    private const WEBAPI_FILE = 'webapi.xml';
    private const PATH_PREFIX = '/V1/';
    private const DEFAULT_PAGE_SIZE = '20';
    private const MODULE_PATTERN = '/^[A-Z][A-Za-z0-9]*_[A-Z][A-Za-z0-9]*$/';
    private const TYPE_MAP = ['int' => 'integer', 'string' => 'string', 'bool' => 'boolean', 'float' => 'number'];
    private const SERVER_MANAGED = ['created_at', 'updated_at'];

    public function __construct(
        private readonly ModuleDirReader $moduleDirReader,
        private readonly File $fileDriver
    ) {
    }

    /**
     * @throws InvalidScaffoldException
     */
    public function draft(string $module): string
    {
        if (preg_match(self::MODULE_PATTERN, $module) !== 1) {
            throw new InvalidScaffoldException('Module must be Vendor_Module, e.g. MageOS_RMA.');
        }
        $routes = $this->routes($module);
        if ($routes === []) {
            throw new InvalidScaffoldException(
                $module . ' declares no REST routes in etc/webapi.xml; a manifest needs routes to call.'
            );
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $document->appendChild($document->createComment(
            "\n" . 'Mago tools of ' . $module . ', drafted by bin/magento mago:tool:manifest from etc/webapi.xml.'
            . "\n"
            . 'Resolve every TODO, then run bin/magento mago:tool:verify <tool> \'{"action": "..."}\'.' . "\n"
            . 'Reference: vendor/mago-assistant/magento2-mago/docs/manifest-tools.md' . "\n"
        ));
        $config = $document->createElement('config');
        $config->setAttribute('version', Reader::SCHEMA_VERSION);
        $document->appendChild($config);

        foreach ($this->groupByResource($routes) as $resource => $resourceRoutes) {
            $config->appendChild($this->tool($document, $module, $resource, $resourceRoutes));
        }

        return (string)$document->saveXML();
    }

    /**
     * @return list<RouteDef>
     * @throws InvalidScaffoldException
     */
    private function routes(string $module): array
    {
        try {
            $etc = $this->moduleDirReader->getModuleDir(Dir::MODULE_ETC_DIR, $module);
        } catch (\InvalidArgumentException) {
            throw new InvalidScaffoldException(
                'Module ' . $module . ' is not registered; check bin/magento module:status.'
            );
        }
        $file = $etc . '/' . self::WEBAPI_FILE;
        if (!$this->fileDriver->isFile($file)) {
            return [];
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->load($file);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new InvalidScaffoldException($file . ' is not well-formed XML.');
        }

        $routes = [];
        foreach ($document->getElementsByTagName('route') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $path = $node->getAttribute('url');
            if (!str_starts_with($path, self::PATH_PREFIX)) {
                continue;
            }
            $service = $node->getElementsByTagName('service')->item(0);
            $acl = [];
            foreach ($node->getElementsByTagName('resource') as $resource) {
                if ($resource instanceof \DOMElement && $resource->getAttribute('ref') !== '') {
                    $acl[] = $resource->getAttribute('ref');
                }
            }
            $routes[] = [
                'method' => strtoupper($node->getAttribute('method')),
                'path' => substr($path, strlen(self::PATH_PREFIX)),
                'class' => $service instanceof \DOMElement ? ltrim($service->getAttribute('class'), '\\') : '',
                'serviceMethod' => $service instanceof \DOMElement ? $service->getAttribute('method') : '',
                'acl' => $acl,
            ];
        }

        return $routes;
    }

    /**
     * @param list<RouteDef> $routes
     * @param list<RouteDef> $routes
     * @return array<string, list<RouteDef>>
     */
    private function groupByResource(array $routes): array
    {
        $groups = [];
        foreach ($routes as $route) {
            $segments = explode('/', trim($route['path'], '/'));
            $groups[$segments[0]][] = $route;
        }

        return $groups;
    }

    /**
     * @param list<RouteDef> $routes
     */
    private function tool(\DOMDocument $document, string $module, string $resource, array $routes): \DOMElement
    {
        $toolAcl = $this->mostCommonAcl($routes);
        $tool = $document->createElement('tool');
        $tool->setAttribute('name', $this->identifier($resource));
        $tool->setAttribute('acl', $toolAcl);
        $tool->setAttribute('module', $module);
        $tool->setAttribute('title', ucfirst(str_replace(['-', '_'], ' ', $resource)));
        $tool->setAttribute(
            'description',
            'TODO: what the model can do with ' . $resource . ', in one or two sentences.'
        );
        $tool->appendChild($document->createElement(
            'instructions',
            'TODO: how to present results, or remove this element.'
        ));

        $names = [];
        foreach ($routes as $route) {
            $tool->appendChild($this->action($document, $route, $toolAcl, $names));
        }

        return $tool;
    }

    /**
     * @param RouteDef $route
     * @param array<string, true> $names
     */
    private function action(\DOMDocument $document, array $route, string $toolAcl, array &$names): \DOMElement
    {
        $segments = explode('/', trim($route['path'], '/'));
        $pathParams = [];
        $words = [];
        foreach (array_slice($segments, 1) as $segment) {
            if (str_starts_with($segment, ':')) {
                $pathParams[] = substr($segment, 1);
            } elseif ($segment !== 'search') {
                $words[] = $segment;
            }
        }
        $isList = $route['method'] === 'GET' && $pathParams === []
            && (str_ends_with($route['path'], '/search')
                || $route['serviceMethod'] === 'getList'
                || $segments === [$segments[0]]);
        $verb = match ($route['method']) {
            'GET' => $isList ? 'list' : 'get',
            'POST' => 'create',
            'PUT' => 'update',
            default => 'delete',
        };
        $name = $this->uniqueName($this->identifier(implode('_', [$verb, ...$words])), $names);
        $subject = $words === [] ? $segments[0] : implode(' ', $words);

        $action = $document->createElement('action');
        $action->setAttribute('name', $name);
        $action->setAttribute('description', 'TODO: ' . $this->describe($verb, $subject));
        $action->setAttribute('method', $route['method']);
        $action->setAttribute('path', preg_replace_callback(
            '/:([A-Za-z0-9_]+)/',
            fn (array $m): string => '{' . $this->snake($m[1]) . '}',
            $route['path']
        ) ?? $route['path']);
        if ($route['method'] === 'GET') {
            $action->setAttribute('readonly', 'true');
        }
        if ($route['method'] === 'DELETE') {
            $action->setAttribute('irreversible', 'true');
            $action->setAttribute(
                'impact',
                'TODO: what is gone once this ran, e.g. "The ' . $subject . ' is deleted."'
            );
        }
        $actionAcl = $route['acl'][0] ?? $toolAcl;
        if ($actionAcl !== $toolAcl) {
            $action->setAttribute('acl', $actionAcl);
        }

        foreach ($pathParams as $param) {
            $action->appendChild($this->param($document, $this->snake($param), $this->idType($param), 'path', true));
        }
        if ($isList) {
            $query = $this->param($document, 'query', 'string', 'criteria', false);
            $query->setAttribute('description', 'TODO: what the text is matched against');
            $query->setAttribute('criteria', 'TODO_field');
            $query->setAttribute('condition', 'like');
            $query->setAttribute('wrap', '%');
            $action->appendChild($query);
            $limit = $this->param($document, 'limit', 'integer', 'page_size', false);
            $limit->setAttribute('default', self::DEFAULT_PAGE_SIZE);
            $action->appendChild($limit);
        }
        if ($route['method'] === 'POST' || $route['method'] === 'PUT') {
            $this->bodyParams($document, $action, $route, $pathParams);
        }
        $this->privacy($document, $action, $route);

        return $action;
    }

    /**
     * The body of a write: the service method's scalar arguments as parameters, and for an argument
     * typed with a data interface the wrapper key plus that interface's scalar getters as parameters.
     * A path parameter is already in the body (the tool puts it there), and timestamps are the server's.
     *
     * @param RouteDef $route
     * @param list<string> $pathParams
     */
    private function bodyParams(\DOMDocument $document, \DOMElement $action, array $route, array $pathParams): void
    {
        $method = $this->reflect($route['class'], $route['serviceMethod']);
        if ($method === null) {
            $action->appendChild($document->createComment(
                ' TODO: ' . $route['class'] . '::' . $route['serviceMethod'] . '() could not be loaded; '
                . 'declare the body parameters by hand, with body="<argument name>" on the action when the '
                . 'route takes an entity '
            ));
            return;
        }

        $pathSnake = array_map($this->snake(...), $pathParams);
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if ($typeName !== null && isset(self::TYPE_MAP[$typeName])) {
                $name = $this->snake($parameter->getName());
                if (!in_array($name, $pathSnake, true)) {
                    $action->appendChild($this->param(
                        $document,
                        $name,
                        self::TYPE_MAP[$typeName],
                        'body',
                        !$parameter->isOptional() && !$parameter->allowsNull()
                    ));
                }
                continue;
            }
            if ($typeName !== null && (interface_exists($typeName) || class_exists($typeName))) {
                $action->setAttribute('body', $parameter->getName());
                foreach ($this->getters(new \ReflectionClass($typeName)) as $field => $fieldType) {
                    if (in_array($field, $pathSnake, true) || in_array($field, self::SERVER_MANAGED, true)) {
                        continue;
                    }
                    $action->appendChild($this->param($document, $field, $fieldType, 'body', false));
                }
            }
        }
    }

    /**
     * Ids are public and needed for links; every other field waits for the author's decision.
     *
     * @param RouteDef $route
     */
    private function privacy(\DOMDocument $document, \DOMElement $action, array $route): void
    {
        $fields = [];
        $method = $this->reflect($route['class'], $route['serviceMethod']);
        $returnType = $method?->getReturnType();
        if ($returnType instanceof \ReflectionNamedType && !$returnType->isBuiltin()
            && (interface_exists($returnType->getName()) || class_exists($returnType->getName()))) {
            $fields = array_keys($this->getters(new \ReflectionClass($returnType->getName())));
        }

        $ids = array_values(array_filter($fields, static fn (string $f): bool => str_ends_with($f, '_id')));
        foreach ($ids as $id) {
            $field = $document->createElement('field');
            $field->setAttribute('name', $id);
            $field->setAttribute('class', 'public');
            $action->appendChild($field);
        }
        $rest = array_values(array_diff($fields, $ids));
        $action->appendChild($document->createComment(
            $rest === []
                ? ' TODO: declare every returned field the model may see as '
                    . '<field name="..." class="public|tokenise|strip"/> '
                : ' TODO: classify ' . implode(', ', $rest) . ' as public, tokenise (personal data) or strip '
        ));
        $all = $document->createElement('fields');
        $all->setAttribute('class', 'strip');
        $action->appendChild($all);
    }

    private function param(\DOMDocument $document, string $name, string $type, string $in, bool $required): \DOMElement
    {
        $param = $document->createElement('param');
        $param->setAttribute('name', $name);
        $param->setAttribute('type', $type);
        if ($in !== 'body') {
            $param->setAttribute('in', $in);
        }
        if ($required) {
            $param->setAttribute('required', 'true');
        }

        return $param;
    }

    /**
     * Scalar getters of a data interface as field name => manifest type, in declaration order.
     *
     * @param \ReflectionClass<object> $class
     * @return array<string, string>
     */
    private function getters(\ReflectionClass $class): array
    {
        $fields = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (!str_starts_with($name, 'get') || $method->getNumberOfRequiredParameters() > 0 || $name === 'get') {
                continue;
            }
            $type = $method->getReturnType();
            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }
            $manifestType = self::TYPE_MAP[$type->getName()] ?? ($type->getName() === 'array' ? 'array' : null);
            if ($manifestType !== null) {
                $fields[$this->snake(substr($name, 3))] = $manifestType;
            }
        }

        return $fields;
    }

    private function reflect(string $class, string $method): ?\ReflectionMethod
    {
        if ($class === '' || $method === '' || !(interface_exists($class) || class_exists($class))) {
            return null;
        }
        try {
            return new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return null;
        }
    }

    /**
     * @param list<RouteDef> $routes
     */
    private function mostCommonAcl(array $routes): string
    {
        $counts = [];
        foreach ($routes as $route) {
            foreach ($route['acl'] as $acl) {
                $counts[$acl] = ($counts[$acl] ?? 0) + 1;
            }
        }
        if ($counts === []) {
            return 'TODO_Vendor_Module::resource';
        }
        arsort($counts);

        return (string)array_key_first($counts);
    }

    /**
     * @param array<string, true> $names
     */
    private function uniqueName(string $name, array &$names): string
    {
        $candidate = $name;
        for ($i = 2; isset($names[$candidate]); $i++) {
            $candidate = $name . '_' . $i;
        }
        $names[$candidate] = true;

        return $candidate;
    }

    private function describe(string $verb, string $subject): string
    {
        return match ($verb) {
            'list' => 'Find ' . $subject . ' by text',
            'get' => 'Get one of the ' . $subject . ' by id',
            'create' => 'Create one of the ' . $subject,
            'update' => 'Update one of the ' . $subject,
            default => 'Delete one of the ' . $subject,
        };
    }

    private function idType(string $param): string
    {
        return preg_match('/(^|_)id$|Id$/', $param) === 1 ? 'integer' : 'string';
    }

    private function identifier(string $text): string
    {
        $identifier = strtolower((string)preg_replace('/[^a-z0-9_]+/i', '_', $this->snake($text)));
        $identifier = trim((string)preg_replace('/_+/', '_', $identifier), '_');
        if ($identifier === '' || !ctype_alpha($identifier[0])) {
            $identifier = 'x_' . $identifier;
        }

        return substr($identifier, 0, 64);
    }

    private function snake(string $camel): string
    {
        return strtolower((string)preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $camel));
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Manifest\Config;

use Magento\Framework\Config\ConverterInterface;

/**
 * The merged mago_tools.xml DOM as plain arrays, one per tool, keyed by tool name. Everything the
 * manifest tool needs at runtime is in here; the XML is not touched again.
 *
 * @phpstan-type ParamDef array{
 *     name: string, type: string, description: string, required: bool, in: string, criteria: ?string,
 *     condition: ?string, wrap: ?string, items: ?string, enum: list<string>, default: ?string
 * }
 * @phpstan-type StepFieldDef array{name: string, value: string, as: string}
 * @phpstan-type StepDef array{
 *     name: string, method: string, path: string, body: ?string, when: ?string, sort: ?string,
 *     fields: list<StepFieldDef>
 * }
 * @phpstan-type LinkDef array{label: string, route: string, param: string, from: string}
 * @phpstan-type ActionDef array{
 *     name: string, description: string, instructions: string, readonly: bool, irreversible: bool,
 *     impact: ?string, status: ?string, acl: ?string, result: ?string, params: array<string, ParamDef>,
 *     steps: list<StepDef>, fields: array<string, array{0: string, 1?: string}>, pick: list<string>,
 *     links: list<LinkDef>
 * }
 * @phpstan-type ToolDef array{
 *     name: string, acl: string, module: string, title: string, description: string, instructions: string,
 *     actions: array<string, ActionDef>
 * }
 */
class Converter implements ConverterInterface
{
    /**
     * @param \DOMDocument $source
     * @return array<string, ToolDef>
     */
    public function convert($source): array
    {
        $tools = [];
        $root = $source->documentElement;
        if ($root === null) {
            return [];
        }
        foreach ($this->children($root, 'tool') as $toolNode) {
            $tool = $this->tool($toolNode);
            $tools[$tool['name']] = $tool;
        }

        return $tools;
    }

    /**
     * @return ToolDef
     */
    private function tool(\DOMElement $node): array
    {
        $actions = [];
        foreach ($this->children($node, 'action') as $actionNode) {
            $action = $this->action($actionNode);
            $actions[$action['name']] = $action;
        }

        return [
            'name' => $node->getAttribute('name'),
            'acl' => $node->getAttribute('acl'),
            'module' => $node->getAttribute('module'),
            'title' => $node->getAttribute('title'),
            'description' => $node->getAttribute('description'),
            'instructions' => $this->text($node, 'instructions'),
            'actions' => $actions,
        ];
    }

    /**
     * @return ActionDef
     */
    private function action(\DOMElement $node): array
    {
        $params = [];
        foreach ($this->children($node, 'param') as $paramNode) {
            $param = $this->param($paramNode);
            $params[$param['name']] = $param;
        }

        $steps = [];
        foreach ($this->children($node, 'step') as $stepNode) {
            $steps[] = $this->step($stepNode);
        }
        // An action without steps is its own single step.
        if ($steps === [] && $node->hasAttribute('method') && $node->hasAttribute('path')) {
            $steps[] = [
                'name' => 'main',
                'method' => $node->getAttribute('method'),
                'path' => $node->getAttribute('path'),
                'body' => $this->optional($node, 'body'),
                'when' => null,
                'sort' => $this->optional($node, 'sort'),
                'fields' => [],
            ];
        }

        $fields = [];
        foreach ($this->children($node, 'field') as $fieldNode) {
            $fields[$fieldNode->getAttribute('name')] = $this->privacyRule($fieldNode);
        }
        foreach ($this->children($node, 'fields') as $fieldsNode) {
            $fields['*'] = $this->privacyRule($fieldsNode);
        }

        $pick = [];
        foreach ($this->children($node, 'pick') as $pickNode) {
            $pick[] = $pickNode->getAttribute('field');
        }

        $links = [];
        foreach ($this->children($node, 'link') as $linkNode) {
            $links[] = [
                'label' => $linkNode->getAttribute('label'),
                'route' => $linkNode->getAttribute('route'),
                'param' => $linkNode->getAttribute('param'),
                'from' => $linkNode->getAttribute('from'),
            ];
        }

        return [
            'name' => $node->getAttribute('name'),
            'description' => $node->getAttribute('description'),
            'instructions' => $this->text($node, 'instructions'),
            'readonly' => $this->flag($node, 'readonly'),
            'irreversible' => $this->flag($node, 'irreversible'),
            'impact' => $this->optional($node, 'impact'),
            'status' => $this->optional($node, 'status'),
            'acl' => $this->optional($node, 'acl'),
            'result' => $this->optional($node, 'result'),
            'params' => $params,
            'steps' => $steps,
            'fields' => $fields,
            'pick' => $pick,
            'links' => $links,
        ];
    }

    /**
     * @return ParamDef
     */
    private function param(\DOMElement $node): array
    {
        $enum = $this->optional($node, 'enum');

        return [
            'name' => $node->getAttribute('name'),
            'type' => $node->getAttribute('type'),
            'description' => $node->getAttribute('description'),
            'required' => $this->flag($node, 'required'),
            'in' => $node->hasAttribute('in') ? $node->getAttribute('in') : 'body',
            'criteria' => $this->optional($node, 'criteria'),
            'condition' => $this->optional($node, 'condition'),
            'wrap' => $this->optional($node, 'wrap'),
            'items' => $this->optional($node, 'items'),
            'enum' => $enum === null ? [] : array_values(array_filter(array_map('trim', explode(',', $enum)))),
            'default' => $this->optional($node, 'default'),
        ];
    }

    /**
     * @return StepDef
     */
    private function step(\DOMElement $node): array
    {
        $fields = [];
        foreach ($this->children($node, 'field') as $fieldNode) {
            $fields[] = [
                'name' => $fieldNode->getAttribute('name'),
                'value' => $fieldNode->getAttribute('value'),
                'as' => $fieldNode->hasAttribute('as') ? $fieldNode->getAttribute('as') : 'raw',
            ];
        }

        return [
            'name' => $node->getAttribute('name'),
            'method' => $node->getAttribute('method'),
            'path' => $node->getAttribute('path'),
            'body' => $this->optional($node, 'body'),
            'when' => $this->optional($node, 'when'),
            'sort' => $this->optional($node, 'sort'),
            'fields' => $fields,
        ];
    }

    /**
     * @return array{0: string, 1?: string}
     */
    private function privacyRule(\DOMElement $node): array
    {
        $rule = [$node->getAttribute('class')];
        if ($node->hasAttribute('token')) {
            $rule[] = $node->getAttribute('token');
        }

        return $rule;
    }

    /**
     * @return list<\DOMElement>
     */
    private function children(\DOMElement $node, string $name): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->nodeName === $name) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function text(\DOMElement $node, string $name): string
    {
        foreach ($this->children($node, $name) as $child) {
            return trim($child->textContent);
        }

        return '';
    }

    private function optional(\DOMElement $node, string $attribute): ?string
    {
        return $node->hasAttribute($attribute) ? $node->getAttribute($attribute) : null;
    }

    private function flag(\DOMElement $node, string $attribute): bool
    {
        return in_array(strtolower($node->getAttribute($attribute)), ['1', 'true'], true);
    }
}

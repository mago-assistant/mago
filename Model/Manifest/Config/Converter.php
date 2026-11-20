<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Manifest\Config;

use Magento\Framework\Config\ConverterInterface;

/**
 * One module's mago_tools.xml DOM as plain arrays, one per tool, keyed by tool name. Everything the
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
 *     name: string, description: string, instructions: string, readonly: bool, readonly_declared: bool,
 *     irreversible: bool, impact: ?string, status: ?string, acl: ?string, result: ?string,
 *     params: array<string, ParamDef>,
 *     steps: list<StepDef>, fields: array<string, array{0: string, 1?: string}>, pick: list<string>,
 *     links: list<LinkDef>
 * }
 * @phpstan-type ToolDef array{
 *     name: string, acl: string, module: string, title: string, description: string, instructions: string,
 *     schema_version: string, actions: array<string, ActionDef>
 * }
 */
class Converter implements ConverterInterface
{
    /**
     * The name of the step an action's own method and path make up, as in {steps.main}.
     */
    public const OWN_STEP = 'main';

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
        $version = $root->getAttribute('version');
        foreach ($this->children($root, 'tool') as $toolNode) {
            $tool = $this->tool($toolNode, $version);
            $tools[$tool['name']] = $tool;
        }

        return $tools;
    }

    /**
     * @return ToolDef
     */
    private function tool(\DOMElement $node, string $version): array
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
            // The format version the declaring file is written in (config@version)
            'schema_version' => $version,
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
            $step = $this->step($stepNode);
            // What the action says about the body wrapper and the order holds for its steps too
            $step['body'] ??= $this->optional($node, 'body');
            $step['sort'] ??= $this->optional($node, 'sort');
            $steps[] = $step;
        }
        // The action's own method and path are its first step; <step> elements follow it.
        if ($node->hasAttribute('method') && $node->hasAttribute('path')) {
            if (in_array(self::OWN_STEP, array_column($steps, 'name'), true)) {
                throw new \InvalidArgumentException(
                    'Action "' . $node->getAttribute('name') . '" has a method and path of its own, which is its '
                    . 'step "' . self::OWN_STEP . '"; a <step> cannot have that name as well.'
                );
            }
            array_unshift($steps, [
                'name' => self::OWN_STEP,
                'method' => $node->getAttribute('method'),
                'path' => $node->getAttribute('path'),
                'body' => $this->optional($node, 'body'),
                'when' => null,
                'sort' => $this->optional($node, 'sort'),
                'fields' => [],
            ]);
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
            // A step that is not a GET writes, whatever the manifest says: a write marked readonly
            // would skip the confirmation and run on a read grant. The declared value is kept so
            // mago:tool:verify can report the mismatch.
            'readonly' => $this->flag($node, 'readonly') && $this->onlyReads($steps),
            'readonly_declared' => $this->flag($node, 'readonly'),
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
     * @param list<StepDef> $steps
     */
    private function onlyReads(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step['method'] !== 'GET') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return ParamDef
     */
    private function param(\DOMElement $node): array
    {
        $enum = $this->optional($node, 'enum');
        $in = $node->hasAttribute('in') ? $node->getAttribute('in') : 'body';

        return [
            'name' => $node->getAttribute('name'),
            'type' => $node->getAttribute('type'),
            'description' => $node->getAttribute('description'),
            'required' => $this->flag($node, 'required'),
            'in' => $in,
            // A criteria parameter filters on the field of its own name unless it names another
            'criteria' => $this->optional($node, 'criteria')
                ?? ($in === 'criteria' ? $node->getAttribute('name') : null),
            'condition' => $this->optional($node, 'condition'),
            'wrap' => $this->optional($node, 'wrap'),
            'items' => $this->optional($node, 'items'),
            'enum' => $enum === null ? [] : $this->listOf($enum),
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

    /**
     * As xs:boolean reads it: surrounding whitespace does not count.
     */
    private function flag(\DOMElement $node, string $attribute): bool
    {
        return in_array(strtolower(trim($node->getAttribute($attribute))), ['1', 'true'], true);
    }

    /**
     * A comma-separated attribute as a list; "0" is a value, an empty entry is not.
     *
     * @return list<string>
     */
    private function listOf(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $entry): bool => $entry !== ''
        ));
    }
}

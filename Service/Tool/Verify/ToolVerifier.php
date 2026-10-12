<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Tool\Verify;

use MagoAssistant\Mago\Api\Acl;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Acl\ToolAccess;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;

/**
 * The checks behind mago:tool:verify, for authors of tools outside this module. inspect() only
 * reads the tool's declarations; run() calls execute(), so the caller decides whether a writing
 * call may run against the store.
 */
class ToolVerifier
{
    /**
     * Provider tool-name rules (Anthropic, OpenAI, Gemini) intersected, in the snake_case the
     * registry uses.
     */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    private const VALID_CLASSES = [PiiClass::PUBLIC, PiiClass::TOKENISE, PiiClass::STRIP];

    public function __construct(
        private readonly PrivacyFilter $privacyFilter,
        private readonly AclResourceIndex $aclResources,
        private readonly ToolAccess $toolAccess
    ) {
    }

    /**
     * @param array<string,mixed> $params
     * @return ToolCheck[]
     */
    public function inspect(ToolInterface $tool, array $params): array
    {
        return [
            $this->checkName($tool),
            $this->checkDescription($tool),
            $this->checkSchema($tool),
            ...$this->checkAcls($tool, [], 'without input'),
            ...($params === [] ? $this->checkInputNeeded($tool) : $this->checkAcls($tool, $params, 'for this call')),
            $this->checkAccess($tool, $params),
            ...$this->checkClassification($tool->getFieldClassification($this->actionOf($params))),
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    public function run(ToolInterface $tool, array $params): ToolRun
    {
        $result = $tool->execute($params);
        $classes = $tool->getFieldClassification($this->actionOf($params));
        $modelView = $this->privacyFilter->filter($classes, $result);

        return new ToolRun(
            $result,
            $modelView,
            [
                $this->checkUndeclaredFields($this->privacyFilter->findUndeclaredPaths($classes, $result)),
                ...(isset($modelView['error']) ? [$this->reportError($modelView['error'])] : []),
            ]
        );
    }

    private function checkName(ToolInterface $tool): ToolCheck
    {
        return preg_match(self::NAME_PATTERN, $tool->getName()) === 1
            ? ToolCheck::pass('Name "' . $tool->getName() . '" is valid for every provider')
            : ToolCheck::failure(
                'Name "' . $tool->getName() . '" must be snake_case, start with a letter and be at most 64 characters'
            );
    }

    private function checkDescription(ToolInterface $tool): ToolCheck
    {
        return trim($tool->getDescription()) === ''
            ? ToolCheck::failure('Description is empty: the model picks tools by their description')
            : ToolCheck::pass('Description is set');
    }

    private function checkSchema(ToolInterface $tool): ToolCheck
    {
        return ($tool->getParameterSchema()['type'] ?? null) === 'object'
            ? ToolCheck::pass('Parameter schema is an object schema')
            : ToolCheck::failure('Parameter schema must have "type": "object"');
    }

    /**
     * One check per resource the call is gated by (the tool's, and the action's when it narrows).
     *
     * @param array<string,mixed> $params
     * @return ToolCheck[]
     */
    private function checkAcls(ToolInterface $tool, array $params, string $context): array
    {
        return array_map(
            fn (string $resource): ToolCheck => $this->checkAcl($resource, $context),
            $this->toolAccess->resourcesFor($tool, $params)
        );
    }

    /**
     * A tool whose calls need parameters may also resolve its resource from them (config_reader
     * and config_writer take it from the path's section), which a check without input cannot see
     *
     * @return ToolCheck[]
     */
    private function checkInputNeeded(ToolInterface $tool): array
    {
        return ($tool->getParameterSchema()['required'] ?? []) === []
            ? []
            : [ToolCheck::pass(
                'ACL checked without input only; pass the call parameters as JSON to check the resource '
                . 'a real call needs'
            )];
    }

    private function checkAcl(string $resource, string $context): ToolCheck
    {
        if ($resource === '') {
            return ToolCheck::failure(
                'No ACL resource ' . $context . ': the call is refused for everyone. Declare the Magento '
                . 'resource guarding this data, or Acl::MAGO_PER_USER for a tool that touches none'
            );
        }

        if ($resource === Acl::MAGO_PER_USER) {
            return ToolCheck::pass(
                'Gated per user ' . $context . ': only an explicit grant under Stores > Admin Assistant > Skills & Permissions allows it'
            );
        }

        return $this->aclResources->has($resource)
            ? ToolCheck::pass('ACL resource ' . $context . ': ' . $resource)
            : ToolCheck::failure(
                'ACL resource ' . $context . ' "' . $resource . '" is not declared in any acl.xml,'
                . ' so every restricted admin is denied'
            );
    }

    /**
     * @param array<string,mixed> $params
     */
    private function checkAccess(ToolInterface $tool, array $params): ToolCheck
    {
        $isReadCall = $tool->isReadOnlyAction($params);

        if ($tool->isReadOnly() && !$isReadCall) {
            return ToolCheck::failure('isReadOnly() is true but isReadOnlyAction() says this call writes');
        }

        return $isReadCall
            ? ToolCheck::pass('This call reads: it runs without confirmation')
            : ToolCheck::pass('This call writes: the admin confirms it in the panel');
    }

    /**
     * @param array<array-key,mixed> $classes
     * @return ToolCheck[]
     */
    private function checkClassification(array $classes): array
    {
        if ($classes === []) {
            return [ToolCheck::warning('Field classification is empty: the model only ever sees "error"')];
        }

        $problems = array_filter(array_map($this->findRuleProblem(...), array_keys($classes), $classes));

        return $problems === []
            ? [ToolCheck::pass('Field classification has ' . count($classes) . ' valid rule(s)')]
            : array_values(array_map(ToolCheck::failure(...), $problems));
    }

    private function findRuleProblem(int|string $field, mixed $rule): ?string
    {
        if (!is_string($field)) {
            return 'Field classification uses a numeric key; name the field instead';
        }

        $class = is_array($rule) ? ($rule[0] ?? null) : null;
        if (!in_array($class, self::VALID_CLASSES, true)) {
            return 'Field "' . $field . '" needs a PiiClass rule such as [PiiClass::PUBLIC]';
        }

        if ($class === PiiClass::TOKENISE && (string)($rule[1] ?? '') === '') {
            return 'Field "' . $field . '" tokenises without a token type, e.g. [PiiClass::TOKENISE, \'order\']';
        }

        return null;
    }

    /**
     * @param string[] $undeclared
     */
    private function checkUndeclaredFields(array $undeclared): ToolCheck
    {
        return $undeclared === []
            ? ToolCheck::pass('Every returned field is classified')
            : ToolCheck::failure(
                'Undeclared fields, stripped before the model sees them: ' . implode(', ', $undeclared)
            );
    }

    private function reportError(mixed $error): ToolCheck
    {
        return ToolCheck::warning('The tool returned an error: ' . (is_scalar($error) ? (string)$error : 'non-scalar'));
    }

    /**
     * @param array<string,mixed> $params
     */
    private function actionOf(array $params): string
    {
        return is_string($params['action'] ?? null) ? $params['action'] : '';
    }
}

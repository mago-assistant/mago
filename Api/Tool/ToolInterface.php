<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Tool;

/**
 * Tool interface — each tool the AI can invoke
 * @api
 */
interface ToolInterface
{
    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @return string
     */
    public function getDescription(): string;

    /**
     * JSON Schema for tool parameters
     *
     * @return array
     */
    public function getParameterSchema(): array;

    /**
     * Execute the tool with given parameters
     *
     * @param array $params
     * @return array
     */
    public function execute(array $params): array;

    /**
     * Whether this tool only reads data (no side effects)
     *
     * @return bool
     */
    public function isReadOnly(): bool;

    /**
     * Detailed instructions injected only when this tool is called (JIT).
     * Return empty string if no extra instructions needed.
     *
     * @return string
     */
    public function getInstructions(): string;

    /**
     * Whether a specific invocation is read-only based on input parameters.
     * For tools with mixed read/write sub-actions (e.g. cms_data),
     * this checks the actual action being called.
     *
     * @param array $input The tool call input parameters
     * @return bool
     */
    public function isReadOnlyAction(array $input): bool;

    /**
     * How the fields of one invocation's result cross to the LLM under privacy mode (issue #97).
     * Action-scoped tools answer for the named action; a flat tool ignores $action. Same shape and
     * semantics as ActionInterface::getFieldClassification(): undeclared is never public.
     *
     * @param string $action
     * @return array<string,array{0:string,1?:string}>
     */
    public function getFieldClassification(string $action = ''): array;

    /**
     * Native Magento ACL resource required for a specific invocation.
     * Mixed tools return the resource matching the action in $input; an empty
     * $input (or an unknown action) must resolve to the most restrictive
     * resource the tool uses (fail closed). Return empty string if no
     * Magento ACL check is needed beyond the assistant skill permissions.
     *
     * Examples: 'Magento_Backend::cache', 'Magento_Indexer::changeMode'
     *
     * @param array $input The tool call input parameters
     * @return string
     */
    public function getMagentoAcl(array $input = []): string;
}

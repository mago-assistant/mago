<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Tool;

/**
 * Tool whose AI-facing definition can be narrowed to a subset of its actions.
 *
 * The registry uses this for admins holding only a read grant on a mixed
 * read/write tool: the description and parameter schema sent to the provider
 * then only mention the actions that user may invoke, so the model does not
 * attempt (and burn an iteration on) a denied write action.
 *
 * @api
 */
interface ActionScopedToolInterface extends ToolInterface
{
    /**
     * Description limited to the given action names
     *
     * @param string[] $actionNames
     * @return string
     */
    public function getDescriptionForActions(array $actionNames): string;

    /**
     * Parameter schema limited to the given action names: the action enum and
     * only the parameters those actions declare. Called with the one action of a
     * tool call, it is also the allowlist for execute(): keys outside it are
     * dropped before the call runs.
     *
     * @param string[] $actionNames
     * @return array
     */
    public function getParameterSchemaForActions(array $actionNames): array;
}

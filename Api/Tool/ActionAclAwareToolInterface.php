<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Tool;

/**
 * A tool whose calls name an action, where the action may declare an ACL resource of its own that
 * is narrower than the tool's. ToolAccess asks this first and falls back to getMagentoAcl(), so a
 * skill can gate one action on a resource the others do not need.
 *
 * @api
 */
interface ActionAclAwareToolInterface extends ToolInterface
{
    /**
     * The resource the action named in $input declares, or null when it declares none or no
     * action is named - in which case the tool's own getMagentoAcl() applies.
     *
     * @param array<string, mixed> $input
     */
    public function getActionAcl(array $input): ?string;
}

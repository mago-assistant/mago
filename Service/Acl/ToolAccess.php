<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Acl;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Api\Acl;
use MagoAssistant\Mago\Api\Tool\ActionAclAwareToolInterface;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Skills\PermissionChecker;

/**
 * The one place that decides whether an admin may make a tool call under the ACL resource the
 * call declares. ChatService asks before running a tool, ExampleQuestions before offering one, and
 * mago:tool:verify reports the same answer, so a tool is gated the same way wherever it is reached.
 *
 * A call declares one of three things, and each is answered differently:
 *
 * - a Magento resource id: the admin must hold it, exactly as the admin screen for that data
 *   requires;
 * - Acl::MAGO_PER_USER: the tool touches no Magento data and is gated by the assistant's own
 *   per-user skill permission alone - and only by an explicit grant there, since holding the
 *   assistant at all says nothing about having been given this tool;
 * - nothing: the tool forgot to say. That is refused for everyone. An empty resource used to mean
 *   "no check", which is how a tool reading customer records came to be reachable by any admin
 *   with the chat grant (#148); making the empty case the closed one means forgetting can never
 *   open data again.
 *
 * A tool with actions is asked twice: for the tool's own resource and for the named action's, and
 * both must allow the call. An action can therefore only narrow what its tool requires, never
 * replace it with something broader - top_spenders asking for Magento_Sales::sales does not lift
 * the customer register's Magento_Customer::manage that the rest of customer_data needs.
 */
class ToolAccess
{
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly PermissionChecker $permissionChecker
    ) {
    }

    /**
     * Every resource this call is gated by: the tool's own, then the named action's when it
     * declares one of its own. All of them must allow the call.
     *
     * @param array<string, mixed> $input
     * @return string[]
     */
    public function resourcesFor(ToolInterface $tool, array $input): array
    {
        $resources = [$tool->getMagentoAcl($input)];

        $actionAcl = $tool instanceof ActionAclAwareToolInterface ? $tool->getActionAcl($input) : null;
        if ($actionAcl !== null && $actionAcl !== '' && $actionAcl !== $resources[0]) {
            $resources[] = $actionAcl;
        }

        return $resources;
    }

    /**
     * Why the admin may not make this call, or null when they may.
     *
     * @param array<string, mixed> $input
     */
    public function denialReason(ToolInterface $tool, array $input, ?int $adminUserId): ?string
    {
        foreach ($this->resourcesFor($tool, $input) as $resource) {
            $reason = $this->resourceDenial($tool, $input, $adminUserId, $resource);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function resourceDenial(ToolInterface $tool, array $input, ?int $adminUserId, string $resource): ?string
    {
        if ($resource === '') {
            return sprintf(
                'Access denied: the %s tool declares no ACL resource for this call, so it cannot be used',
                $tool->getName()
            );
        }

        if ($resource === Acl::MAGO_PER_USER) {
            $permission = $tool->isReadOnlyAction($input) ? 'read' : 'write';

            return $this->permissionChecker->isExplicitlyAllowed((int)$adminUserId, $tool->getName(), $permission)
                ? null
                : sprintf(
                    'Access denied: the %s tool is granted per user, and you have not been given it',
                    $tool->getName()
                );
        }

        return $this->authorization->isAllowed($resource)
            ? null
            : sprintf(
                'Access denied: you do not have the required Magento permission (%s) to use the %s tool',
                $resource,
                $tool->getName()
            );
    }
}

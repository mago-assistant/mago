<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Skill;

/**
 * A write action that is only irreversible for some invocations.
 *
 * A comment that also e-mails the customer is one: the comment can be deleted, the e-mail cannot be
 * recalled. The "cannot be undone" card is shown only for the invocations this reports as
 * irreversible; the others get the plain Allow button of a reversible write.
 *
 * @api
 */
interface ConditionallyIrreversibleActionInterface extends IrreversibleActionInterface
{
    /**
     * Whether this invocation cannot be undone once it ran
     *
     * @param array $params Tool call parameters, as the action will receive them
     * @return bool
     */
    public function isIrreversible(array $params): bool;
}

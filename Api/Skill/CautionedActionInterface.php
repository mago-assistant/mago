<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Skill;

/**
 * A write action that can be reverted but changes something the admin should weigh before
 * allowing it, such as a discount that applies to every cart.
 *
 * The chat panel asks for these with the caution card: the lines this returns and a ticked
 * "I understand what this changes" instead of a plain Allow button, so a write proposed from
 * text the assistant read does not pass on one unread click. The skill-level counterpart of
 * HighImpactToolInterface, as IrreversibleActionInterface is of IrreversibleToolInterface.
 * @api
 */
interface CautionedActionInterface extends ActionInterface
{
    /**
     * What the call changes, in plain words, one line each; empty when the plain card will do.
     * Read the current state where it helps; never change anything here.
     *
     * @param array $params Tool call parameters, as the action will receive them
     * @param int $adminUserId Current admin user ID
     * @return string[]
     */
    public function getCautions(array $params, int $adminUserId): array;
}

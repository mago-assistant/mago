<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Tool;

use MagoAssistant\Mago\Api\Tool\AdminDescribedToolInterface;
use MagoAssistant\Mago\Api\Tool\ToolInterface;

/**
 * The text the Skills & Permissions page shows for a tool (#304): its own admin description, or
 * else the first sentence of the model description
 */
final class AdminDescription
{
    /**
     * The action list AbstractSkill appends, also when an add-on starts it on a new line
     */
    private const ACTION_LIST_MARKER = '/\s+Actions:\s/';

    /**
     * A sentence ends at . ! or ? followed by whitespace. The dot of "e.g." and "i.e." never ends
     * one, in any case; the dot of "etc." only does when the next word starts with a capital.
     */
    private const SENTENCE_END = '/(?<!\b(?i:e\.g|i\.e|etc))[.!?](?=\s)|(?<=\b(?i:etc))\.(?=\s+[A-Z])/';

    public function of(ToolInterface $tool): string
    {
        $declared = $tool instanceof AdminDescribedToolInterface ? trim($tool->getAdminDescription()) : '';

        return $declared !== '' ? $declared : $this->firstSentence($tool->getDescription());
    }

    private function firstSentence(string $description): string
    {
        $withoutActions = (preg_split(self::ACTION_LIST_MARKER, $description, 2) ?: [$description])[0];
        if (preg_match(self::SENTENCE_END, $withoutActions, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return trim($withoutActions);
        }

        return trim(substr($withoutActions, 0, $match[0][1] + 1));
    }
}

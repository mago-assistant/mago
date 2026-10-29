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
    private const ACTION_LIST_MARKER = ' Actions: ';

    /**
     * A sentence ends at . ! or ? followed by whitespace, unless the dot closes "e.g." or "i.e."
     */
    private const SENTENCE_END = '/(?<!\be\.g)(?<!\bi\.e)[.!?](?=\s)/';

    public function of(ToolInterface $tool): string
    {
        $declared = $tool instanceof AdminDescribedToolInterface ? trim($tool->getAdminDescription()) : '';

        return $declared !== '' ? $declared : $this->firstSentence($tool->getDescription());
    }

    private function firstSentence(string $description): string
    {
        $withoutActions = explode(self::ACTION_LIST_MARKER, $description, 2)[0];
        if (preg_match(self::SENTENCE_END, $withoutActions, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return trim($withoutActions);
        }

        return trim(substr($withoutActions, 0, $match[0][1] + 1));
    }
}

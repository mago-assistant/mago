<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Welcome;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Phrase;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;

/**
 * One-click example questions for the empty chat, registered per skill through di.xml.
 */
class ExampleQuestions
{
    private const MAX_QUESTIONS = 4;

    /**
     * @param array<string, array{question: string|Phrase, tool: string, action?: string, icon?: string, sortOrder?: int|string}> $questions
     */
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly AuthorizationInterface $authorization,
        private readonly array $questions = []
    ) {
    }

    /**
     * @return list<array{question: string, icon: string}>
     */
    public function getForAdmin(?int $adminUserId): array
    {
        $questions = $this->questions;
        uasort($questions, static fn(array $a, array $b): int => (int)($a['sortOrder'] ?? 0) <=> (int)($b['sortOrder'] ?? 0));

        $result = [];
        foreach ($questions as $item) {
            if (!$this->isAvailable($item, $adminUserId)) {
                continue;
            }
            $result[] = ['question' => $this->render($item), 'icon' => (string)($item['icon'] ?? '')];
            if (count($result) === self::MAX_QUESTIONS) {
                break;
            }
        }

        return $result;
    }

    /**
     * A question the admin could not get answered must not be offered: the skill has to be enabled,
     * granted for the action's read/write level, and pass the action's native Magento ACL.
     */
    private function isAvailable(array $item, ?int $adminUserId): bool
    {
        $tool = $this->toolRegistry->getTool((string)($item['tool'] ?? ''), $adminUserId);
        if ($tool === null) {
            return false;
        }

        $input = isset($item['action']) ? ['action' => (string)$item['action']] : [];
        if (!$this->toolRegistry->isCallAllowed($tool, $input, $adminUserId)) {
            return false;
        }

        $acl = $tool->getMagentoAcl($input);

        return $acl === '' || $this->authorization->isAllowed($acl);
    }

    private function render(array $item): string
    {
        // translate="true" in di.xml already yields a Phrase; a plain string still goes through __().
        return (string)($item['question'] instanceof Phrase ? $item['question'] : __((string)$item['question']));
    }
}

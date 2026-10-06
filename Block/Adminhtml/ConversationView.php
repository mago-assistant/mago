<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Escaper;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;

class ConversationView extends Template
{
    private const READABLE_JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const SCRIPT_SAFE_JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;

    private ?array $conversation = null;
    private ?array $messages = null;
    private ?array $usageData = null;

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resourceConnection,
        private readonly Escaper $escaper,
        private readonly TimezoneInterface $timezone,
        private readonly ConfigRepository $configRepository,
        private readonly AdminSession $adminSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getConversationId(): int
    {
        return (int)$this->getRequest()->getParam('id');
    }

    public function getConversation(): ?array
    {
        if ($this->conversation !== null) {
            return $this->conversation;
        }

        $connection = $this->resourceConnection->getConnection();
        $conversationTable = $this->resourceConnection->getTableName('mago_conversation');
        $adminUserTable = $this->resourceConnection->getTableName('admin_user');

        $select = $connection->select()
            ->from(['c' => $conversationTable])
            ->joinLeft(
                ['u' => $adminUserTable],
                'c.admin_user_id = u.user_id',
                ['username', 'firstname', 'lastname', 'email']
            )
            ->where('c.entity_id = ?', $this->getConversationId());

        $this->conversation = $connection->fetchRow($select) ?: null;
        return $this->conversation;
    }

    public function getMessages(): array
    {
        if ($this->messages !== null) {
            return $this->messages;
        }

        $connection = $this->resourceConnection->getConnection();
        $messageTable = $this->resourceConnection->getTableName('mago_message');

        $select = $connection->select()
            ->from($messageTable)
            ->where('conversation_id = ?', $this->getConversationId())
            ->order('created_at ASC')
            ->order('entity_id ASC');

        $this->messages = $connection->fetchAll($select);
        return $this->messages;
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('mago/conversations/index');
    }

    public function getAdminDisplayName(): string
    {
        $conv = $this->getConversation();
        if (!$conv) {
            return '';
        }

        $parts = array_filter([
            $conv['firstname'] ?? '',
            $conv['lastname'] ?? '',
        ]);
        $name = implode(' ', $parts);
        $username = $conv['username'] ?? '';

        return $name ? "$username ($name)" : $username;
    }

    public function getAccentColor(): string
    {
        return $this->configRepository->getAccentColor();
    }

    public function getUsageData(): array
    {
        if ($this->usageData !== null) {
            return $this->usageData;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_usage_log');

        $select = $connection->select()
            ->from($table, [
                'provider' => new Expression('MAX(provider)'),
                'model' => new Expression('MAX(model)'),
                'total_tokens' => new Expression('SUM(total_tokens)'),
                'input_tokens' => new Expression('SUM(input_tokens)'),
                'output_tokens' => new Expression('SUM(output_tokens)'),
                'api_calls' => new Expression('COUNT(*)'),
            ])
            ->where('conversation_id = ?', $this->getConversationId());

        $this->usageData = $connection->fetchRow($select) ?: [];
        return $this->usageData;
    }

    public function getApiCalls(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_usage_log');

        $select = $connection->select()
            ->from($table)
            ->where('conversation_id = ?', $this->getConversationId())
            ->order('created_at ASC')
            ->order('entity_id ASC');

        return $connection->fetchAll($select);
    }

    public function getSkillsUsed(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_usage_log');

        $select = $connection->select()
            ->from($table, ['skill_names'])
            ->where('conversation_id = ?', $this->getConversationId())
            ->where('skill_names IS NOT NULL')
            ->where('skill_names != ?', '');

        $rows = $connection->fetchCol($select);
        $skills = [];
        foreach ($rows as $row) {
            foreach (explode(',', $row) as $skill) {
                $skill = trim($skill);
                if ($skill !== '') {
                    $skills[$skill] = true;
                }
            }
        }

        return array_keys($skills);
    }

    public function getAdminFirstName(): string
    {
        $conv = $this->getConversation();
        return $conv['firstname'] ?? $conv['username'] ?? '';
    }

    public function getMessageDate(string $datetime): string
    {
        $date = $this->timezone->date(new \DateTime($datetime, new \DateTimeZone('UTC')));
        $today = $this->timezone->date();
        $yesterday = $this->timezone->date()->modify('-1 day');

        if ($date->format('Y-m-d') === $today->format('Y-m-d')) {
            return 'Today';
        }
        if ($date->format('Y-m-d') === $yesterday->format('Y-m-d')) {
            return 'Yesterday';
        }

        return $date->format('F j, Y');
    }

    public function getMessageTime(string $datetime): string
    {
        return $this->timezone->date(new \DateTime($datetime, new \DateTimeZone('UTC')))->format('H:i');
    }

    public function formatDateTime(string $datetime): string
    {
        return $this->timezone->date(new \DateTime($datetime, new \DateTimeZone('UTC')))->format('M j, Y H:i');
    }

    public function getAssistantName(): string
    {
        return $this->configRepository->getAssistantName();
    }

    public function getEstimatedCost(): string
    {
        $usage = $this->getUsageData();
        $input = (int)($usage['input_tokens'] ?? 0);
        $output = (int)($usage['output_tokens'] ?? 0);
        if ($input === 0 && $output === 0) {
            return '';
        }
        $cost = ($input / 1000000) * 3.0 + ($output / 1000000) * 15.0;
        return '$' . number_format($cost, 4);
    }

    public function getContinueUrl(): string
    {
        return $this->getUrl('mago/conversations/continueChat', ['id' => $this->getConversationId()]);
    }

    public function isOwnConversation(): bool
    {
        $conv = $this->getConversation();
        $currentUserId = (int)($this->adminSession->getUser()?->getId() ?? 0);
        return $currentUserId > 0 && $currentUserId === (int)($conv['admin_user_id'] ?? 0);
    }

    public function parseToolCalls(?string $toolCallsJson): array
    {
        if (empty($toolCallsJson)) {
            return [];
        }

        try {
            $decoded = json_decode($toolCallsJson, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException $e) {
            return [];
        }
    }

    /**
     * Extract tool names from message content (legacy "Using tool: X" format)
     * and return them, also stripping the lines from content.
     */
    public function extractInlineTools(string &$content): array
    {
        $tools = [];
        if (preg_match_all('/\*{0,2}Using tool:\s*(\w+)\*{0,2}/i', $content, $matches)) {
            $tools = array_unique($matches[1]);
            $content = (string)preg_replace('/\*{0,2}Using tool:\s*\w+\*{0,2}\s*/i', '', $content);
            $content = trim($content);
        }
        return $tools;
    }

    /**
     * Render markdown content to safe HTML.
     *
     * Supports: bold, italic, inline code, code blocks, links, paragraphs, line breaks.
     * Output is stripped to an allowlist of safe tags.
     */
    public function renderMarkdown(string $text): string
    {
        if (empty(trim($text))) {
            return '';
        }

        $html = $this->escaper->escapeHtml($text);

        // Code blocks: ```lang\ncode\n```
        $html = (string)preg_replace('/```\w*\n([\s\S]*?)```/m', '<pre><code>$1</code></pre>', $html);

        // Inline code: `code`
        $html = (string)preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

        // Bold: **text**
        $html = (string)preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);

        // Italic: *text*
        $html = (string)preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $html);

        // Markdown links [text](url) and bare URLs in one pass, so a bare URL inside a link's own
        // href is never linked a second time (that nested <a> broke out of the href attribute).
        // A link is http(s) or a path on this store: "//host" and "/\host" only look like paths.
        $html = (string)preg_replace_callback(
            '/\[([^\]]+)\]\(((?:https?:\/\/[^ )]+|\/(?![\/\\\\\s])[^ )]+))\)|(https?:\/\/[^\s<]+)/',
            function ($m) {
                if (isset($m[3])) {
                    return '<a href="' . $m[3] . '" target="_blank" rel="noopener noreferrer">' . $m[3] . '</a>';
                }
                $url = str_replace(["\n", "\r"], '', $m[2]);
                $target = str_starts_with($url, '/') ? '_self' : '_blank';
                return '<a href="' . $url . '" target="' . $target . '" rel="noopener noreferrer">'
                    . $m[1] . '</a>';
            },
            $html
        );

        // Paragraphs and line breaks
        $html = (string)str_replace("\n\n", '</p><p>', $html);
        $html = (string)str_replace("\n", '<br>', $html);
        $html = '<p>' . $html . '</p>';

        // Strip to allowed tags only
        return strip_tags($html, '<p><br><strong><em><code><pre><a>');
    }

    /**
     * Readable JSON for output that is HTML-escaped afterwards. Not safe to print unescaped.
     */
    public function formatJson(mixed $data): string
    {
        if (is_string($data)) {
            try {
                $data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return $data;
            }
        }

        return json_encode($this->decodeNestedValue($data), self::READABLE_JSON_FLAGS) ?: '';
    }

    /**
     * JSON that can be printed unescaped inside a <script> block. Payloads hold stored customer
     * input (reviews, addresses), so <, >, &, ' and " are always \u-escaped and a payload that
     * isn't valid JSON is encoded as a JSON string instead of being passed through.
     */
    public function formatJsonForScript(mixed $data): string
    {
        if ($data === null || $data === '') {
            return '';
        }

        if (is_string($data)) {
            try {
                $data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return json_encode($data, self::SCRIPT_SAFE_JSON_FLAGS) ?: '""';
            }
        }

        return json_encode($this->decodeNestedValue($data), self::SCRIPT_SAFE_JSON_FLAGS) ?: '""';
    }

    private function decodeNestedValue(mixed $data): mixed
    {
        return is_array($data) ? $this->decodeNestedJson($data) : $data;
    }

    private function decodeNestedJson(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (is_string($value) && isset($value[0]) && ($value[0] === '{' || $value[0] === '[')) {
                try {
                    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                    $value = is_array($decoded) ? $this->decodeNestedJson($decoded) : $decoded;
                } catch (\JsonException $e) {
                    // not JSON, keep as string
                }
            } elseif (is_array($value)) {
                $value = $this->decodeNestedJson($value);
            }
        }
        return $data;
    }
}

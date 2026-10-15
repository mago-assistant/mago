<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Config;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Config repository interface
 * @api
 */
interface RepositoryInterface
{
    public const EXTENSION_CODE = 'MagoAssistant_Mago';
    public const XML_PATH_EXTENSION_ENABLE = 'mago/general/enabled';
    public const XML_PATH_DEBUG = 'mago/debug/debug';
    public const XML_PATH_PAYLOAD_RETENTION_DAYS = 'mago/debug/payload_retention_days';
    public const XML_PATH_HISTORY_RETENTION_DAYS = 'mago/privacy/history_retention_days';
    public const XML_PATH_AI_SERVICE = 'mago/api/ai_service';
    public const XML_PATH_MAX_TOKENS = 'mago/api/max_tokens';
    public const XML_PATH_STREAMING = 'mago/api/streaming';
    public const XML_PATH_SYSTEM_PROMPT = 'mago/chat/system_prompt';
    public const XML_PATH_MAX_TOOL_ITERATIONS = 'mago/chat/max_tool_iterations';
    public const XML_PATH_MAX_RESPONSE_TOKENS = 'mago/tools/max_response_tokens';
    public const XML_PATH_ALLOW_REINDEX = 'mago/tools/allow_reindex';
    public const XML_PATH_CUSTOMER_NOTIFICATION_INTERVAL = 'mago/tools/customer_notification_interval';
    public const XML_PATH_ACCENT_COLOR = 'mago/chat/accent_color';
    public const XML_PATH_TEXT_COLOR = 'mago/chat/text_color';
    public const XML_PATH_ASSISTANT_NAME = 'mago/chat/assistant_name';
    public const XML_PATH_LANGUAGE = 'mago/chat/language';
    public const XML_PATH_ANSWER_WIDGETS = 'mago/chat/answer_widgets';
    public const XML_PATH_VOICE_INPUT = 'mago/voice/enabled';
    public const XML_PATH_VOICE_MODE = 'mago/voice/mode';
    public const XML_PATH_VOICE_LANGUAGE = 'mago/voice/language';
    public const XML_PATH_VOICE_SEND_DELAY = 'mago/voice/send_delay';
    public const XML_PATH_VOICE_IDLE_TIMEOUT = 'mago/voice/idle_timeout';
    public const XML_PATH_VOICE_WORDS = 'mago/voice/words_';
    public const XML_PATH_VOICE_NUMBER_WORDS = 'mago/voice/number_words';
    public const VOICE_COMMANDS = ['yes', 'no', 'stop'];
    /**
     * English command words that always work, whatever a shop adds, so a mistyped or emptied
     * setting can never leave voice input without a way to send, answer a card or stop.
     */
    public const VOICE_ENGLISH_WORDS = [
        'yes' => ['yes', 'send', 'send it', 'send now', 'submit', 'allow', 'ok', 'okay', 'confirm', 'go ahead', 'do it', 'approve'],
        'no' => ['no', 'discard', 'clear', 'delete', 'start over', 'scratch that', 'reject', 'nope', 'cancel', 'deny', 'not now', 'skip'],
        'stop' => ['stop', 'stop listening', 'microphone off'],
    ];
    public const XML_PATH_ADDONS_ENABLED = 'mago/addons/enabled';
    public const XML_PATH_DOCS_ENABLED = 'mago/docs/enabled';
    public const XML_PATH_DOCS_SOURCE_REPO = 'mago/docs/source_repo';
    public const XML_PATH_DOCS_REF = 'mago/docs/ref';
    public const XML_PATH_DOCS_TOP_K = 'mago/docs/top_k';
    /**
     * @return string
     */
    public function getExtensionVersion(): string;

    /**
     * @return string
     */
    public function getExtensionCode(): string;

    /**
     * @return string
     */
    public function getMagentoVersion(): string;

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool;

    /**
     * @param int|null $storeId
     * @return StoreInterface
     */
    public function getStore(?int $storeId = null): StoreInterface;

    /**
     * @return string
     */
    public function getSupportLink(): string;

    /**
     * @return bool
     */
    public function isDebugEnabled(): bool;

    /**
     * @return int
     */
    public function getPayloadRetentionDays(): int;

    /**
     * @return int
     */
    public function getHistoryRetentionDays(): int;

    /**
     * Row id of the MageOS_AiBase service the assistant runs on.
     *
     * Empty means "whichever service is usable first", which is what a single-provider store wants
     * and what a fresh install has. Credentials, model and endpoint all live on that row, under
     * Stores > Configuration > Mage-OS > AI Configuration.
     *
     * @return string
     */
    public function getAiServiceId(): string;

    /**
     * @return int
     */
    public function getMaxTokens(): int;

    /**
     * @return bool
     */
    public function isStreamingEnabled(): bool;

    /**
     * @return string
     */
    public function getSystemPrompt(): string;

    /**
     * @return int
     */
    public function getMaxToolIterations(): int;

    /**
     * @return int
     */
    public function getMaxResponseTokens(): int;

    /**
     * @return bool
     */
    public function isReindexAllowed(): bool;

    /**
     * Minutes Mago waits before it e-mails the customer of the same order again, 0 when unlimited
     *
     * @return int
     */
    public function getCustomerNotificationInterval(): int;

    /**
     * @return string
     */
    public function getAccentColor(): string;

    /**
     * @return string
     */
    public function getTextColor(): string;

    /**
     * @return string
     */
    public function getAssistantName(): string;

    /**
     * @return string
     */
    public function getLanguage(): string;

    /**
     * Whether the assistant may answer with the chat panel's widgets (```mago blocks)
     *
     * @return bool
     */
    public function isAnswerWidgetsEnabled(): bool;

    /**
     * Whether the chat panel offers a microphone button (browser speech recognition)
     *
     * @return bool
     */
    public function isVoiceInputEnabled(): bool;

    /**
     * How a spoken message is sent: dictate, auto_send or confirm (see Source\VoiceMode)
     *
     * @return string
     */
    public function getVoiceMode(): string;

    /**
     * BCP-47 tag for the speech recogniser, or "auto" to follow the admin interface locale
     *
     * @return string
     */
    public function getVoiceLanguage(): string;

    /**
     * Milliseconds the auto_send countdown runs after speech before a spoken message is sent
     *
     * @return int
     */
    public function getVoiceSendDelay(): int;

    /**
     * Seconds without speech after which a hands-free microphone turns itself off; 0 never does
     *
     * @return int
     */
    public function getVoiceIdleTimeout(): int;

    /**
     * Spoken command words per command in VOICE_COMMANDS, lower-cased: the shop's own words first
     * (the first one is what the panel suggests), then the built-in English ones
     *
     * @return array<string, string[]>
     */
    public function getVoiceWords(): array;

    /**
     * The shop's own words for the numbers that pick a suggestion, lower-cased: number => words.
     * Configured as "one/first, two/second, …"; English and digits are built into the panel.
     *
     * @return array<int, string[]>
     */
    public function getVoiceNumberWords(): array;

    /**
     * Whether the dashboard may show the add-on feed
     *
     * @return bool
     */
    public function isAddonFeedEnabled(): bool;

    /**
     * @return bool
     */
    public function isDocsEnabled(): bool;

    /**
     * @return string
     */
    public function getDocsSourceRepo(): string;

    /**
     * @return string
     */
    public function getDocsRef(): string;

    /**
     * @return int
     */
    public function getDocsTopK(): int;
}

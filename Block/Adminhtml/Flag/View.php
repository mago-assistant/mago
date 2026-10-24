<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Block\Adminhtml\Flag;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MagoAssistant\Mago\Service\Flag\FlagRepository;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;

/**
 * One flagged answer, read back out of its snapshot.
 *
 * Everything shown here comes from the flag row, never from the live conversation: a flag whose
 * conversation was deleted, or whose usage payloads have since been purged, still shows what it
 * showed the day it was flagged.
 *
 * Message text is stored tokenised (#97) and the snapshot keeps it that way, so the JSON bundle does
 * not carry customer data the store never sent to the provider. On screen it is rehydrated through
 * the conversation's vault while that still exists; once the conversation is gone the tokens read as
 * a neutral label, as they do everywhere else.
 */
class View extends Template
{
    private ?array $flag = null;
    private ?array $snapshot = null;
    private bool $isVaultBound = false;

    public function __construct(
        Context $context,
        private readonly FlagRepository $flagRepository,
        private readonly TimezoneInterface $timezone,
        private readonly PrivacyService $privacyService,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFlagId(): int
    {
        return (int)$this->getRequest()->getParam('id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFlag(): ?array
    {
        if ($this->flag === null) {
            $this->flag = $this->flagRepository->getById($this->getFlagId());
        }

        return $this->flag ?: null;
    }

    public function getRatingLabel(): string
    {
        return ($this->getFlag()['rating'] ?? '') === FlagRepository::RATING_UP
            ? (string)__('Thumbs up')
            : (string)__('Thumbs down');
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        if ($this->snapshot === null) {
            $flag = $this->getFlag();
            $this->snapshot = $flag ? $this->flagRepository->snapshot($flag) : [];
        }

        return $this->snapshot;
    }

    /**
     * False when the stored snapshot could not be decoded; FlagRepository logs why.
     */
    public function hasReadableSnapshot(): bool
    {
        return $this->getSnapshot() !== [];
    }

    /**
     * Whether payloads were dropped to fit the snapshot in its row (see SnapshotSizeLimit).
     */
    public function isPayloadTrimmed(): bool
    {
        return ($this->getUsage()['payloads_trimmed'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnswer(): array
    {
        return (array)($this->getSnapshot()['answer'] ?? []);
    }

    /**
     * The messages leading up to the flagged answer, as the snapshot stored them.
     *
     * @return list<array<string, mixed>>
     */
    public function getContext(): array
    {
        $messages = [];
        foreach ((array)($this->getSnapshot()['context'] ?? []) as $message) {
            if (is_array($message)) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUsage(): array
    {
        return (array)($this->getSnapshot()['usage'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getEnvironment(): array
    {
        return (array)($this->getSnapshot()['environment'] ?? []);
    }

    /**
     * The provider calls of the flagged turn, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function getCalls(): array
    {
        return array_values(array_filter(
            (array)($this->getUsage()['calls'] ?? []),
            'is_array'
        ));
    }

    /**
     * Whether the wire payloads were captured. They only exist when debug logging was on at the
     * time, so their absence is a fact about the store, not a broken flag.
     */
    public function hasPayloads(): bool
    {
        return array_filter(
            $this->getCalls(),
            static fn (array $call): bool => ($call['request_payload'] ?? null) !== null
                || ($call['response_payload'] ?? null) !== null
        ) !== [];
    }

    /**
     * Stored message text as the admin saw it in the panel.
     */
    public function displayText(string $text): string
    {
        if (!$this->isVaultBound) {
            $this->privacyService->beginConversation((int)($this->getFlag()['conversation_id'] ?? 0));
            $this->isVaultBound = true;
        }

        return $this->privacyService->displayText($text);
    }

    /**
     * Named apart from AbstractBlock::formatDate(), whose signature this does not follow.
     */
    public function formatFlagDate(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        return $this->timezone->formatDateTime(
            new \DateTime($value, new \DateTimeZone('UTC')),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }

    public function pretty(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function getConversationUrl(): string
    {
        $conversationId = (int)($this->getFlag()['conversation_id'] ?? 0);

        return $conversationId ? $this->getUrl('mago/conversations/view', ['id' => $conversationId]) : '';
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Store\Api\Data\StoreInterface;
use MagoAssistant\Mago\Api\Config\RepositoryInterface;

class FakeConfigRepository implements RepositoryInterface
{
    private bool $addonFeedEnabled = true;
    private int $maxToolIterations = 0;
    private int $customerNotificationInterval = 0;
    private bool $answerWidgets = false;    private int $maxResponseTokens = 0;
    private bool $reindexAllowed = false;
    private bool $isDebugEnabled = false;
    private int $debugEnabledReads = 0;
    private bool $isEnabled = true;
    private string $docsSourceRepo = '';
    private string $docsRef = '';

    public function withDebugEnabled(bool $isEnabled): self
    {
        $this->isDebugEnabled = $isEnabled;

        return $this;
    }

    public function getDebugEnabledReads(): int
    {
        return $this->debugEnabledReads;
    }

    public function withMaxToolIterations(int $maxToolIterations): self
    {
        $this->maxToolIterations = $maxToolIterations;

        return $this;
    }

    public function withCustomerNotificationInterval(int $minutes): self
    {
        $this->customerNotificationInterval = $minutes;

        return $this;
    }

    public function withMaxResponseTokens(int $maxResponseTokens): self
    {
        $this->maxResponseTokens = $maxResponseTokens;

        return $this;
    }

    public function withAnswerWidgets(bool $enabled): self
    {
        $this->answerWidgets = $enabled;

        return $this;
    }

    public function isAnswerWidgetsEnabled(): bool
    {
        return $this->answerWidgets;
    }

    public function getExtensionVersion(): string
    {
        return '1.0.0';
    }

    public function getExtensionCode(): string
    {
        return self::EXTENSION_CODE;
    }

    public function getMagentoVersion(): string
    {
        return '2.4.7';
    }

    public function withEnabled(bool $isEnabled): self
    {
        $this->isEnabled = $isEnabled;

        return $this;
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled;
    }

    public function getStore(?int $storeId = null): StoreInterface
    {
        throw new \LogicException('FakeConfigRepository does not provide stores');
    }

    public function getSupportLink(): string
    {
        return '';
    }

    public function isDebugEnabled(): bool
    {
        $this->debugEnabledReads++;

        return $this->isDebugEnabled;
    }

    public function getProvider(): string
    {
        return '';
    }

    public function getApiKey(): string
    {
        return '';
    }

    public function getModel(): string
    {
        return '';
    }

    public function getMaxTokens(): int
    {
        return 0;
    }

    public function getTemperature(): float
    {
        return 0.0;
    }

    public function isStreamingEnabled(): bool
    {
        return false;
    }

    public function getSystemPrompt(): string
    {
        return '';
    }

    public function getMaxToolIterations(): int
    {
        return $this->maxToolIterations;
    }

    public function getCustomerNotificationInterval(): int
    {
        return $this->customerNotificationInterval;
    }

    public function getAccentColor(): string
    {
        return '';
    }

    public function getTextColor(): string
    {
        return '';
    }

    public function getAssistantName(): string
    {
        return '';
    }

    public function getLanguage(): string
    {
        return '';
    }

    public function isAddonFeedEnabled(): bool
    {
        return $this->addonFeedEnabled;
    }

    public function withAddonFeedEnabled(bool $enabled): self
    {
        $this->addonFeedEnabled = $enabled;

        return $this;
    }

    public function withDocsSource(string $repo, string $ref): self
    {
        $this->docsSourceRepo = $repo;
        $this->docsRef = $ref;

        return $this;
    }

    public function isDocsEnabled(): bool
    {
        return $this->docsSourceRepo !== '';
    }

    public function getDocsSourceRepo(): string
    {
        return $this->docsSourceRepo;
    }

    public function getDocsRef(): string
    {
        return $this->docsRef;
    }

    public function getDocsTopK(): int
    {
        return 0;
    }

    public function getPayloadRetentionDays(): int
    {
        return 0;
    }

    public function getHistoryRetentionDays(): int
    {
        return 0;
    }

    public function getAiServiceId(): string
    {
        return '';
    }

    public function getMaxResponseTokens(): int
    {
        return $this->maxResponseTokens;
    }

    public function withReindexAllowed(bool $isAllowed): self
    {
        $this->reindexAllowed = $isAllowed;

        return $this;
    }

    public function isReindexAllowed(): bool
    {
        return $this->reindexAllowed;
    }
}

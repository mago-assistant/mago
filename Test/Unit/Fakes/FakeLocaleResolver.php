<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\Locale\ResolverInterface;

/**
 * Emulates a locale per store id the way Magento's resolver does: emulate() stacks, revert() pops.
 */
final class FakeLocaleResolver implements ResolverInterface
{
    /** @var list<string> */
    private array $emulatedLocales = [];

    /**
     * @param array<int, string> $localesByStoreId
     */
    public function __construct(
        private string $locale = 'en_US',
        private readonly array $localesByStoreId = []
    ) {
    }

    public function getDefaultLocalePath(): string
    {
        return 'general/locale/code';
    }

    public function setDefaultLocale($locale): self
    {
        return $this;
    }

    public function getDefaultLocale(): string
    {
        return 'en_US';
    }

    public function setLocale($locale = null): self
    {
        $this->locale = (string)$locale;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function emulate($scopeId): ?string
    {
        $this->emulatedLocales[] = $this->locale;
        $this->locale = $this->localesByStoreId[(int)$scopeId] ?? $this->locale;

        return $this->locale;
    }

    public function revert(): ?string
    {
        $this->locale = array_pop($this->emulatedLocales) ?? $this->locale;

        return $this->locale;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\VoicePreferenceRepositoryInterface;

class FakeVoicePreferenceRepository implements VoicePreferenceRepositoryInterface
{
    /** @var array<int, true> */
    private array $consented = [];

    /** @var array<int, string|null> */
    private array $modes = [];

    public function hasConsented(int $adminUserId): bool
    {
        return isset($this->consented[$adminUserId]);
    }

    public function recordConsent(int $adminUserId): void
    {
        if ($adminUserId > 0) {
            $this->consented[$adminUserId] = true;
        }
    }

    public function getMode(int $adminUserId): ?string
    {
        return $this->modes[$adminUserId] ?? null;
    }

    public function setMode(int $adminUserId, ?string $mode): void
    {
        if ($adminUserId > 0) {
            $this->modes[$adminUserId] = $mode;
        }
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api;

/**
 * Voice input settings of one admin user, kept server-side so they follow the admin into every
 * browser: whether they acknowledged that the browser vendor handles the audio, and the voice
 * mode they picked in the chat (null leaves the configured default in charge).
 */
interface VoicePreferenceRepositoryInterface
{
    /**
     * @param int $adminUserId
     * @return bool
     */
    public function hasConsented(int $adminUserId): bool;

    /**
     * Records the acknowledgement now; recording it again keeps the first moment
     *
     * @param int $adminUserId
     * @return void
     */
    public function recordConsent(int $adminUserId): void;

    /**
     * @param int $adminUserId
     * @return string|null
     */
    public function getMode(int $adminUserId): ?string;

    /**
     * @param int $adminUserId
     * @param string|null $mode one of Model\Config\Source\VoiceMode, or null for the default
     * @return void
     */
    public function setMode(int $adminUserId, ?string $mode): void;
}

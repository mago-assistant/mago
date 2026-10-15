<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Voice;

use Magento\Framework\App\ResourceConnection;
use MagoAssistant\Mago\Api\VoicePreferenceRepositoryInterface;

class PreferenceRepository implements VoicePreferenceRepositoryInterface
{
    private const TABLE = 'mago_voice_preference';

    /** @var array<int, array<string, mixed>|false> */
    private array $rows = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function hasConsented(int $adminUserId): bool
    {
        return !empty($this->row($adminUserId)['consented_at']);
    }

    public function recordConsent(int $adminUserId): void
    {
        if ($adminUserId <= 0 || $this->hasConsented($adminUserId)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::TABLE),
            ['admin_user_id' => $adminUserId, 'consented_at' => gmdate('Y-m-d H:i:s')],
            ['consented_at']
        );
        unset($this->rows[$adminUserId]);
    }

    public function getMode(int $adminUserId): ?string
    {
        $mode = $this->row($adminUserId)['mode'] ?? null;

        return $mode === null || $mode === '' ? null : (string)$mode;
    }

    public function setMode(int $adminUserId, ?string $mode): void
    {
        if ($adminUserId <= 0) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::TABLE),
            ['admin_user_id' => $adminUserId, 'mode' => $mode],
            ['mode']
        );
        unset($this->rows[$adminUserId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $adminUserId): array
    {
        if ($adminUserId <= 0) {
            return [];
        }

        if (!array_key_exists($adminUserId, $this->rows)) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName(self::TABLE))
                ->where('admin_user_id = ?', $adminUserId);
            $this->rows[$adminUserId] = $connection->fetchRow($select);
        }

        return $this->rows[$adminUserId] ?: [];
    }
}

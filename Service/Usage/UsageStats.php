<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Usage;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

class UsageStats
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getForPeriod(string $period): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_usage_log');
        $from = $this->getFromDate($period);

        $select = $connection->select()
            ->from($table, [
                'total_requests' => new Expression('COUNT(*)'),
                'total_input_tokens' => new Expression('SUM(input_tokens)'),
                'total_output_tokens' => new Expression('SUM(output_tokens)'),
                'total_tokens' => new Expression('SUM(total_tokens)'),
                'total_cache_read_tokens' => new Expression('SUM(cache_read_tokens)'),
                'total_cache_write_tokens' => new Expression('SUM(cache_write_tokens)'),
                'unique_users' => new Expression('COUNT(DISTINCT admin_user_id)'),
            ]);

        if ($from) {
            $select->where('created_at >= ?', $from);
        }

        $result = $connection->fetchRow($select);

        $inputTokens = (int)($result['total_input_tokens'] ?? 0);
        $outputTokens = (int)($result['total_output_tokens'] ?? 0);

        return [
            'total_requests' => (int)($result['total_requests'] ?? 0),
            'total_input_tokens' => $inputTokens,
            'total_output_tokens' => $outputTokens,
            'total_tokens' => (int)($result['total_tokens'] ?? 0),
            // SUM() over only-NULL rows is NULL: nothing in the period reported cache figures
            'total_cache_read_tokens' => isset($result['total_cache_read_tokens'])
                ? (int)$result['total_cache_read_tokens']
                : null,
            'total_cache_write_tokens' => isset($result['total_cache_write_tokens'])
                ? (int)$result['total_cache_write_tokens']
                : null,
            'unique_users' => (int)($result['unique_users'] ?? 0),
            'estimated_cost' => $this->estimateCost($inputTokens, $outputTokens),
        ];
    }

    public function getUserBreakdown(string $period): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_usage_log');
        $adminTable = $this->resourceConnection->getTableName('admin_user');
        $from = $this->getFromDate($period);

        $select = $connection->select()
            ->from(['u' => $table], [
                'admin_user_id',
                'request_count' => new Expression('COUNT(*)'),
                'total_tokens' => new Expression('SUM(u.total_tokens)'),
                'total_input' => new Expression('SUM(u.input_tokens)'),
                'total_output' => new Expression('SUM(u.output_tokens)'),
            ])
            ->joinLeft(['a' => $adminTable], 'a.user_id = u.admin_user_id', [
                'username' => 'a.username',
                'firstname' => 'a.firstname',
                'lastname' => 'a.lastname',
            ])
            ->group('u.admin_user_id')
            ->order('total_tokens DESC');

        if ($from) {
            $select->where('u.created_at >= ?', $from);
        }

        $rows = $connection->fetchAll($select);
        $users = [];
        foreach ($rows as $row) {
            $input = (int)($row['total_input'] ?? 0);
            $output = (int)($row['total_output'] ?? 0);
            $users[] = [
                'admin_user_id' => (int)$row['admin_user_id'],
                'username' => $row['username'] ?? 'Unknown',
                'name' => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
                'request_count' => (int)$row['request_count'],
                'total_tokens' => (int)$row['total_tokens'],
                'estimated_cost' => $this->estimateCost($input, $output),
            ];
        }

        return $users;
    }

    public function getSkillBreakdown(string $period): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_usage_log');
        $from = $this->getFromDate($period);

        $select = $connection->select()
            ->from($table, ['skill_names'])
            ->where('skill_names IS NOT NULL')
            ->where('skill_names != ?', '');

        if ($from) {
            $select->where('created_at >= ?', $from);
        }

        $rows = $connection->fetchCol($select);
        $counts = [];
        foreach ($rows as $skillNames) {
            foreach (explode(',', $skillNames) as $name) {
                $name = trim($name);
                if ($name) {
                    $counts[$name] = ($counts[$name] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        $skills = [];
        foreach ($counts as $name => $count) {
            $skills[] = ['name' => $name, 'usage_count' => $count];
        }

        return $skills;
    }

    public function getDailyTrend(int $days = 30): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_usage_log');
        $startDate = (new \DateTimeImmutable())->modify("-" . ($days - 1) . " days");
        $from = $startDate->format('Y-m-d 00:00:00');

        $select = $connection->select()
            ->from($table, [
                'date' => new Expression('DATE(created_at)'),
                'requests' => new Expression('COUNT(*)'),
                'tokens' => new Expression('SUM(total_tokens)'),
            ])
            ->where('created_at >= ?', $from)
            ->group(new Expression('DATE(created_at)'))
            ->order('date ASC');

        $rows = $connection->fetchAll($select);
        $dataByDate = [];
        foreach ($rows as $row) {
            $dataByDate[$row['date']] = $row;
        }

        $trend = [];
        $current = $startDate;
        $end = new \DateTimeImmutable();
        while ($current->format('Y-m-d') <= $end->format('Y-m-d')) {
            $dateKey = $current->format('Y-m-d');
            $trend[] = $dataByDate[$dateKey] ?? [
                'date' => $dateKey,
                'requests' => 0,
                'tokens' => 0,
            ];
            $current = $current->modify('+1 day');
        }

        return $trend;
    }

    private function estimateCost(int $inputTokens, int $outputTokens): float
    {
        // Default estimate using Claude Sonnet pricing
        $inputCost = ($inputTokens / 1000000) * 3.0;
        $outputCost = ($outputTokens / 1000000) * 15.0;
        return round($inputCost + $outputCost, 4);
    }

    private function getFromDate(string $period): ?string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return match ($period) {
            'today' => $now->format('Y-m-d 00:00:00'),
            '7days' => $now->modify('-7 days')->format('Y-m-d 00:00:00'),
            '30days' => $now->modify('-30 days')->format('Y-m-d 00:00:00'),
            'all' => null,
            default => $now->modify('-30 days')->format('Y-m-d 00:00:00'),
        };
    }
}

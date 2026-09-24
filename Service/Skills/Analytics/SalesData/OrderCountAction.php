<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\SalesData;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\PeriodParser;

class OrderCountAction implements ActionInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PeriodParser $periodParser
    ) {
    }

    public function getName(): string
    {
        return 'order_count';
    }

    public function getDescription(): string
    {
        return 'Count orders by status for a period';
    }

    public function getParameterSchema(): array
    {
        return [
            'country' => [
                'type' => 'string',
                'description' => 'Two-letter country code of the billing address, e.g. "NL", "DE", "CH". '
                    . 'Pass it whenever the question names a country.',
            ],
            'group_by' => [
                'type' => 'string',
                'enum' => ['status', 'year', 'month'],
                'description' => 'How to break the count down. "status" is the default. Use "year" or '
                    . '"month" for a question about a development over time: the buckets come from the '
                    . 'orders themselves, so you never have to guess which years exist.',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Order status to count, e.g. "processing", "complete". Left out, '
                    . 'every status is counted and broken down.',
            ],
            'period' => [
                'type' => 'string',
                'description' => 'Time period: "today", "yesterday", "7days", "30days", "this_month", "last_month", "this_year", "all" for no lower bound, "YYYY-MM" for a specific month, or "YYYY-MM-DD:YYYY-MM-DD" for a custom range',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Sales::sales';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        // by_status keys the counts by order status code, including custom ones, so the keys cannot
        // be enumerated; the whole result is count aggregates.
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getInstructions(): string
    {
        return 'filters_applied lists what the number actually counts. Name every filter the '
            . 'question asked for; if one you needed is missing from that list, the count is wider '
            . 'than the question and has to be reported as such rather than as the answer.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $period = $params['period'] ?? '30days';
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order');
        [$from, $to] = $this->periodParser->parse($period);

        $groupBy = (string)($params['group_by'] ?? 'status');
        $bucket = match ($groupBy) {
            'year' => new Expression('YEAR(o.created_at)'),
            'month' => new Expression('DATE_FORMAT(o.created_at, \'%Y-%m\')'),
            default => new Expression('o.status'),
        };

        $select = $connection->select()
            ->from(['o' => $table], [
                'bucket' => $bucket,
                'count' => new Expression('COUNT(*)'),
            ])
            ->where('o.created_at >= ?', $from)
            ->where('o.created_at <= ?', $to)
            ->group(new Expression((string)$bucket))
            ->order(new Expression((string)$bucket));

        $applied = ['period'];

        $country = strtoupper(trim((string)($params['country'] ?? '')));
        if ($country !== '') {
            $select->join(
                ['a' => $this->resourceConnection->getTableName('sales_order_address')],
                'a.parent_id = o.entity_id AND a.address_type = \'billing\'',
                []
            )->where('a.country_id = ?', $country);
            $applied[] = 'country';
        }

        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $select->where('o.status = ?', $status);
            $applied[] = 'status';
        }

        $rows = $connection->fetchAll($select);
        $buckets = [];
        $total = 0;
        foreach ($rows as $row) {
            $buckets[(string)$row['bucket']] = (int)$row['count'];
            $total += (int)$row['count'];
        }

        return [
            'period' => $period,
            'country' => $country !== '' ? $country : null,
            'status_filter' => $status !== '' ? $status : null,
            // What the number actually counts. A tool that quietly ignores half the question
            // answers a different one, and a plausible number is harder to spot than an empty one.
            'filters_applied' => $applied,
            'grouped_by' => $groupBy,
            'total' => $total,
            'counts' => $buckets,
        ];
    }
}

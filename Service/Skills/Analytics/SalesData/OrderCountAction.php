<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\SalesData;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Time\CalendarBuckets;

class OrderCountAction implements ActionInterface
{
    private const ADDRESS_SHIPPING = 'shipping';
    private const ADDRESS_BILLING = 'billing';

    /** The bucket of an order that has no address at all */
    private const UNKNOWN_COUNTRY = 'unknown';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PeriodParser $periodParser,
        private readonly CalendarBuckets $calendarBuckets
    ) {
    }

    public function getName(): string
    {
        return 'order_count';
    }

    public function getDescription(): string
    {
        return 'Count orders for a period, by status, year, month or country';
    }

    public function getParameterSchema(): array
    {
        return [
            'country' => [
                'type' => 'string',
                'description' => 'Two-letter country code, e.g. "NL", "DE", "CH". Pass it whenever the '
                    . 'question names a country, and leave it out when it does not. Which address it is '
                    . 'matched against is set by "address".',
            ],
            'address' => [
                'type' => 'string',
                'enum' => [self::ADDRESS_SHIPPING, self::ADDRESS_BILLING],
                'description' => 'Which address the country of an order comes from, for both "country" '
                    . 'and group_by "country". "shipping" is the default: where the order went. An order '
                    . 'without a shipping address (only virtual or downloadable products) counts under its '
                    . 'billing country. Use "billing" only when the question is about where customers are '
                    . 'billed.',
            ],
            'group_by' => [
                'type' => 'string',
                'enum' => ['status', 'year', 'month', 'country'],
                'description' => 'How to break the count down. "status" is the default. Use "year" or '
                    . '"month" for a question about a development over time: the buckets come from the '
                    . 'orders themselves, so you never have to guess which years exist. Use "country" for '
                    . 'which countries orders come from or go to; the buckets are two-letter country codes, '
                    . 'most orders first, and "unknown" for orders without an address.',
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
            . 'than the question and has to be reported as such rather than as the answer. When '
            . 'country_address is set, say whether the countries are where orders were shipped to or '
            . 'where they were billed.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $period = $params['period'] ?? '30days';
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order');
        [$from, $to] = $this->periodParser->parse($period);

        $address = strtolower(trim((string)($params['address'] ?? ''))) === self::ADDRESS_BILLING
            ? self::ADDRESS_BILLING
            : self::ADDRESS_SHIPPING;
        $country = strtoupper(trim((string)($params['country'] ?? '')));
        // A model that has no country to pass sometimes passes "none" or "all" instead, and filtering
        // on that counts nothing. Saying so lets it ask again without the filter.
        if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return [
                'error' => sprintf(
                    '"%s" is not a two-letter country code. Leave country out to count every country.',
                    (string)$params['country']
                ),
            ];
        }
        $groupBy = (string)($params['group_by'] ?? 'status');
        $needsCountry = $country !== '' || $groupBy === 'country';
        $countryColumn = $this->countryColumn($address);

        $bucket = match ($groupBy) {
            'year' => $this->calendarBucket($this->calendarBuckets->years(...$this->orderSpan($from, $to))),
            'month' => $this->calendarBucket($this->calendarBuckets->months(...$this->orderSpan($from, $to))),
            'country' => new Expression(sprintf('COALESCE(%s, \'%s\')', $countryColumn, self::UNKNOWN_COUNTRY)),
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
            // The largest country first is the answer to "where do orders come from"; time and status
            // read best in their own order.
            ->order($groupBy === 'country'
                ? [new Expression('COUNT(*) DESC'), new Expression((string)$bucket)]
                : new Expression((string)$bucket));

        if ($needsCountry) {
            $this->joinAddresses($select);
        }

        $applied = ['period'];

        if ($country !== '') {
            $select->where($countryColumn . ' = ?', $country);
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
            // Which address the country came from, so "orders from Germany" is not read as "shipped
            // to Germany" when it was billing, or the other way around.
            'country_address' => $needsCountry ? $address : null,
            'status_filter' => $status !== '' ? $status : null,
            // What the number actually counts. A tool that quietly ignores half the question
            // answers a different one, and a plausible number is harder to spot than an empty one.
            'filters_applied' => $applied,
            'grouped_by' => $groupBy,
            'total' => $total,
            'counts' => $buckets,
        ];
    }

    /**
     * The first and last order in the window, so the buckets cover the orders there are rather than
     * every month since 1970 for period "all". Without any order the window's end is a single,
     * empty bucket.
     *
     * @return array{0: string, 1: string} UTC
     */
    private function orderSpan(string $from, string $to): array
    {
        $connection = $this->resourceConnection->getConnection();
        $span = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName('sales_order'), [
                    'first' => new Expression('MIN(created_at)'),
                    'last' => new Expression('MAX(created_at)'),
                ])
                ->where('created_at >= ?', $from)
                ->where('created_at <= ?', $to)
        );

        return [(string)($span['first'] ?? $to), (string)($span['last'] ?? $to)];
    }

    /**
     * Newest start first, so the first WHEN an order passes is the bucket it belongs to.
     *
     * @param list<array{label: string, from: string}> $buckets
     */
    private function calendarBucket(array $buckets): Expression
    {
        $connection = $this->resourceConnection->getConnection();
        $cases = array_map(
            static fn (array $bucket): string => $connection->quoteInto('WHEN o.created_at >= ?', $bucket['from'])
                . ' THEN ' . $connection->quote($bucket['label']),
            array_reverse($buckets)
        );

        return new Expression('CASE ' . implode(' ', $cases) . ' END');
    }

    /**
     * Both addresses are joined, not only the one asked for: the shipping country falls back to the
     * billing one for an order that has nothing to ship.
     */
    private function joinAddresses(Select $select): void
    {
        $addressTable = $this->resourceConnection->getTableName('sales_order_address');

        $select->joinLeft(
            ['shipping' => $addressTable],
            'shipping.parent_id = o.entity_id AND shipping.address_type = \'shipping\'',
            []
        )->joinLeft(
            ['billing' => $addressTable],
            'billing.parent_id = o.entity_id AND billing.address_type = \'billing\'',
            []
        );
    }

    private function countryColumn(string $address): string
    {
        return $address === self::ADDRESS_BILLING
            ? 'billing.country_id'
            : 'COALESCE(shipping.country_id, billing.country_id)';
    }
}

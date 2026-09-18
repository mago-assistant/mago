<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\CustomerData;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class TopSpendersAction implements ActionInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PeriodParser $periodParser,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'top_spenders';
    }

    public function getDescription(): string
    {
        return 'Customers with highest order totals';
    }

    public function getParameterSchema(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Time period: "7days", "30days", "this_month", "this_year"',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results (default: 10)',
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
        // The bare customer id is tokenised so the assistant can still refer to the row.
        return [
            'period' => [PiiClass::PUBLIC],
            'customer_id' => [PiiClass::TOKENISE, 'customer'],
            'total_spent' => [PiiClass::PUBLIC],
            'order_count' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $period = $params['period'] ?? '30days';
        $limit = (int)($params['limit'] ?? 10);
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $from = $this->periodParser->getFromDate($period);

        $select = $connection->select()
            ->from($orderTable, [
                'customer_id',
                'total_spent' => new Expression('SUM(grand_total)'),
                'order_count' => new Expression('COUNT(*)'),
            ])
            ->where('customer_id IS NOT NULL')
            ->where('created_at >= ?', $from)
            ->where('state NOT IN (?)', ['canceled', 'closed'])
            ->group('customer_id')
            ->order('total_spent DESC')
            ->limit($limit);

        $rows = $connection->fetchAll($select);
        $spenders = [];
        foreach ($rows as $row) {
            $spenders[] = [
                'customer_id' => (int)$row['customer_id'],
                'total_spent' => round((float)$row['total_spent'], 2),
                'order_count' => (int)$row['order_count'],
                'admin_url' => $this->secureAdminUrl->getUrl('customer/index/edit', ['id' => (int)$row['customer_id']]),
            ];
        }

        return ['period' => $period, 'top_spenders' => $spenders];
    }
}

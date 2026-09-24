<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager;

use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as StatusCollectionFactory;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class UpdateStatusAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly OrderResolver $orderResolver,
        private readonly StatusCollectionFactory $statusCollectionFactory
    ) {
    }

    public function getName(): string
    {
        return 'update_status';
    }

    public function getDescription(): string
    {
        return 'Update the status of an order';
    }

    public function getParameterSchema(): array
    {
        return [
            'order_number' => [
                'type' => 'string',
                'description' => 'Order increment ID (e.g. "000000549")',
            ],
            'status' => [
                'type' => 'string',
                'enum' => $this->availableStatuses(),
                'description' => 'The status to set. These are the statuses this store actually has; '
                    . 'there is no "shipped" among them, because shipping an order creates a shipment '
                    . 'rather than changing its status.',
            ],
            'comment' => [
                'type' => 'string',
                'description' => 'Optional comment to add with the status change',
            ],
            'notify_customer' => [
                'type' => 'boolean',
                'description' => 'Whether to notify the customer (default: false)',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        // The ack's order number is a linkable id (tokenised); the message may embed it too, which
        // the filter's vault-conceal pass covers.
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'success' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            'order_number' => [PiiClass::TOKENISE, 'order'],
            'old_status' => [PiiClass::PUBLIC],
            'new_status' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'Not all status transitions are valid. Magento will reject invalid transitions via the API. '
            . 'Common statuses: pending, processing, complete, holded, canceled, closed.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $orderNumber = $params['order_number'] ?? '';
        $newStatus = $params['status'] ?? '';

        if (empty($orderNumber)) {
            return ['error' => 'order_number parameter is required'];
        }

        if (empty($newStatus)) {
            return ['error' => 'status parameter is required'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $order = $this->orderResolver->resolve($orderNumber, $adminUserId);
        if (isset($order['error'])) {
            return $order;
        }

        $entityId = $order['entity_id'];
        $oldStatus = $order['status'];
        $notifyCustomer = !empty($params['notify_customer']);
        $comment = $params['comment'] ?? '';

        $body = [
            'statusHistory' => [
                'comment' => $comment ?: 'Status updated to ' . $newStatus,
                'status' => $newStatus,
                'is_customer_notified' => $notifyCustomer ? 1 : 0,
                'is_visible_on_front' => 0,
            ],
        ];

        $result = $this->apiClient->post('orders/' . $entityId . '/comments', $body, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        return [
            'success' => true,
            'message' => 'Order #' . $order['increment_id'] . ' status updated from "' . $oldStatus . '" to "' . $newStatus . '"',
            'order_number' => $order['increment_id'],
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
        ];
    }

    /**
     * The statuses this store has, read from the store rather than assumed. A model asked to ship an
     * order reaches for a status called "shipped", which Magento has never had, and the web api
     * refuses it after the write has already been confirmed. Offering the real list up front is the
     * difference between a question it can answer and a guess it cannot.
     *
     * @return string[]
     */
    private function availableStatuses(): array
    {
        $statuses = [];
        foreach ($this->statusCollectionFactory->create() as $status) {
            $statuses[] = (string)$status->getStatus();
        }

        return $statuses;
    }
}

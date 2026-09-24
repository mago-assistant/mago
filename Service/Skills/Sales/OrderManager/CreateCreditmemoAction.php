<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager;

use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class CreateCreditmemoAction implements IrreversibleActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly OrderResolver $orderResolver
    ) {
    }

    public function getName(): string
    {
        return 'create_creditmemo';
    }

    public function getDescription(): string
    {
        return 'Create a credit memo (refund) for an order';
    }

    public function getParameterSchema(): array
    {
        return [
            'order_number' => [
                'type' => 'string',
                'description' => 'Order increment ID (e.g. "000000549")',
            ],
            'notify_customer' => [
                'type' => 'boolean',
                'description' => 'Whether to notify the customer (default: false)',
            ],
            'adjustment_positive' => [
                'type' => 'number',
                'description' => 'Extra refund amount to add',
            ],
            'adjustment_negative' => [
                'type' => 'number',
                'description' => 'Amount to withhold from the refund',
            ],
            'comment' => [
                'type' => 'string',
                'description' => 'Optional comment to add to the credit memo',
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
        // The ack's ids are linkable (tokenised); the message may embed the order number, which the
        // filter's vault-conceal pass covers.
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'success' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            'creditmemo_id' => [PiiClass::TOKENISE, 'creditmemo'],
            'order_number' => [PiiClass::TOKENISE, 'order'],
        ];
    }

    public function getInstructions(): string
    {
        return 'The order must be invoiced before a credit memo can be created. '
            . 'By default, a full credit memo (all items) is created.';
    }

    public function getImpacts(array $params, int $adminUserId): array
    {
        $orderNumber = (string)($params['order_number'] ?? '');
        $label = $orderNumber !== '' ? 'Order #' . $orderNumber : 'The order';
        $lines = [
            $label . ' is refunded in full through the original payment method; the refund cannot be recalled.',
        ];
        if (isset($params['adjustment_positive']) && (float)$params['adjustment_positive'] > 0) {
            $lines[] = 'An extra ' . (float)$params['adjustment_positive'] . ' is refunded on top of the order total.';
        }
        if (isset($params['adjustment_negative']) && (float)$params['adjustment_negative'] > 0) {
            $lines[] = (float)$params['adjustment_negative'] . ' is withheld from the refund.';
        }
        $lines[] = !empty($params['notify_customer'])
            ? 'The customer receives the credit memo by e-mail.'
            : 'The customer is not notified.';

        return $lines;
    }

    public function execute(array $params, int $adminUserId): array
    {
        $orderNumber = $params['order_number'] ?? '';

        if (empty($orderNumber)) {
            return ['error' => 'order_number parameter is required'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $order = $this->orderResolver->resolve($orderNumber, $adminUserId);
        if (isset($order['error'])) {
            return $order;
        }

        $entityId = $order['entity_id'];
        $notify = $params['notify_customer'] ?? false;

        $body = [
            'notify' => (bool)$notify,
        ];

        $adjustmentPositive = $params['adjustment_positive'] ?? null;
        if ($adjustmentPositive !== null) {
            $body['adjustment_positive'] = (float)$adjustmentPositive;
        }

        $adjustmentNegative = $params['adjustment_negative'] ?? null;
        if ($adjustmentNegative !== null) {
            $body['adjustment_negative'] = (float)$adjustmentNegative;
        }

        $comment = $params['comment'] ?? '';
        if (!empty($comment)) {
            $body['comment'] = [
                'comment' => $comment,
            ];
        }

        $result = $this->apiClient->post('order/' . $entityId . '/refund', $body, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $creditmemoId = $result['result'] ?? $result['id'] ?? null;

        return [
            'success' => true,
            'message' => 'Credit memo created for order #' . $order['increment_id'],
            'creditmemo_id' => $creditmemoId,
            'order_number' => $order['increment_id'],
            'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
        ];
    }
}

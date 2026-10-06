<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager;

use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class CreateCreditmemoAction implements IrreversibleActionInterface
{
    private const ADJUSTMENTS = ['adjustment_positive', 'adjustment_negative'];

    public function __construct(
        private readonly InternalApiClientInterface $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly OrderResolver $orderResolver,
        private readonly CustomerNotificationGuard $notificationGuard
    ) {
    }

    public function getName(): string
    {
        return 'create_creditmemo';
    }

    public function getDescription(): string
    {
        return 'Create an offline credit memo for an order. It records the refund in Magento only: '
            . 'no money is refunded through the payment provider.';
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
                'description' => 'Whether to e-mail the credit memo to the customer (default: false). '
                    . CustomerNotificationGuard::PARAMETER_RULES,
            ],
            'adjustment_positive' => [
                'type' => 'number',
                'description' => 'Extra amount to credit',
            ],
            'adjustment_negative' => [
                'type' => 'number',
                'description' => 'Amount to withhold from the credit memo',
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
            . 'By default, a full credit memo (all items) is created. '
            . 'This is an offline credit memo: it does not refund any money through the payment provider, '
            . 'so the merchant has to return the money to the customer separately. Never say the customer '
            . 'was refunded or paid back.';
    }

    public function getImpacts(array $params, int $adminUserId): array
    {
        $orderNumber = (string)($params['order_number'] ?? '');
        $label = $orderNumber !== '' ? 'Order #' . $orderNumber : 'The order';
        $lines = [
            $label . ' gets an offline credit memo for all items; it cannot be recalled.',
            'No money is refunded through the payment provider: return the money to the customer separately.',
        ];
        if (isset($params['adjustment_positive']) && (float)$params['adjustment_positive'] > 0) {
            $lines[] = 'An extra ' . (float)$params['adjustment_positive'] . ' is credited on top of the order total.';
        }
        if (isset($params['adjustment_negative']) && (float)$params['adjustment_negative'] > 0) {
            $lines[] = (float)$params['adjustment_negative'] . ' is withheld from the credit memo.';
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
        $notify = !empty($params['notify_customer']);

        if ($notify) {
            $refusal = $this->notificationGuard->findRefusal(
                (int)$entityId,
                (string)$order['increment_id'],
                CustomerNotificationGuard::KIND_CREDITMEMO
            );
            if ($refusal !== null) {
                return $refusal;
            }
        }

        $body = [
            'notify' => $notify,
        ];

        $arguments = $this->getAdjustments($params);
        if ($arguments !== []) {
            $body['arguments'] = $arguments;
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

        if ($notify) {
            $this->notificationGuard->recordSent((int)$entityId, CustomerNotificationGuard::KIND_CREDITMEMO);
        }

        $creditmemoId = $result['result'] ?? $result['id'] ?? null;

        return [
            'success' => true,
            'message' => 'Offline credit memo created for order #' . $order['increment_id']
                . '. No money was refunded through the payment provider.',
            'creditmemo_id' => $creditmemoId,
            'order_number' => $order['increment_id'],
            'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
        ];
    }

    /**
     * RefundOrderInterface::execute() takes the adjustments inside its $arguments
     * (CreditmemoCreationArgumentsInterface); sent next to "notify" they are silently dropped.
     *
     * @param array<string, mixed> $params
     * @return array<string, float>
     */
    private function getAdjustments(array $params): array
    {
        return array_map(
            static fn (mixed $amount): float => (float)$amount,
            array_filter(
                array_intersect_key($params, array_flip(self::ADJUSTMENTS)),
                static fn (mixed $amount): bool => $amount !== null
            )
        );
    }
}

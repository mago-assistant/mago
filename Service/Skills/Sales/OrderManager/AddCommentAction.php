<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager;

use MagoAssistant\Mago\Api\Skill\ConditionallyIrreversibleActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class AddCommentAction implements ConditionallyIrreversibleActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly OrderResolver $orderResolver,
        private readonly CustomerNotificationGuard $notificationGuard
    ) {
    }

    public function getName(): string
    {
        return 'add_comment';
    }

    public function getDescription(): string
    {
        return 'Add a comment or note to an order';
    }

    public function getParameterSchema(): array
    {
        return [
            'order_number' => [
                'type' => 'string',
                'description' => 'Order increment ID (e.g. "000000549")',
            ],
            'comment' => [
                'type' => 'string',
                'description' => 'Comment text to add to the order',
            ],
            'notify_customer' => [
                'type' => 'boolean',
                'description' => 'Whether to e-mail the comment to the customer (default: false). '
                    . CustomerNotificationGuard::PARAMETER_RULES,
            ],
            'visible_on_front' => [
                'type' => 'boolean',
                'description' => 'Whether the comment is visible on the storefront (default: false)',
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
        ];
    }

    public function getInstructions(): string
    {
        return 'notify_customer e-mails this comment to the customer. It does not resend the order confirmation '
            . 'or any other e-mail, so never use it as a substitute for one.';
    }

    public function isIrreversible(array $params): bool
    {
        return !empty($params['notify_customer']);
    }

    public function getImpacts(array $params, int $adminUserId): array
    {
        $orderNumber = (string)($params['order_number'] ?? '');

        return [$this->notificationGuard->describeEmail($orderNumber, 'this comment')];
    }

    public function execute(array $params, int $adminUserId): array
    {
        $orderNumber = $params['order_number'] ?? '';
        $comment = $params['comment'] ?? '';

        if (empty($orderNumber)) {
            return ['error' => 'order_number parameter is required'];
        }

        if (empty($comment)) {
            return ['error' => 'comment parameter is required'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $order = $this->orderResolver->resolve($orderNumber, $adminUserId);
        if (isset($order['error'])) {
            return $order;
        }

        $entityId = $order['entity_id'];
        $notifyCustomer = !empty($params['notify_customer']);
        $visibleOnFront = !empty($params['visible_on_front']);

        if ($notifyCustomer) {
            $refusal = $this->notificationGuard->findRefusal(
                (int)$entityId,
                (string)$order['increment_id'],
                CustomerNotificationGuard::KIND_COMMENT
            );
            if ($refusal !== null) {
                return $refusal;
            }
        }

        $body = [
            'statusHistory' => [
                'comment' => $comment,
                'is_customer_notified' => $notifyCustomer ? 1 : 0,
                'is_visible_on_front' => $visibleOnFront ? 1 : 0,
            ],
        ];

        $result = $this->apiClient->post('orders/' . $entityId . '/comments', $body, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        if ($notifyCustomer) {
            $this->notificationGuard->recordSent((int)$entityId, CustomerNotificationGuard::KIND_COMMENT);
        }

        return [
            'success' => true,
            'message' => 'Comment added to order #' . $order['increment_id'],
            'order_number' => $order['increment_id'],
            'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
        ];
    }
}

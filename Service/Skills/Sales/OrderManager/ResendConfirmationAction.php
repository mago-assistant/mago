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

/**
 * Sends the order confirmation e-mail to the customer again, like "Send Email" on the order view.
 *
 * Irreversible, so the administrator ticks "I understand" before it runs, and limited by
 * CustomerNotificationGuard to one confirmation e-mail per order per interval.
 */
class ResendConfirmationAction implements IrreversibleActionInterface
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
        return 'resend_confirmation';
    }

    public function getDescription(): string
    {
        return 'Send the order confirmation e-mail to the customer again (one e-mail per call)';
    }

    public function getParameterSchema(): array
    {
        return [
            'order_number' => [
                'type' => 'string',
                'description' => 'Order increment ID (e.g. "000000549")',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Sales::emails';
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
        return '';
    }

    public function getImpacts(array $params, int $adminUserId): array
    {
        $orderNumber = (string)($params['order_number'] ?? '');

        return [$this->notificationGuard->describeEmail($orderNumber, 'their order (the order confirmation)')];
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
        $refusal = $this->notificationGuard->findRefusal(
            (int)$entityId,
            (string)$order['increment_id'],
            CustomerNotificationGuard::KIND_CONFIRMATION
        );
        if ($refusal !== null) {
            return $refusal;
        }

        $result = $this->apiClient->post('orders/' . $entityId . '/emails', [], $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        // Magento answers false when it did not send, e.g. with order e-mails disabled in the config
        if (($result['result'] ?? false) !== true) {
            return [
                'error' => 'Magento did not send the order confirmation for order #' . $order['increment_id']
                    . '. Order e-mails may be disabled in Stores > Configuration > Sales > Sales Emails.',
            ];
        }

        $this->notificationGuard->recordSent((int)$entityId, CustomerNotificationGuard::KIND_CONFIRMATION);

        return [
            'success' => true,
            'message' => 'Order confirmation sent again for order #' . $order['increment_id'],
            'order_number' => $order['increment_id'],
            'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
        ];
    }
}

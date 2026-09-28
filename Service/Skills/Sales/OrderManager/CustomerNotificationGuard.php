<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Lets Mago send the customer of an order each kind of e-mail at most once per configured interval.
 *
 * Asked to "send 100 confirmation e-mails", a model happily calls a notifying action again and
 * again, and the confirmation card is approved with one click. The prompt cannot be trusted to
 * stop that, so every order action that e-mails the customer asks here first, after approval.
 * Kinds are counted apart, so an invoice e-mail followed by a shipment e-mail still goes through.
 * The moment of the last e-mail lives in the cache without a tag: cache:clean leaves it alone,
 * only cache:flush resets it.
 */
class CustomerNotificationGuard
{
    private const CACHE_KEY_PREFIX = 'mago_customer_notification_';

    /** A comment and a status change send the same order-update e-mail, so they share a kind */
    public const KIND_COMMENT = 'comment';
    public const KIND_INVOICE = 'invoice';
    public const KIND_SHIPMENT = 'shipment';
    public const KIND_CREDITMEMO = 'creditmemo';
    public const KIND_CONFIRMATION = 'confirmation';

    /**
     * Appended to every notify_customer parameter description. The rules must sit in the schema:
     * skill instructions only reach the model after its first call and never after a confirmed
     * write, so they come too late to stop a bulk e-mail request, and the tool description is
     * shown to the administrator on the confirmation card.
     */
    public const PARAMETER_RULES = 'This sends the customer a real e-mail. Never send the same e-mail more '
        . 'than once or in bulk: for a request like "send 100 confirmation e-mails", offer to send it once '
        . 'instead. To send the order confirmation again use resend_confirmation, never a comment e-mail. '
        . 'When a customer e-mail fails or is refused, say that no e-mail was sent and do not suggest trying again.';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ConfigRepository $configRepository,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * The tool result that refuses the e-mail, or null when the customer may be e-mailed now
     *
     * @param int $orderId Order entity id
     * @param string $orderNumber Order increment id, for the message
     * @param string $kind One of the KIND_* constants
     * @return array{error: string}|null
     */
    public function findRefusal(int $orderId, string $orderNumber, string $kind): ?array
    {
        $interval = $this->configRepository->getCustomerNotificationInterval();
        if ($interval === 0) {
            return null;
        }

        $sentAt = (int)$this->cache->load($this->cacheKey($orderId, $kind));
        if ($sentAt === 0) {
            return null;
        }

        $elapsedMinutes = intdiv(max(0, $this->dateTime->gmtTimestamp() - $sentAt), 60);
        if ($elapsedMinutes >= $interval) {
            return null;
        }

        return [
            'error' => sprintf(
                'Not sent: Mago already sent the customer of order #%s the %s e-mail %s ago. To keep customers '
                . 'from being flooded, Mago sends each kind of e-mail for an order at most once every %d minutes. '
                . 'Do not retry. Tell the user that no e-mail went out and nothing was changed; if the change '
                . 'itself is still needed, it can run again with notify_customer false.',
                $orderNumber,
                $kind,
                $elapsedMinutes < 1 ? 'less than a minute' : $elapsedMinutes . ' minute(s)',
                $interval
            ),
        ];
    }

    /**
     * Remember that the customer of the order was just e-mailed
     *
     * @param int $orderId Order entity id
     * @param string $kind One of the KIND_* constants
     */
    public function recordSent(int $orderId, string $kind): void
    {
        $interval = $this->configRepository->getCustomerNotificationInterval();
        if ($interval === 0) {
            return;
        }

        $this->cache->save(
            (string)$this->dateTime->gmtTimestamp(),
            $this->cacheKey($orderId, $kind),
            [],
            $interval * 60
        );
    }

    /**
     * The confirmation card line that says the customer gets an e-mail
     *
     * @param string $orderNumber Order increment id as the model proposed it, may be empty
     * @param string $subject What the e-mail is about, e.g. "the shipment"
     */
    public function describeEmail(string $orderNumber, string $subject): string
    {
        $customer = $orderNumber !== '' ? 'The customer of order #' . $orderNumber : 'The customer';

        return $customer . ' receives an e-mail about ' . $subject . '; a sent e-mail cannot be recalled.';
    }

    private function cacheKey(int $orderId, string $kind): string
    {
        return self::CACHE_KEY_PREFIX . $kind . '_order_' . $orderId;
    }
}

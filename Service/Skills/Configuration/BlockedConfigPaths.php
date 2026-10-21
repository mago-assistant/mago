<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Configuration;

/**
 * Settings config_writer never changes, however the request is worded or confirmed.
 *
 * A caution card asks the admin to tick that they understand a change, but a tick is all a write
 * proposed from text the assistant read (a prompt injection) needs. For these settings one wrong
 * value hands the store, its admin or its mail to someone else, and there is no everyday reason
 * to change them from a chat: the storefront and admin URLs, the mail server, every address mail
 * with customer data goes to (copies, bounces, contact form, error reports, and the store email
 * addresses, which receive customer replies and the payment-failed mail with the customer's name
 * and addresses), two-factor and admin login security, and the scripts every storefront page
 * loads. The admin changes them under Stores > Configuration, where Magento's own validation and
 * warnings apply.
 *
 * Patterns are globs matched against the lowercased config path: "*" stands for any run of
 * characters, slashes included, and "?" for one character. $additionalPatterns
 * extends the list from di.xml, the way FormPolicy's deny lists are extended, so a module can
 * block settings of its own; it cannot remove a default:
 *
 *     <type name="MagoAssistant\Mago\Service\Skills\Configuration\BlockedConfigPaths">
 *         <arguments>
 *             <argument name="additionalPatterns" xsi:type="array">
 *                 <item name="vendor_gateway_url" xsi:type="string">vendor_section/gateway/*_url</item>
 *             </argument>
 *         </arguments>
 *     </type>
 */
class BlockedConfigPaths
{
    private const DEFAULT_PATTERNS = [
        'web/*/base_*url',
        'admin/url/*',
        'system/smtp/host',
        'system/smtp/port',
        'system/smtp/return_path_email',
        '*/copy_to',
        'contact/email/recipient_email',
        'trans_email/ident_*/email',
        '*/error_email',
        'twofactorauth/*',
        'admin/security/*',
        'admin/captcha/*',
        'design/head/includes',
        'design/footer/absolute_footer',
    ];

    /**
     * @var string[] Anchored regular expressions, one per pattern
     */
    private readonly array $expressions;

    /**
     * @param array<string|int, string> $additionalPatterns
     */
    public function __construct(array $additionalPatterns = [])
    {
        $patterns = array_merge(
            self::DEFAULT_PATTERNS,
            array_filter(
                array_map(static fn (mixed $pattern): string => strtolower(trim((string)$pattern)), $additionalPatterns),
                static fn (string $pattern): bool => $pattern !== ''
            )
        );
        $this->expressions = array_values(array_map($this->toExpression(...), $patterns));
    }

    public function isBlocked(string $path): bool
    {
        $lowercasePath = strtolower($path);

        return array_filter(
            $this->expressions,
            static fn (string $expression): bool => preg_match($expression, $lowercasePath) === 1
        ) !== [];
    }

    /**
     * A regular expression rather than fnmatch(), which warns (and so throws under Magento's error
     * handler) on a path longer than the platform's maximum file name
     */
    private function toExpression(string $pattern): string
    {
        return '#\A' . strtr(preg_quote($pattern, '#'), ['\\*' => '.*', '\\?' => '.']) . '\z#s';
    }
}

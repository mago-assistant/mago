<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Configuration;

use Magento\Config\Model\Config\TypePool;

/**
 * Why a configuration change deserves a second look on the confirmation card (#245). The ACL
 * already decides whether the admin may change the setting; this names the settings where a
 * change proposed from text the assistant read would hurt most: what runs on every storefront
 * page, how the admin is secured and reached, the store's URLs, where its email goes.
 */
final class ConfigWriteImpact
{
    private const STOREFRONT_CODE = 'Adds or changes HTML and scripts on every storefront page.';
    private const SECURITY = 'Changes how the admin or customer accounts are secured, '
        . 'or where the admin is reached.';
    private const URLS = 'Changes the store\'s URLs, cookies or redirects; '
        . 'a wrong value can make the store or the admin unreachable.';
    private const EMAIL = 'Changes where store email is sent from, sent to or copied to.';
    private const DEVELOPER = 'Changes developer settings that affect the live store.';
    private const ENVIRONMENT = 'This setting usually differs per environment, such as production and staging.';
    private const SENSITIVE = 'Changes a setting Magento treats as sensitive.';

    /**
     * Path prefixes and the reason a change there stands out, first match wins.
     */
    private const REASONS = [
        'design/head/includes' => self::STOREFRONT_CODE,
        'design/footer/absolute_footer' => self::STOREFRONT_CODE,
        'design/theme/' => self::STOREFRONT_CODE,
        'twofactorauth/' => self::SECURITY,
        'admin/security/' => self::SECURITY,
        'admin/captcha/' => self::SECURITY,
        'admin/url/' => self::SECURITY,
        'customer/captcha/' => self::SECURITY,
        'customer/password/' => self::SECURITY,
        'oauth/' => self::SECURITY,
        'webapi/' => self::SECURITY,
        'csp/' => self::SECURITY,
        'web/secure/' => self::URLS,
        'web/unsecure/' => self::URLS,
        'web/cookie/' => self::URLS,
        'web/url/' => self::URLS,
        'system/smtp/' => self::EMAIL,
        'trans_email/' => self::EMAIL,
        'contact/email/' => self::EMAIL,
        'dev/' => self::DEVELOPER,
    ];

    private const EMAIL_COPY_FIELDS = ['copy_to', 'copy_method'];

    public function __construct(
        private readonly TypePool $typePool
    ) {
    }

    /**
     * The reason a change to this path stands out, or null for an everyday setting.
     *
     * @param string $path
     * @return string|null
     */
    public function reasonFor(string $path): ?string
    {
        $pathLower = strtolower($path);
        foreach (self::REASONS as $prefix => $reason) {
            if (str_starts_with($pathLower, $prefix)) {
                return $reason;
            }
        }
        if (in_array(substr($pathLower, (int)strrpos($pathLower, '/') + 1), self::EMAIL_COPY_FIELDS, true)) {
            return self::EMAIL;
        }
        if ($this->typePool->isPresent($path, TypePool::TYPE_ENVIRONMENT)) {
            return self::ENVIRONMENT;
        }

        return $this->typePool->isPresent($path, TypePool::TYPE_SENSITIVE) ? self::SENSITIVE : null;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Url;

/**
 * A downloadable link url as proposed to create_product. Only plain https to a named host is
 * allowed onto a product, and the refusal lands before the administrator is asked to confirm.
 */
final readonly class DownloadableLinkUrl
{
    private const ALLOWED_SCHEME = 'https';
    private const LOCALHOST = 'localhost';
    private const MAX_IPV4_LABELS = 4;

    public function __construct(private string $url)
    {
    }

    public function findRefusalReason(): ?string
    {
        $parts = parse_url($this->url);
        $host = is_array($parts) ? strtolower(rtrim(trim((string)($parts['host'] ?? '')), '.')) : '';
        if ($host === '') {
            return $this->refusal('it has no host');
        }
        if (strtolower($parts['scheme'] ?? '') !== self::ALLOWED_SCHEME) {
            return $this->refusal('only https urls are allowed');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $this->refusal('it carries credentials');
        }
        if ($this->isIpLiteral($host)) {
            return $this->refusal('it points at an IP address instead of a hostname');
        }
        if ($this->isLocalhost($host)) {
            return $this->refusal('it points at localhost');
        }

        return null;
    }

    private function refusal(string $reason): string
    {
        return 'Refused downloadable link url "' . $this->url . '": ' . $reason
            . '. Use an https url to a hostname from the store\'s downloadable_domains.';
    }

    private function isIpLiteral(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || $this->isNumericIpv4($host);
    }

    /**
     * Resolvers also accept IPv4 addresses written as decimal, hex, octal or fewer than four
     * labels ("2130706433", "0x7f000001", "0177.0.0.1", "127.1"), which FILTER_VALIDATE_IP
     * does not recognize as IP addresses.
     */
    private function isNumericIpv4(string $host): bool
    {
        $labels = explode('.', $host);
        if (count($labels) > self::MAX_IPV4_LABELS) {
            return false;
        }

        return array_reduce(
            $labels,
            static fn (bool $allNumeric, string $label): bool =>
                $allNumeric && preg_match('/^(0x[0-9a-f]+|[0-9]+)$/i', $label) === 1,
            true
        );
    }

    private function isLocalhost(string $host): bool
    {
        return $host === self::LOCALHOST || str_ends_with($host, '.' . self::LOCALHOST);
    }
}

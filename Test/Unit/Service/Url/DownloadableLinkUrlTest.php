<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Url;

use MagoAssistant\Mago\Service\Url\DownloadableLinkUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DownloadableLinkUrlTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'plain http' => ['http://downloads.example.test/ebook.pdf'],
            'local file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://downloads.example.test/ebook.pdf'],
            'php wrapper' => ['php://filter/resource=/etc/passwd'],
            'no scheme' => ['//downloads.example.test/ebook.pdf'],
            'loopback ip' => ['https://127.0.0.1/ebook.pdf'],
            'cloud metadata ip' => ['https://169.254.169.254/latest/meta-data/'],
            'private ip' => ['https://10.0.0.5/ebook.pdf'],
            'ipv6 loopback' => ['https://[::1]/ebook.pdf'],
            'localhost' => ['https://localhost/ebook.pdf'],
            'localhost subdomain' => ['https://api.localhost/ebook.pdf'],
            'localhost with trailing dot' => ['https://localhost./ebook.pdf'],
            'decimal ipv4' => ['https://2130706433/ebook.pdf'],
            'hex ipv4' => ['https://0x7f000001/ebook.pdf'],
            'octal ipv4' => ['https://0177.0.0.1/ebook.pdf'],
            'short form ipv4' => ['https://127.1/ebook.pdf'],
            'ipv4 with trailing dot' => ['https://127.0.0.1./ebook.pdf'],
            'dot as host' => ['https://./ebook.pdf'],
            'embedded credentials' => ['https://user:secret@downloads.example.test/ebook.pdf'],
            'not a url' => ['not a url at all'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safeUrls(): array
    {
        return [
            'https to a named host' => ['https://downloads.example.test/ebook.pdf'],
            'uppercase scheme' => ['HTTPS://downloads.example.test/ebook.pdf'],
            'explicit port' => ['https://downloads.example.test:8443/ebook.pdf'],
        ];
    }

    #[Test]
    #[DataProvider('unsafeUrls')]
    public function itRefusesAUrlThatIsNotHttpsToANamedHost(string $url): void
    {
        $reason = (new DownloadableLinkUrl($url))->findRefusalReason();

        self::assertNotNull($reason, 'Accepted an unsafe downloadable link url: ' . $url);
        self::assertStringContainsString($url, $reason);
    }

    #[Test]
    #[DataProvider('safeUrls')]
    public function itAcceptsAnHttpsUrlToANamedHost(string $url): void
    {
        self::assertNull((new DownloadableLinkUrl($url))->findRefusalReason());
    }
}

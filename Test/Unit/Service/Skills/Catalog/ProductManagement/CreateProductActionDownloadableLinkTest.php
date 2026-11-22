<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductManagement;

use MagoAssistant\Mago\Service\Skills\Catalog\ProductManagement\CreateProductAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Security audit 2026-11-02, findings 1 and 2: a downloadable link url is fetched by the server when
 * a customer downloads it, so create_product must refuse a url that is not plain https to a named
 * host before it hands the product to the catalog.
 */
final class CreateProductActionDownloadableLinkTest extends TestCase
{
    private const ADMIN_USER_ID = 1;
    private const SKU = 'EBOOK-1';
    private const PRODUCTS_ENDPOINT = 'products';

    private FakeInternalApiClient $apiClient;

    protected function setUp(): void
    {
        $this->apiClient = (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'products/' . self::SKU, ['error' => 'Not found'])
            ->withResponse(FakeInternalApiClient::POST, self::PRODUCTS_ENDPOINT, ['id' => 42, 'sku' => self::SKU]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeLinkUrls(): array
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
            'embedded credentials' => ['https://user:secret@downloads.example.test/ebook.pdf'],
        ];
    }

    #[Test]
    #[DataProvider('unsafeLinkUrls')]
    public function itRefusesADownloadableLinkThatIsNotHttpsToANamedHost(string $url): void
    {
        $result = $this->createDownloadableWithLink($url);

        self::assertArrayHasKey('error', $result, 'Accepted an unsafe downloadable link url: ' . $url);
        self::assertSame(
            [],
            $this->postedProducts(),
            'The product was sent to the catalog with an unsafe downloadable link url: ' . $url
        );
    }

    #[Test]
    public function itCreatesADownloadableProductWithAnHttpsLinkToANamedHost(): void
    {
        $result = $this->createDownloadableWithLink('https://downloads.example.test/ebook.pdf');

        self::assertTrue($result['success'] ?? false);
        self::assertSame(
            'https://downloads.example.test/ebook.pdf',
            $this->postedProducts()[0]['extension_attributes']['downloadable_product_links'][0]['link_url']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function createDownloadableWithLink(string $url): array
    {
        return (new CreateProductAction($this->apiClient, new SecureAdminUrl(new FakeBackendUrl())))->execute(
            [
                'sku' => self::SKU,
                'name' => 'E-book',
                'product_type' => 'downloadable',
                'price' => 9.95,
                'downloadable_links' => [['title' => 'E-book', 'url' => $url]],
            ],
            self::ADMIN_USER_ID
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function postedProducts(): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['payload']['product'],
            array_filter(
                $this->apiClient->callsOf(FakeInternalApiClient::POST),
                static fn (array $call): bool => $call['endpoint'] === self::PRODUCTS_ENDPOINT
            )
        ));
    }
}

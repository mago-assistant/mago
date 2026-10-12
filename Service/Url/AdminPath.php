<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Url;

use Magento\Backend\Model\UrlInterface as BackendUrl;

/**
 * The path every admin page lives under: the base URL's path plus the admin frontName, so a
 * custom admin path, a store in a subdirectory or index.php in the URL all count.
 */
final class AdminPath
{
    public function __construct(
        private readonly BackendUrl $backendUrl
    ) {
    }

    public function get(): string
    {
        return $this->getBasePath() . $this->backendUrl->getAreaFrontName() . '/';
    }

    private function getBasePath(): string
    {
        return rtrim((string)parse_url((string)$this->backendUrl->getBaseUrl(), PHP_URL_PATH), '/') . '/';
    }
}

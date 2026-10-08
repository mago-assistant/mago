<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Backend\Model\Auth\Session;
use Magento\Backend\Model\UrlInterface;

/**
 * A backend URL builder for one install: its base URL and admin frontName, with admin URLs built
 * from the two the way Magento does, without the secret key.
 */
final class FakeBackendUrl implements UrlInterface
{
    public function __construct(
        private readonly string $baseUrl = 'https://shop.test/',
        private readonly string $frontName = 'admin'
    ) {
    }

    public function getBaseUrl($params = []): string
    {
        return $this->baseUrl;
    }

    public function getAreaFrontName(): string
    {
        return $this->frontName;
    }

    public function getUrl($routePath = null, $routeParams = null): string
    {
        return $this->baseUrl . $this->frontName . '/' . trim((string)$routePath, '/') . '/';
    }

    public function getRouteUrl($routePath = null, $routeParams = null): string
    {
        return $this->getUrl($routePath, $routeParams);
    }

    public function getDirectUrl($url, $params = []): string
    {
        return $this->baseUrl . ltrim((string)$url, '/');
    }

    public function getRedirectUrl($url): string
    {
        return (string)$url;
    }

    public function getCurrentUrl(): string
    {
        return $this->baseUrl . $this->frontName . '/';
    }

    public function getStartupPageUrl(): string
    {
        return 'admin/dashboard';
    }

    public function getSecretKey($routeName = null, $controller = null, $action = null): string
    {
        return '';
    }

    public function useSecretKey(): bool
    {
        return false;
    }

    public function turnOnSecretKey(): self
    {
        return $this;
    }

    public function turnOffSecretKey(): self
    {
        return $this;
    }

    public function renewSecretUrls(): void
    {
    }

    public function setSession(Session $session): self
    {
        return $this;
    }

    public function findFirstAvailableMenu(): string
    {
        return 'admin/dashboard';
    }

    public function getUseSession(): bool
    {
        return false;
    }

    public function addSessionParam(): self
    {
        return $this;
    }

    public function addQueryParams(array $data): self
    {
        return $this;
    }

    public function setQueryParam($key, $data): self
    {
        return $this;
    }

    public function escape($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES);
    }

    public function sessionUrlVar($html): string
    {
        return (string)$html;
    }

    public function isOwnOriginUrl(): bool
    {
        return true;
    }

    public function setScope($params): self
    {
        return $this;
    }
}

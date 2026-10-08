<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Docs;

use Magento\Framework\HTTP\AsyncClient\HttpException;
use Magento\Framework\HTTP\AsyncClient\Request;
use Magento\Framework\HTTP\AsyncClientInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use Psr\Http\Client\ClientExceptionInterface;

class GitHubDocsSource
{
    private const TREES_URL = 'https://api.github.com/repos/%s/git/trees/%s?recursive=1';
    private const RAW_URL = 'https://raw.githubusercontent.com/%s/%s/%s';
    private const USER_AGENT = 'MagoAssistant-Mago';
    private const HTTP_OK = 200;

    public function __construct(
        private readonly Json $json,
        private readonly AsyncClientInterface $httpClient,
        private readonly ErrorLogger $errorLogger,
        private readonly ErrorReporter $errorReporter
    ) {
    }

    private function isValidSource(string $repo, string $ref): bool
    {
        return (bool)preg_match('#^[\w.-]+/[\w.-]+$#', $repo)
            && $ref !== ''
            && (bool)preg_match('#^[\w.\-/]+$#', $ref);
    }

    /**
     * Returns ['sha' => <tree sha>, 'paths' => <help/**.md paths>] or null on failure.
     *
     * @param string $repo
     * @param string $ref
     * @return array{sha: string, paths: string[]}|null
     */
    public function fetchTree(string $repo, string $ref): ?array
    {
        if (!$this->isValidSource($repo, $ref)) {
            $this->errorLogger->addLog('DocsSource', 'Invalid repo/ref: ' . $repo . '@' . $ref);
            return null;
        }

        $url = sprintf(self::TREES_URL, $repo, rawurlencode($ref));
        $body = $this->get($url, ['Accept' => 'application/vnd.github+json']);
        if ($body === null) {
            return null;
        }

        try {
            $data = $this->json->unserialize($body);
        } catch (\Throwable $e) {
            $this->errorReporter->log('DocsSource: invalid tree JSON', $e);
            return null;
        }

        if (!empty($data['truncated'])) {
            $this->errorLogger->addLog('DocsSource', 'Tree truncated for ' . $repo . '@' . $ref . ' — aborting sync');
            return null;
        }

        if (empty($data['tree']) || !is_array($data['tree'])) {
            return null;
        }

        $paths = [];
        foreach ($data['tree'] as $node) {
            if (($node['type'] ?? '') !== 'blob') {
                continue;
            }
            $path = (string)($node['path'] ?? '');
            if (str_starts_with($path, 'help/') && str_ends_with($path, '.md')) {
                $paths[] = $path;
            }
        }

        return ['sha' => (string)($data['sha'] ?? ''), 'paths' => $paths];
    }

    public function fetchRaw(string $repo, string $ref, string $path): ?string
    {
        if (!$this->isValidSource($repo, $ref)) {
            return null;
        }
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url = sprintf(self::RAW_URL, $repo, rawurlencode($ref), $encodedPath);
        return $this->get($url, ['Accept' => 'text/plain']);
    }

    /**
     * Goes through the docs HTTP client from di.xml, which holds the timeouts and redirect rules.
     * Magento's Curl client is avoided on purpose: it never closes its handle and references
     * itself from its header callback, so a sync of several hundred files kept as many sockets
     * open until the cycle collector ran and hit "Too many open files" (#248). Guzzle reuses a
     * small pool of handles instead.
     *
     * @param string $url
     * @param array<string,string> $headers
     */
    private function get(string $url, array $headers): ?string
    {
        try {
            $response = $this->httpClient->request(
                new Request($url, Request::METHOD_GET, ['User-Agent' => self::USER_AGENT] + $headers, null)
            )->get();
        } catch (HttpException | ClientExceptionInterface $e) {
            $this->errorReporter->log('DocsSource: ' . $url, $e);
            return null;
        }

        if ($response->getStatusCode() !== self::HTTP_OK) {
            $this->errorLogger->addLog('DocsSource', 'HTTP ' . $response->getStatusCode() . ' for ' . $url);
            return null;
        }

        return $response->getBody();
    }
}

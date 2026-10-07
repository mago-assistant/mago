<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Docs;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Error\ErrorReporter;

class GitHubDocsSource
{
    private const TREES_URL = 'https://api.github.com/repos/%s/git/trees/%s?recursive=1';
    private const RAW_URL = 'https://raw.githubusercontent.com/%s/%s/%s';
    private const USER_AGENT = 'MagoAssistant-Mago';
    private const TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;
    private const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly Json $json,
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
     * A plain curl handle, freed when the call returns. Magento's HTTP client never closes its
     * handle and references itself from its header callback, so a sync of several hundred files
     * kept as many sockets open until the cycle collector ran and hit "Too many open files" (#248).
     *
     * @param array<string, string> $headers
     */
    private function get(string $url, array $headers = []): ?string
    {
        $httpHeaders = ['User-Agent: ' . self::USER_AGENT];
        foreach ($headers as $name => $value) {
            $httpHeaders[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $this->errorLogger->addLog('DocsSource', 'Could not start a request for ' . $url);
            return null;
        }
        try {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_HTTPHEADER => $httpHeaders,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
        } finally {
            curl_close($ch);
        }

        if ($body === false) {
            $this->errorLogger->addLog('DocsSource', $error . ' for ' . $url);
            return null;
        }
        if ($status !== 200) {
            $this->errorLogger->addLog('DocsSource', 'HTTP ' . $status . ' for ' . $url);
            return null;
        }

        return (string)$body;
    }
}

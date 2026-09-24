<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Hypernode;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

/**
 * Where the Hypernode skill finds its app name, API token and access log: admin configuration
 * first, then what the Hypernode itself provides when Magento runs on it.
 */
class Config
{
    public const XML_PATH_APP_NAME = 'mago/hypernode/app_name';
    public const XML_PATH_API_TOKEN = 'mago/hypernode/api_token';
    public const XML_PATH_ACCESS_LOG_PATH = 'mago/hypernode/access_log_path';
    public const XML_PATH_ON_NODE = 'mago/hypernode/on_node';

    public const ON_NODE_AUTO = 'auto';
    public const ON_NODE_YES = 'yes';
    public const ON_NODE_NO = 'no';

    public const API_URL = 'https://api.hypernode.com';
    public const INSIGHTS_URL = 'https://insights.hypernode.com';
    public const TOKEN_FILE = '/etc/hypernode/hypernode_api_token';
    public const NODE_MARKER_FILE = '/usr/bin/hypernode-systemctl';
    public const DEFAULT_ACCESS_LOG_PATH = '/var/log/nginx/access.log';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly FileDriver $fileDriver
    ) {
    }

    /**
     * Configured app name, else the one in the node's hostname (<id>-<app>-magweb-<x>), else ''
     */
    public function getAppName(): string
    {
        $configured = trim((string)$this->scopeConfig->getValue(self::XML_PATH_APP_NAME));
        if ($configured !== '') {
            return $configured;
        }

        $hostname = (string)gethostname();
        if (preg_match('/^[a-z0-9]+-(.+)-magweb(-|$)/i', $hostname, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Configured token, else the token file every Hypernode carries, else ''
     */
    public function getApiToken(): string
    {
        $configured = trim((string)$this->scopeConfig->getValue(self::XML_PATH_API_TOKEN));
        if ($configured !== '') {
            return trim($this->encryptor->decrypt($configured));
        }

        try {
            if ($this->fileDriver->isReadable(self::TOKEN_FILE)) {
                return trim($this->fileDriver->fileGetContents(self::TOKEN_FILE));
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }

    public function getAccessLogPath(): string
    {
        $configured = trim((string)$this->scopeConfig->getValue(self::XML_PATH_ACCESS_LOG_PATH));

        return $configured !== '' ? $configured : self::DEFAULT_ACCESS_LOG_PATH;
    }

    /**
     * Whether PHP runs on the Hypernode itself, so /proc and the access log describe the node
     */
    public function isOnHypernode(): bool
    {
        $setting = (string)$this->scopeConfig->getValue(self::XML_PATH_ON_NODE);
        if ($setting === self::ON_NODE_YES || $setting === self::ON_NODE_NO) {
            return $setting === self::ON_NODE_YES;
        }

        try {
            if ($this->fileDriver->isExists(self::TOKEN_FILE) || $this->fileDriver->isExists(self::NODE_MARKER_FILE)) {
                return true;
            }
        } catch (\Throwable $e) {
            return false;
        }

        $appName = $this->getAppName();

        return $appName !== '' && str_contains(strtolower((string)gethostname()), strtolower($appName));
    }

    public function isApiConfigured(): bool
    {
        return $this->getAppName() !== '' && $this->getApiToken() !== '';
    }

    /**
     * The one sentence every action answers with when the API cannot be used
     */
    public function getNotConfiguredMessage(): string
    {
        return 'The Hypernode API is not configured: set the app name and API token under '
            . 'Stores > Configuration > Mago Assistant > Hypernode, or run Magento on the Hypernode itself '
            . '(the token is then read from ' . self::TOKEN_FILE . ').';
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;
use MagoAssistant\Mago\Service\Usage\CacheShare;
use MagoAssistant\Mago\Service\Usage\UsageStats;

class Dashboard extends Template
{
    protected $_template = 'MagoAssistant_Mago::dashboard/index.phtml';

    public function __construct(
        Context $context,
        private readonly UsageStats $usageStats,
        private readonly ConfigRepository $configRepository,
        private readonly CacheShare $cacheShare,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getAccentColor(): string
    {
        return $this->configRepository->getAccentColor();
    }

    public function getPeriod(): string
    {
        return $this->getRequest()->getParam('period', '30days');
    }

    public function getStats(): array
    {
        return [
            'today' => $this->usageStats->getForPeriod('today'),
            'week' => $this->usageStats->getForPeriod('7days'),
            'month' => $this->usageStats->getForPeriod('30days'),
        ];
    }

    public function getUserStats(): array
    {
        return $this->usageStats->getUserBreakdown($this->getPeriod());
    }

    public function getSkillStats(): array
    {
        return $this->usageStats->getSkillBreakdown($this->getPeriod());
    }

    public function getDailyTrend(): array
    {
        $period = $this->getPeriod();
        if ($period === 'today') {
            return $this->usageStats->getHourlyTrend();
        }
        $days = match ($period) {
            '7days' => 7,
            '30days' => 30,
            'all' => 90,
            default => 30,
        };
        return $this->usageStats->getDailyTrend($days);
    }

    public function formatTokens(int $tokens): string
    {
        if ($tokens >= 1000000) {
            return round($tokens / 1000000, 1) . 'M';
        }
        if ($tokens >= 1000) {
            return round($tokens / 1000, 1) . 'K';
        }
        return (string)$tokens;
    }

    /**
     * @param array<string, mixed> $stats One period from getStats()
     */
    public function formatCacheShare(array $stats): string
    {
        return $this->cacheShare->format(
            $stats['total_cache_read_tokens'] ?? null,
            $stats['total_cache_reported_input_tokens'] ?? null
        );
    }

    public function formatCost(float $cost): string
    {
        return '$' . number_format($cost, 2);
    }
}

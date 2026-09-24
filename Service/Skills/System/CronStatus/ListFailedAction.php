<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\System\CronStatus;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\PeriodParser;

class ListFailedAction implements ActionInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly PeriodParser $periodParser
    ) {
    }

    public function getName(): string
    {
        return 'list_failed';
    }

    public function getDescription(): string
    {
        return 'Recently failed or missed cron jobs with error messages';
    }

    public function getParameterSchema(): array
    {
        return [
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results to return (default: 20)',
            ],
            'since' => [
                'type' => 'string',
                'description' => 'Period filter: "today", "yesterday", "7days", "30days" (default: last 24 hours)',
            ],
            'job_code' => [
                'type' => 'string',
                'description' => 'Filter by specific job code',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'schedule_id' => [PiiClass::PUBLIC],
            'job_code' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'messages' => [PiiClass::PUBLIC],
            'scheduled_at' => [PiiClass::PUBLIC],
            'executed_at' => [PiiClass::PUBLIC],
            'finished_at' => [PiiClass::PUBLIC],
            'count' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $limit = (int)($params['limit'] ?? 20);
        $since = $params['since'] ?? '';
        $jobCode = $params['job_code'] ?? '';

        if ($since) {
            [$from, $to] = $this->periodParser->parse($since);
        } else {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $from = $now->modify('-24 hours')->format('Y-m-d H:i:s');
            $to = $now->format('Y-m-d H:i:s');
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => ['error', 'missed']]);
        $collection->addFieldToFilter('scheduled_at', ['gteq' => $from]);
        $collection->addFieldToFilter('scheduled_at', ['lteq' => $to]);
        $collection->setOrder('scheduled_at', 'DESC');
        $collection->setPageSize($limit);

        if ($jobCode) {
            $collection->addFieldToFilter('job_code', $jobCode);
        }

        $jobs = [];
        foreach ($collection as $schedule) {
            $jobs[] = [
                'schedule_id' => (int)$schedule->getData('schedule_id'),
                'job_code' => $schedule->getData('job_code'),
                'status' => $schedule->getData('status'),
                'messages' => $schedule->getData('messages'),
                'scheduled_at' => $schedule->getData('scheduled_at'),
                'executed_at' => $schedule->getData('executed_at'),
                'finished_at' => $schedule->getData('finished_at'),
            ];
        }

        return ['failed_jobs' => $jobs, 'count' => count($jobs)];
    }
}

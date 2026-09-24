<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\System\CronStatus;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use Magento\Cron\Model\Schedule;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class ListScheduleAction implements ActionInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getName(): string
    {
        return 'list_schedule';
    }

    public function getDescription(): string
    {
        return 'Upcoming scheduled (pending) cron jobs';
    }

    public function getParameterSchema(): array
    {
        return [
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results to return (default: 20)',
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
            'scheduled_at' => [PiiClass::PUBLIC],
            'created_at' => [PiiClass::PUBLIC],
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
        $jobCode = $params['job_code'] ?? '';

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', Schedule::STATUS_PENDING);
        $collection->addFieldToFilter('scheduled_at', ['gteq' => $now]);
        $collection->setOrder('scheduled_at', 'ASC');
        $collection->setPageSize($limit);

        if ($jobCode) {
            $collection->addFieldToFilter('job_code', $jobCode);
        }

        $jobs = [];
        foreach ($collection as $schedule) {
            $jobs[] = [
                'schedule_id' => (int)$schedule->getData('schedule_id'),
                'job_code' => $schedule->getData('job_code'),
                'scheduled_at' => $schedule->getData('scheduled_at'),
                'created_at' => $schedule->getData('created_at'),
            ];
        }

        return ['scheduled_jobs' => $jobs, 'count' => count($jobs)];
    }
}

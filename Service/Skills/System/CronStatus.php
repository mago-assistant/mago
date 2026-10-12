<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\System;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class CronStatus extends AbstractSkill
{
    public function getName(): string
    {
        return 'cron_status';
    }

    /**
     * Cron has no admin screen of its own, so there is no resource to derive. Reading the schedule
     * and queueing a job are developer-facing, like the module version lookup, so this shares its
     * resource (#148). Scheduling additionally asks for confirmation, as every write does.
     */
    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Config::dev';
    }

    protected function getBaseDescription(): string
    {
        return 'Monitor Magento cron jobs: check running, scheduled, and failed jobs, and perform health checks.';
    }

    protected function getBaseInstructions(): string
    {
        return 'Cron is critical for Magento — indexers, catalog price rules, email sending, and many other '
            . 'processes depend on it. A "running" job older than 1 hour is likely stuck. '
            . 'When reporting issues, always mention the job_code, scheduled_at, and error message if available. '
            . 'If health_check shows no recent success jobs, cron is likely not configured or broken.';
    }
}

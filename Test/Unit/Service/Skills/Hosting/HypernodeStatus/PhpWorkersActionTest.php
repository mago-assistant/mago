<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Hosting\HypernodeStatus;

use MagoAssistant\Mago\Service\Hypernode\FpmStatusParser;
use MagoAssistant\Mago\Service\Skills\Hosting\HypernodeStatus\PhpWorkersAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeHypernodeApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PhpWorkersActionTest extends TestCase
{
    #[Test]
    public function itSummarizesTheWorkersTheApiReports(): void
    {
        $client = (new FakeHypernodeApiClient())->withFpmStatus(
            "1 IDLE 0.0s - phpfpm 127.0.0.1 GET magweb/status.php (heartbeat)\n"
            . "2 RUNNING 3.0s NL 1.2.3.4 GET /slow/ (Mozilla)\n"
        );

        $result = (new PhpWorkersAction($client, new FpmStatusParser()))->execute([], 1);

        self::assertSame(2, $result['total_workers']);
        self::assertSame(1, $result['active_workers']);
        self::assertSame('/slow/', $result['active_requests'][0]['path']);
    }

    #[Test]
    public function itAnswersWithTheApiErrorInsteadOfThrowing(): void
    {
        $client = (new FakeHypernodeApiClient())->failingWith('The Hypernode API rejected the token (HTTP 401).');

        $result = (new PhpWorkersAction($client, new FpmStatusParser()))->execute([], 1);

        self::assertSame('The Hypernode API rejected the token (HTTP 401).', $result['error']);
    }
}

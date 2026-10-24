<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Conversation;

use MagoAssistant\Mago\Model\Conversation\ConfirmRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfirmRequestTest extends TestCase
{
    #[Test]
    public function itReadsTheProposalTheAdminConfirms(): void
    {
        $request = ConfirmRequest::fromPostData(['message_id' => '42']);

        self::assertSame(42, $request->messageId);
    }

    #[Test]
    public function itCountsATickedAcknowledgement(): void
    {
        $request = ConfirmRequest::fromPostData(['message_id' => 42, 'acknowledged' => true]);

        self::assertTrue($request->acknowledged);
    }

    #[Test]
    public function itTreatsAMissingAcknowledgementAsNotTicked(): void
    {
        $request = ConfirmRequest::fromPostData(['message_id' => 42]);

        self::assertFalse($request->acknowledged);
    }

    #[Test]
    public function itCountsOnlyALiteralTrueAsTicked(): void
    {
        $values = ['1', 'true', 1, 'yes'];

        $acknowledged = array_map(
            static fn (mixed $value): bool => ConfirmRequest::fromPostData(['acknowledged' => $value])->acknowledged,
            $values
        );

        self::assertSame([false, false, false, false], $acknowledged);
    }

    #[Test]
    public function itRunsEveryCallWhenNoTickedIdsAreSent(): void
    {
        $request = ConfirmRequest::fromPostData(['message_id' => 42]);

        self::assertNull($request->selectedIds);
    }

    #[Test]
    public function itReadsTheTickedIdsOfABulkCardAsStrings(): void
    {
        $request = ConfirmRequest::fromPostData(['message_id' => 42, 'tool_call_ids' => ['call_1', 0]]);

        self::assertSame(['call_1', '0'], $request->selectedIds);
    }

    #[Test]
    public function itIgnoresTickedIdsThatAreNotAList(): void
    {
        $request = ConfirmRequest::fromPostData(['message_id' => 42, 'tool_call_ids' => 'call_1']);

        self::assertNull($request->selectedIds);
    }
}

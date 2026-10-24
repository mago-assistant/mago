<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Console\Command;

use MagoAssistant\Mago\Console\Command\VerifyTool;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Service\Tool\Verify\AclResourceIndex;
use MagoAssistant\Mago\Service\Tool\Verify\ToolVerifier;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclResourceProvider;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAdminArea;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeTool;
use MagoAssistant\Mago\Service\Acl\ToolAccess;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePermissionChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class VerifyToolTest extends TestCase
{
    private const ACL = 'Magento_Sales::actions_view';

    private FakeAdminArea $adminArea;

    protected function setUp(): void
    {
        $this->adminArea = new FakeAdminArea();
    }

    #[Test]
    public function itPrintsWhatTheModelSeesButNotTheRawResult(): void
    {
        $tester = $this->tester($this->lookupTool());

        $exitCode = $tester->execute(['tool' => 'order_lookup', 'params' => '{"action": "find"}']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->adminArea->isEntered());
        self::assertStringContainsString('What the model sees:', $tester->getDisplay());
        self::assertStringContainsString('"status": "pending"', $tester->getDisplay());
        self::assertStringNotContainsString('jan@example.com', $tester->getDisplay());
    }

    #[Test]
    public function itPrintsTheRawResultOnlyWhenAsked(): void
    {
        $tester = $this->tester($this->lookupTool());

        $tester->execute(['tool' => 'order_lookup', 'params' => '{"action": "find"}', '--show-raw' => true]);

        self::assertStringContainsString('jan@example.com', $tester->getDisplay());
    }

    #[Test]
    public function itFailsWhenAReturnedFieldIsUndeclared(): void
    {
        $tool = $this->lookupTool()->withFieldClassification(['status' => [PiiClass::PUBLIC]]);
        $tester = $this->tester($tool);

        $exitCode = $tester->execute(['tool' => 'order_lookup', 'params' => '{"action": "find"}']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('[fail] Undeclared fields, stripped before the model sees them: email', $tester->getDisplay());
    }

    #[Test]
    public function itSkipsACallThatWritesUnlessAllowed(): void
    {
        $tester = $this->tester($this->lookupTool());

        $tester->execute(['tool' => 'order_lookup', 'params' => '{"action": "cancel"}']);

        self::assertStringContainsString('Skipped execute(): this call writes.', $tester->getDisplay());
        self::assertStringNotContainsString('What the model sees:', $tester->getDisplay());
    }

    #[Test]
    public function itRunsACallThatWritesWhenAllowed(): void
    {
        $tester = $this->tester($this->lookupTool());

        $tester->execute(['tool' => 'order_lookup', 'params' => '{"action": "cancel"}', '--allow-write' => true]);

        self::assertStringContainsString('What the model sees:', $tester->getDisplay());
    }

    #[Test]
    public function itFailsForAToolTheRegistryDoesNotHave(): void
    {
        $tester = $this->tester($this->lookupTool());

        $exitCode = $tester->execute(['tool' => 'order_lokup']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No tool "order_lokup" in the ToolRegistry', $tester->getDisplay());
    }

    #[Test]
    public function itRejectsParamsThatAreNotAJsonObject(): void
    {
        $tester = $this->tester($this->lookupTool());

        $exitCode = $tester->execute(['tool' => 'order_lookup', 'params' => '["find"]']);

        self::assertSame(Command::INVALID, $exitCode);
    }

    private function lookupTool(): FakeTool
    {
        return (new FakeTool('order_lookup', ['find', 'cancel'], ['find'], self::ACL))
            ->withResult(['status' => 'pending', 'email' => 'jan@example.com'])
            ->withFieldClassification(['status' => [PiiClass::PUBLIC], 'email' => [PiiClass::STRIP]]);
    }

    private function tester(FakeTool $tool): CommandTester
    {
        return new CommandTester(new VerifyTool(
            new ToolRegistry(null, [$tool]),
            new ToolVerifier(
                new PrivacyFilter(new ConversationVault(), new PiiHeuristic()),
                new AclResourceIndex(FakeAclResourceProvider::withResources(self::ACL)),
                new ToolAccess(new FakeAuthorization(), new FakePermissionChecker())
            ),
            $this->adminArea
        ));
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Tool\Verify;

use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Api\Acl;
use MagoAssistant\Mago\Service\Tool\Verify\AclResourceIndex;
use MagoAssistant\Mago\Service\Tool\Verify\CheckStatus;
use MagoAssistant\Mago\Service\Tool\Verify\ToolCheck;
use MagoAssistant\Mago\Service\Tool\Verify\ToolVerifier;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclResourceProvider;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeTool;
use MagoAssistant\Mago\Service\Acl\ToolAccess;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePermissionChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolVerifierTest extends TestCase
{
    private const ACL = 'Magento_Sales::actions_view';
    private const CLASSES = ['valid' => [PiiClass::PUBLIC], 'order_id' => [PiiClass::TOKENISE, 'order']];

    #[Test]
    public function itPassesAToolThatDeclaresEverything(): void
    {
        $checks = $this->verifier()->inspect($this->tool(), ['action' => 'check']);

        self::assertSame([], $this->messagesWith(CheckStatus::Failure, $checks));
        self::assertSame([], $this->messagesWith(CheckStatus::Warning, $checks));
    }

    /**
     * config_reader and config_writer take their resource from the path, which a check without
     * input cannot see (#223)
     */
    #[Test]
    public function itNotesThatACheckWithoutInputMissesTheResourceARealCallNeeds(): void
    {
        $checks = $this->verifier()->inspect($this->tool(), []);

        self::assertStringContainsString(
            'pass the call parameters',
            implode("\n", $this->messagesWith(CheckStatus::Pass, $checks))
        );
        self::assertSame([], $this->messagesWith(CheckStatus::Warning, $checks));
    }

    #[Test]
    public function itFailsANameThatProvidersReject(): void
    {
        $tool = new FakeTool('Vies-Check', ['check'], ['check'], self::ACL);

        $checks = $this->verifier()->inspect($tool->withFieldClassification(self::CLASSES), []);

        self::assertStringContainsString('Vies-Check', $this->messagesWith(CheckStatus::Failure, $checks)[0]);
    }

    #[Test]
    public function itFailsAnEmptyDescription(): void
    {
        $checks = $this->verifier()->inspect($this->tool()->withDescription(' '), ['action' => 'check']);

        self::assertSame(
            ['Description is empty: the model picks tools by their description'],
            $this->messagesWith(CheckStatus::Failure, $checks)
        );
    }

    #[Test]
    public function itFailsASchemaThatIsNotAnObjectSchema(): void
    {
        $checks = $this->verifier()->inspect($this->tool()->withParameterSchema(['properties' => []]), ['action' => 'check']);

        self::assertSame(
            ['Parameter schema must have "type": "object"'],
            $this->messagesWith(CheckStatus::Failure, $checks)
        );
    }

    #[Test]
    public function itFailsWhenTheToolHasNoAclResource(): void
    {
        $tool = (new FakeTool('vies_check', ['check'], ['check'], ''))->withFieldClassification(self::CLASSES);

        $checks = $this->verifier()->inspect($tool, []);

        $failure = $this->messagesWith(CheckStatus::Failure, $checks)[0];
        self::assertStringContainsString('No ACL resource', $failure);
        self::assertStringContainsString('refused for everyone', $failure);
        self::assertStringContainsString('Acl::MAGO_PER_USER', $failure);
    }

    /**
     * A tool that touches no Magento data says so with the per-user sentinel; that is a complete
     * declaration, not a missing one, and acl.xml is not expected to know it.
     */
    #[Test]
    public function itPassesAToolGatedPerUser(): void
    {
        $tool = (new FakeTool('issue_tracker', ['list'], ['list'], Acl::MAGO_PER_USER))
            ->withFieldClassification(self::CLASSES);

        $checks = $this->verifier()->inspect($tool, ['action' => 'list']);

        self::assertSame([], $this->messagesWith(CheckStatus::Failure, $checks));
        self::assertStringContainsString('Gated per user', implode("\n", $this->messagesWith(CheckStatus::Pass, $checks)));
    }

    #[Test]
    public function itFailsAnAclResourceNoAclXmlDeclares(): void
    {
        $tool = new FakeTool('vies_check', ['check'], ['check'], 'Magento_Sales::action_view');

        $checks = $this->verifier()->inspect($tool->withFieldClassification(self::CLASSES), []);

        self::assertStringContainsString(
            '"Magento_Sales::action_view" is not declared',
            $this->messagesWith(CheckStatus::Failure, $checks)[0]
        );
    }

    #[Test]
    public function itFailsAReadOnlyToolWhoseCallWrites(): void
    {
        $checks = $this->verifier()->inspect($this->tool(), ['action' => 'cancel']);

        self::assertSame(
            ['isReadOnly() is true but isReadOnlyAction() says this call writes'],
            $this->messagesWith(CheckStatus::Failure, $checks)
        );
    }

    #[Test]
    public function itFailsRulesThatAreNotValidPiiClasses(): void
    {
        $tool = $this->tool()->withFieldClassification([
            'valid' => ['public-ish'],
            'order_id' => [PiiClass::TOKENISE],
        ]);

        $failures = $this->messagesWith(CheckStatus::Failure, $this->verifier()->inspect($tool, ['action' => 'check']));

        self::assertCount(2, $failures);
        self::assertStringContainsString('"valid" needs a PiiClass rule', $failures[0]);
        self::assertStringContainsString('"order_id" tokenises without a token type', $failures[1]);
    }

    #[Test]
    public function itWarnsWhenTheClassificationIsEmpty(): void
    {
        $checks = $this->verifier()->inspect($this->tool()->withFieldClassification([]), []);

        self::assertSame(
            ['Field classification is empty: the model only ever sees "error"'],
            $this->messagesWith(CheckStatus::Warning, $checks)
        );
    }

    #[Test]
    public function aRunShowsWhatTheModelSeesAndFailsOnUndeclaredFields(): void
    {
        $tool = $this->tool()->withResult(['valid' => true, 'email' => 'jan@example.com']);

        $run = $this->verifier()->run($tool, ['action' => 'check']);

        self::assertSame(['valid' => true, 'email' => 'jan@example.com'], $run->rawResult);
        self::assertSame(['valid' => true], $run->modelView);
        self::assertSame(
            ['Undeclared fields, stripped before the model sees them: email'],
            $this->messagesWith(CheckStatus::Failure, $run->checks)
        );
    }

    #[Test]
    public function aRunPassesWhenEveryFieldIsClassified(): void
    {
        $tool = $this->tool()->withResult(['valid' => true, 'order_id' => '000001234']);

        $run = $this->verifier()->run($tool, ['action' => 'check']);

        self::assertSame(['valid' => true, 'order_id' => 'mago://order_1'], $run->modelView);
        self::assertSame([], $this->messagesWith(CheckStatus::Failure, $run->checks));
    }

    #[Test]
    public function aRunWarnsWhenTheToolReturnsAnError(): void
    {
        $tool = $this->tool()->withResult(['error' => 'VIES is unreachable']);

        $run = $this->verifier()->run($tool, ['action' => 'check']);

        self::assertSame(
            ['The tool returned an error: VIES is unreachable'],
            $this->messagesWith(CheckStatus::Warning, $run->checks)
        );
    }

    private function verifier(): ToolVerifier
    {
        return new ToolVerifier(
            new PrivacyFilter(new ConversationVault(), new PiiHeuristic()),
            new AclResourceIndex(FakeAclResourceProvider::withResources(self::ACL)),
            new ToolAccess(new FakeAuthorization(), new FakePermissionChecker())
        );
    }

    private function tool(): FakeTool
    {
        return (new FakeTool('vies_check', ['check'], ['check'], self::ACL))->withFieldClassification(self::CLASSES);
    }

    /**
     * @param ToolCheck[] $checks
     * @return string[]
     */
    private function messagesWith(CheckStatus $status, array $checks): array
    {
        return array_values(array_map(
            static fn (ToolCheck $check) => $check->message,
            array_filter($checks, static fn (ToolCheck $check) => $check->status === $status)
        ));
    }
}

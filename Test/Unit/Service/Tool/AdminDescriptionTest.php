<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Tool;

use MagoAssistant\Mago\Service\Tool\AdminDescription;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAdminDescribedTool;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeSkill;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #304: the Skills & Permissions page shows an admin text, not the model description
 */
final class AdminDescriptionTest extends TestCase
{
    private AdminDescription $adminDescription;

    protected function setUp(): void
    {
        $this->adminDescription = new AdminDescription();
    }

    #[Test]
    public function itReturnsTheAdminDescriptionOfAToolThatDeclaresOne(): void
    {
        $tool = new FakeAdminDescribedTool(
            'rumvision',
            'Query RUMvision. Actions: "get_vitals" (...), "get_pages" (...).',
            'Core Web Vitals from RUMvision.'
        );

        $description = $this->adminDescription->of($tool);

        self::assertSame('Core Web Vitals from RUMvision.', $description);
    }

    #[Test]
    public function itFallsBackToTheFirstSentenceWhenTheAdminDescriptionIsEmpty(): void
    {
        $tool = new FakeAdminDescribedTool('rumvision', 'Query RUMvision. Use get_vitals first.', '');

        $description = $this->adminDescription->of($tool);

        self::assertSame('Query RUMvision.', $description);
    }

    #[Test]
    public function itFallsBackToTheFirstSentenceWhenTheAdminDescriptionIsOnlyWhitespace(): void
    {
        $tool = new FakeAdminDescribedTool('rumvision', 'Query RUMvision. Use get_vitals first.', '   ');

        $description = $this->adminDescription->of($tool);

        self::assertSame('Query RUMvision.', $description);
    }

    #[Test]
    public function itShowsOnlyTheFirstSentenceOfAnActionScopedSkill(): void
    {
        $skill = new FakeSkill('cms_data', new FakeAuthorization(), [
            'list_pages' => new FakeAction('list_pages', true),
            'update_page' => new FakeAction('update_page', false),
        ]);

        $description = $this->adminDescription->of($skill);

        self::assertSame('Fake cms_data.', $description);
    }

    #[Test]
    public function itCutsTheActionListOfAToolWithoutASentenceBreak(): void
    {
        $tool = (new FakeTool('cache_manager', [], []))
            ->withDescription('Manage caches Actions: "flush" (flush all caches).');

        $description = $this->adminDescription->of($tool);

        self::assertSame('Manage caches', $description);
    }

    #[Test]
    public function itKeepsAnAbbreviationInsideTheFirstSentence(): void
    {
        $tool = (new FakeTool('config_reader', [], []))
            ->withDescription('Read a path (e.g. web/unsecure/base_url) or i.e. a value. Blocked: secrets.');

        $description = $this->adminDescription->of($tool);

        self::assertSame('Read a path (e.g. web/unsecure/base_url) or i.e. a value.', $description);
    }

    #[Test]
    public function itDoesNotNeedAnUppercaseStartForTheSecondSentence(): void
    {
        $tool = (new FakeTool('dead_stock', [], []))
            ->withDescription('Find dead stock among products. dead_stock reads the sales history.');

        $description = $this->adminDescription->of($tool);

        self::assertSame('Find dead stock among products.', $description);
    }

    #[Test]
    public function itKeepsADecimalNumberInsideTheFirstSentence(): void
    {
        $tool = (new FakeTool('scorer', [], []))
            ->withDescription('Flags pages scoring below 2.5 seconds! Pass "url" to narrow it down.');

        $description = $this->adminDescription->of($tool);

        self::assertSame('Flags pages scoring below 2.5 seconds!', $description);
    }

    #[Test]
    public function itReturnsTheWholeTextWhenThereIsNoSentenceBreak(): void
    {
        $tool = (new FakeTool('product_data', [], []))->withDescription('Query product catalog data');

        $description = $this->adminDescription->of($tool);

        self::assertSame('Query product catalog data', $description);
    }

    #[Test]
    public function itReturnsAnEmptyStringForAnEmptyDescription(): void
    {
        $tool = (new FakeTool('silent', [], []))->withDescription('');

        $description = $this->adminDescription->of($tool);

        self::assertSame('', $description);
    }
}

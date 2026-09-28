<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Ai;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Ai\AnswerWidgets;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnswerWidgetsTest extends TestCase
{
    /**
     * Every type the guide lists must exist in mago-ui.js, otherwise the model is taught a
     * widget the panel cannot draw. Kept in step by hand; this test guards the list.
     */
    private const PANEL_TYPES = [
        'stat', 'stats', 'sparkline', 'meter', 'ring', 'composition', 'rankedBars', 'columns', 'lines',
        'stackedColumns', 'heatmap', 'funnel', 'entityList', 'table', 'record', 'confirmWrite', 'toolTrace',
        'callout', 'suggestions', 'answerFooter', 'empty', 'skeleton', 'skillAsk', 'skillRunning', 'skillLine',
        'skillFailed', 'readLine', 'skillIrreversible', 'skillBulk', 'skillPlan', 'paramPrompt', 'undoCallout',
        'sessionLog', 'skillMenu',
    ];

    #[Test]
    public function itOpensWithTheMarkerAndNamesTheFence(): void
    {
        $section = $this->answerWidgets()->toPromptSection();

        self::assertStringStartsWith(AnswerWidgets::MARKER . ' ', $section);
        self::assertStringContainsString('language "mago"', $section);
        self::assertStringContainsString('```mago', $section);
    }

    #[Test]
    public function itOnlyTeachesTypesThePanelCanRender(): void
    {
        $guide = $this->answerWidgets();

        self::assertNotEmpty($guide->getTypes());
        foreach ($guide->getTypes() as $type) {
            self::assertContains($type, self::PANEL_TYPES, "Unknown widget type {$type}");
            self::assertStringContainsString('{"type":"' . $type . '"', $guide->toPromptSection());
        }
    }

    #[Test]
    public function itLeavesInteractiveCardsToThePanel(): void
    {
        $types = $this->answerWidgets()->getTypes();

        $cards = ['skillAsk', 'skillRunning', 'skillBulk', 'skillIrreversible', 'skillPlan', 'confirmWrite'];
        foreach ($cards as $card) {
            self::assertNotContains($card, $types);
        }
    }

    #[Test]
    public function everyExampleShapeIsValidJson(): void
    {
        $section = $this->answerWidgets()->toPromptSection();

        preg_match_all('/^- (\{.*?\})(?= — )/m', $section, $matches);

        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $shape) {
            // <stat> placeholders stand for a nested example, not literal JSON
            $shape = str_replace('<stat>', '{"type":"stat"}', $shape);
            self::assertIsArray(json_decode($shape, true), "Shape is not valid JSON: {$shape}");
        }
    }

    #[Test]
    public function aModuleCanTeachItsOwnWidget(): void
    {
        $guide = $this->answerWidgets([
            'stockAlert' => '{"type":"stockAlert","sku":"MH01","qty":2} — a product that is about to sell out.',
        ]);

        self::assertContains('stockAlert', $guide->getTypes());
        self::assertStringContainsString(
            '- {"type":"stockAlert","sku":"MH01","qty":2} — a product that is about to sell out.',
            $guide->toPromptSection()
        );
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function brokenWidgets(): array
    {
        return [
            'replaces a built-in' => ['stat', '{"type":"stat"} — mine.'],
            'type is not camelCase' => ['stock-alert', '{"type":"stock-alert"} — mine.'],
            'no guidance' => ['stockAlert', '{"type":"stockAlert"}'],
            'shape is not JSON' => ['stockAlert', '{type: stockAlert} — mine.'],
            'shape names another type' => ['stockAlert', '{"type":"other"} — mine.'],
            'not a string' => ['stockAlert', ['type' => 'stockAlert']],
        ];
    }

    #[Test]
    #[DataProvider('brokenWidgets')]
    public function aBrokenWidgetIsLeftOutAndLogged(string $type, mixed $entry): void
    {
        $logger = new FakeLogger();

        $guide = $this->answerWidgets([$type => $entry], $logger);

        self::assertSame($this->answerWidgets()->getTypes(), $guide->getTypes());
        self::assertSame($this->answerWidgets()->toPromptSection(), $guide->toPromptSection());
        self::assertCount(1, $logger->getMessages());
        self::assertStringStartsWith('AnswerWidgets: ', $logger->getMessages()[0]);
    }

    #[Test]
    public function aValidWidgetLogsNothing(): void
    {
        $logger = new FakeLogger();

        $this->answerWidgets(['stockAlert' => '{"type":"stockAlert"} — a product about to sell out.'], $logger);

        self::assertSame([], $logger->getMessages());
    }

    #[Test]
    public function itTellsTheModelToUseToolDataOnly(): void
    {
        $section = $this->answerWidgets()->toPromptSection();

        self::assertStringContainsString('never invent', $section);
        self::assertStringContainsString('never put HTML', $section);
    }

    /**
     * Admin urls reach the model as tokens (mago://url_1) and only work with their secret key, so
     * the guide must never show or ask for a hand-written admin path.
     */
    #[Test]
    public function itOnlyLinksToUrlsAToolReturned(): void
    {
        $section = $this->answerWidgets()->toPromptSection();

        self::assertStringContainsString('"href":"mago://url_1"', $section);
        self::assertStringContainsString('never write or assemble an admin path yourself', $section);
        self::assertStringNotContainsString('"/admin/', $section);
    }

    #[Test]
    public function itMapsQuestionsToWidgetsItTeaches(): void
    {
        $guide = $this->answerWidgets();
        $section = $guide->toPromptSection();

        self::assertStringContainsString('Pick the widget by the question:', $section);
        preg_match_all('/→ (.*)$/m', $section, $matches);
        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $choice) {
            preg_match_all('/"([a-zA-Z]+)"/', $choice, $types);
            foreach ($types[1] as $type) {
                self::assertContains($type, $guide->getTypes(), "Choice names unknown type {$type}");
            }
        }
    }

    #[Test]
    public function theToolReminderPointsAtTheGuide(): void
    {
        $reminder = $this->answerWidgets()->toToolReminder();

        self::assertStringContainsString('```mago', $reminder);
        self::assertStringContainsString(AnswerWidgets::MARKER, $reminder);
    }

    /**
     * @param array<string, mixed> $widgets
     */
    private function answerWidgets(array $widgets = [], ?FakeLogger $logger = null): AnswerWidgets
    {
        return new AnswerWidgets(new ErrorLogger($logger ?? new FakeLogger(), new Json()), $widgets);
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Block\Adminhtml;

use MagoAssistant\Mago\Block\Adminhtml\ConversationView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConversationViewTest extends TestCase
{
    private const SCRIPT_BREAKOUT = '</script><script>alert(1)</script>';

    #[Test]
    public function itEscapesAClosingScriptTagInsideValidJsonForScriptOutput(): void
    {
        $payload = json_encode(['review' => self::SCRIPT_BREAKOUT, 'nested' => '{"title":"</script>"}']);

        $output = $this->block()->formatJsonForScript($payload);

        self::assertStringNotContainsStringIgnoringCase('</script', $output);
        self::assertSame(
            ['review' => self::SCRIPT_BREAKOUT, 'nested' => ['title' => '</script>']],
            json_decode($output, true)
        );
    }

    #[Test]
    public function itEncodesInvalidJsonAsAJsonStringForScriptOutput(): void
    {
        $payload = 'not json ' . self::SCRIPT_BREAKOUT;

        $output = $this->block()->formatJsonForScript($payload);

        self::assertStringNotContainsStringIgnoringCase('</script', $output);
        self::assertSame($payload, json_decode($output, true));
    }

    #[Test]
    public function itEscapesHtmlSpecialCharactersForScriptOutput(): void
    {
        $output = $this->block()->formatJsonForScript(['value' => '<!-- & \' "']);

        self::assertStringNotContainsString('<', $output);
        self::assertStringNotContainsString('&', $output);
        self::assertStringNotContainsString("'", $output);
        self::assertSame(['value' => '<!-- & \' "'], json_decode($output, true));
    }

    #[Test]
    public function itProducesValidJsonForScriptOutputWhenThePayloadHasInvalidUtf8(): void
    {
        $output = $this->block()->formatJsonForScript(['value' => "broken \xB1 byte"]);

        self::assertIsArray(json_decode($output, true));
    }

    #[Test]
    public function itLeavesAnEmptyPayloadEmptyForScriptOutput(): void
    {
        self::assertSame('', $this->block()->formatJsonForScript(''));
        self::assertSame('', $this->block()->formatJsonForScript(null));
    }

    #[Test]
    public function itKeepsReadableJsonForEscapedOutput(): void
    {
        $output = $this->block()->formatJson('{"url":"https://example.com/a","name":"Café"}');

        self::assertSame("{\n    \"url\": \"https://example.com/a\",\n    \"name\": \"Café\"\n}", $output);
    }

    #[Test]
    public function itPassesInvalidJsonThroughForEscapedOutput(): void
    {
        self::assertSame('plain text result', $this->block()->formatJson('plain text result'));
    }

    /**
     * The JSON formatters use no constructor dependencies.
     */
    private function block(): ConversationView
    {
        return (new \ReflectionClass(ConversationView::class))->newInstanceWithoutConstructor();
    }
}

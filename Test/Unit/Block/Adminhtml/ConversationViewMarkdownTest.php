<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Block\Adminhtml;

use Magento\Framework\Escaper;
use MagoAssistant\Mago\Block\Adminhtml\ConversationView;
use PHPUnit\Framework\TestCase;

class ConversationViewMarkdownTest extends TestCase
{
    public function testUrlInsideALinkTargetIsNotLinkedAgain(): void
    {
        $html = $this->block()->renderMarkdown(
            'See [order](/x?u=https://e/onmouseover=alert&#40;1&#41;//)'
        );

        $this->assertSame(1, substr_count($html, '<a '));
        $this->assertStringContainsString('href="/x?u=https://e/onmouseover=alert&#40;1&#41;//"', $html);
    }

    public function testBareUrlIsStillLinked(): void
    {
        $this->assertSame(
            '<p>Go to <a href="https://example.com/a" target="_blank" rel="noopener noreferrer">https://example.com/a</a></p>',
            $this->block()->renderMarkdown('Go to https://example.com/a')
        );
    }

    private function block(): ConversationView
    {
        $reflection = new \ReflectionClass(ConversationView::class);
        $block = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('escaper')->setValue($block, new Escaper());

        return $block;
    }
}

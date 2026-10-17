<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Form;

use MagoAssistant\Mago\Model\Form\PageContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageContextTest extends TestCase
{
    /**
     * The guidance sits at the top of the system prompt. If it ever depended on the page, the
     * start of every request would change on each navigation and a provider that caches a prompt
     * prefix would have nothing stable to reuse.
     */
    #[Test]
    public function theGuidanceDoesNotDependOnThePage(): void
    {
        $this->assertSame(PageContext::promptGuidance(), PageContext::promptGuidance());
        $this->assertStringNotContainsString('/admin/', PageContext::promptGuidance());
        $this->assertDoesNotMatchRegularExpression('/\d+ field\(s\)/', PageContext::promptGuidance());
    }

    #[Test]
    public function theGuidanceOpensWithItsMarker(): void
    {
        $this->assertStringStartsWith(PageContext::GUIDANCE_MARKER, PageContext::promptGuidance());
    }

    /**
     * Several skills can answer "update the description"; only page_form puts a value on the page.
     * Left to pick by name the model drafts prose about a form it is already looking at and changes
     * nothing, which is what happened before this hint existed.
     */
    #[Test]
    public function itPrefersTheFormToolWhileAFormIsOpen(): void
    {
        $this->assertStringContainsString('prefer page_form', PageContext::promptGuidance());
    }

    #[Test]
    public function itSaysThatATextOnlyToolLeavesTheFormUntouched(): void
    {
        $this->assertStringContainsString('leaves the form untouched', PageContext::promptGuidance());
    }

    /**
     * Without this a field missing only because the list was cut is indistinguishable from one the
     * form does not have, which invited telling the administrator the Description field did not
     * exist when it had simply been cut from the list.
     */
    #[Test]
    public function itTellsTheModelNotToClaimAFieldIsMissingWhenTheListWasTruncated(): void
    {
        $guidance = PageContext::promptGuidance();

        $this->assertStringContainsString('truncated', $guidance);
        $this->assertStringContainsString('do not tell the administrator a field is missing', $guidance);
    }

    #[Test]
    public function itExplainsThatANoteOnlyAppearsWhenThePageChanges(): void
    {
        $this->assertStringContainsString('only when the page changes', PageContext::promptGuidance());
    }

    #[Test]
    public function itConvertsToALocationWithoutTheFields(): void
    {
        $location = (new PageContext(
            route: '/admin/catalog/product/edit/id/1/',
            namespace: 'product_form',
            entityType: 'product',
            entityId: '1',
            isNewEntity: false,
            storeId: null,
            fields: [['path' => 'name']],
            fieldCount: 1
        ))->toLocation();

        $this->assertSame('product', $location->entityType);
        $this->assertSame('1', $location->entityId);
    }
}

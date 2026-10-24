<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Seo;

use MagoAssistant\Mago\Service\Skills\Seo\UrlRewriteManager;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeIrreversibleAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UrlRewriteManagerTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    /**
     * @param array<string, \MagoAssistant\Mago\Api\Skill\ActionInterface> $actions
     */
    private function manager(array $actions = []): UrlRewriteManager
    {
        return new UrlRewriteManager(new FakeAuthorization(), $actions);
    }

    #[Test]
    public function aRedirectToAnExternalHostIsIrreversibleAndNamesTheHost(): void
    {
        $manager = $this->manager();
        $input = ['action' => 'create', 'request_path' => 'checkout', 'target_path' => 'https://pay.example.net/x'];

        self::assertTrue($manager->isIrreversibleAction($input));

        $impacts = implode("\n", $manager->getImpacts($input, self::ADMIN_USER_ID));
        self::assertStringContainsString('pay.example.net', $impacts);
        self::assertStringContainsString('/checkout', $impacts);
    }

    #[Test]
    public function aProtocolRelativeTargetIsExternalToo(): void
    {
        self::assertTrue(
            $this->manager()->isIrreversibleAction(['action' => 'create', 'target_path' => '//evil.example/x'])
        );
    }

    #[Test]
    public function anInternalRedirectIsNotFlagged(): void
    {
        $manager = $this->manager();
        $input = ['action' => 'create', 'request_path' => 'old.html', 'target_path' => 'catalog/category/view/id/42'];

        self::assertFalse($manager->isIrreversibleAction($input));
        self::assertSame([], $manager->getImpacts($input, self::ADMIN_USER_ID));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function internalTargets(): array
    {
        return [
            'relative system path' => ['catalog/product/view/id/5'],
            'relative url key' => ['new-url.html'],
            'nested url key' => ['women/tops/shirt.html'],
            'leading slash' => ['/new-url.html'],
            'store root' => ['/'],
            'query string' => ['search?q=shoes'],
            'colon after the first segment' => ['blog/post:2024'],
            'colon in the query string' => ['search?time=10:30'],
            'absolute url as a later segment' => ['go/https://evil.example'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function externalTargets(): array
    {
        return [
            'https url' => ['https://evil.example/x'],
            'https without slashes' => ['https:evil.example'],
            'http with a single slash' => ['http:/evil.example'],
            'uppercase scheme' => ['HTTPS://evil.example'],
            'mixed case scheme' => ['HtTp://evil.example'],
            'protocol relative' => ['//evil.example'],
            'slash backslash' => ['/\\evil.example'],
            'double backslash' => ['\\\\evil.example'],
            'leading space' => [' https://evil.example'],
            'leading tab' => ["\thttps://evil.example"],
            'leading newline' => ["\nhttps://evil.example"],
            'leading null byte' => ["\0https://evil.example"],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,hi'],
            'other scheme' => ['ftp://evil.example'],
            'triple slash' => ['///evil.example'],
            'backslash later in the path' => ['foo\\..\\evil'],
            'whitespace only' => ['   '],
        ];
    }

    #[Test]
    #[DataProvider('internalTargets')]
    public function anInternalTargetDoesNotWarn(string $target): void
    {
        $manager = $this->manager();
        $input = ['action' => 'create', 'request_path' => 'old.html', 'target_path' => $target];

        $isIrreversible = $manager->isIrreversibleAction($input);

        self::assertFalse($isIrreversible);
        self::assertSame([], $manager->getImpacts($input, self::ADMIN_USER_ID));
    }

    #[Test]
    #[DataProvider('externalTargets')]
    public function anyTargetThatIsNotAPlainInternalPathWarns(string $target): void
    {
        $manager = $this->manager();
        $input = ['action' => 'create', 'request_path' => 'old.html', 'target_path' => $target];

        $isIrreversible = $manager->isIrreversibleAction($input);

        self::assertTrue($isIrreversible);
        self::assertNotSame([], $manager->getImpacts($input, self::ADMIN_USER_ID));
    }

    #[Test]
    public function anEmptyTargetDoesNotWarn(): void
    {
        $isIrreversible = $this->manager()->isIrreversibleAction(['action' => 'create', 'target_path' => '']);

        self::assertFalse($isIrreversible);
    }

    #[Test]
    public function itStillDefersToAnIrreversibleDeleteAction(): void
    {
        $manager = $this->manager(['delete' => new FakeIrreversibleAction('delete', ['The rewrite is gone.'])]);
        $input = ['action' => 'delete', 'url_rewrite_id' => 5];

        self::assertTrue($manager->isIrreversibleAction($input));
        self::assertSame(['The rewrite is gone.'], $manager->getImpacts($input, self::ADMIN_USER_ID));
    }
}

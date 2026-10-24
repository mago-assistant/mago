<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml;

use MagoAssistant\Mago\Controller\Adminhtml\Conversations\ContinueChat;
use MagoAssistant\Mago\Controller\Adminhtml\Conversations\Index as ConversationsIndex;
use MagoAssistant\Mago\Controller\Adminhtml\Conversations\View as ConversationsView;
use MagoAssistant\Mago\Controller\Adminhtml\Dashboard\Index as DashboardIndex;
use MagoAssistant\Mago\Controller\Adminhtml\Skills\Edit as SkillsEdit;
use MagoAssistant\Mago\Controller\Adminhtml\Skills\Index as SkillsIndex;
use MagoAssistant\Mago\Controller\Adminhtml\Skills\SavePermissions;
use MagoAssistant\Mago\Controller\Adminhtml\Statistics\Index as StatisticsIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each admin screen asks for its own resource (#195): holding the module configuration grant must
 * not open other admins' transcripts, the statistics or the per-user skill permissions.
 */
final class AdminResourceTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../../..';

    /**
     * @param class-string $controller
     */
    #[Test]
    #[DataProvider('screens')]
    public function eachScreenAsksForItsOwnResource(string $controller, string $resource): void
    {
        self::assertSame($resource, constant($controller . '::ADMIN_RESOURCE'));
    }

    #[Test]
    public function everyControllerResourceIsDeclaredInAclXml(): void
    {
        $declared = $this->declaredResources();

        foreach (glob(self::MODULE_DIR . '/Controller/Adminhtml/*/*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            $name = basename(dirname($file)) . '/' . basename($file);
            if (preg_match('/^trait /m', $source)) {
                continue;
            }

            // Without its own constant a controller falls back to Magento_Backend::admin: any admin
            self::assertSame(1, preg_match("/ADMIN_RESOURCE = '([^']+)'/", $source, $match), $name);
            self::assertContains($match[1], $declared, $name);
        }
    }

    /**
     * A menu item or grid on an undeclared resource is hidden from everyone but full admins, and a
     * grid on a broader one than its screen leaks its rows through mui/index/render.
     */
    #[Test]
    public function everyMenuAndGridResourceIsDeclaredInAclXml(): void
    {
        $declared = $this->declaredResources();
        $files = array_merge(
            [self::MODULE_DIR . '/etc/adminhtml/menu.xml'],
            glob(self::MODULE_DIR . '/view/adminhtml/ui_component/*.xml') ?: []
        );

        foreach ($files as $file) {
            preg_match_all('/resource="([^"]+)"|<aclResource>([^<]+)</', (string)file_get_contents($file), $matches);
            foreach ([...$matches[1], ...$matches[2]] as $resource) {
                if ($resource !== '') {
                    self::assertContains($resource, $declared, basename($file));
                }
            }
        }
    }

    #[Test]
    public function onlyTheConfigurationMenuItemStaysOnTheConfigResource(): void
    {
        $menu = simplexml_load_file(self::MODULE_DIR . '/etc/adminhtml/menu.xml');
        self::assertNotFalse($menu);

        $onConfig = [];
        foreach ($menu->xpath('//add') ?: [] as $item) {
            if ((string)$item['resource'] === 'MagoAssistant_Mago::config') {
                $onConfig[] = (string)$item['id'];
            }
        }

        self::assertSame(['MagoAssistant_Mago::configuration'], $onConfig);
    }

    /**
     * mui/index/render only checks a grid's own aclResource, so each grid needs the one its screen has.
     */
    #[Test]
    #[DataProvider('grids')]
    public function eachGridAsksForTheResourceOfItsScreen(string $listing, string $resource): void
    {
        $xml = simplexml_load_file(self::MODULE_DIR . '/view/adminhtml/ui_component/' . $listing . '.xml');
        self::assertNotFalse($xml);

        self::assertSame([$resource], array_map('strval', $xml->xpath('//dataSource/aclResource') ?: []));
    }

    #[Test]
    public function everyGridIsCovered(): void
    {
        $listings = array_map(
            static fn(string $file): string => basename($file, '.xml'),
            glob(self::MODULE_DIR . '/view/adminhtml/ui_component/*_listing.xml') ?: []
        );

        self::assertEqualsCanonicalizing($listings, array_keys(self::grids()));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function grids(): array
    {
        return [
            'mago_conversations_listing' => ['mago_conversations_listing', 'MagoAssistant_Mago::conversations'],
            'mago_flags_listing' => ['mago_flags_listing', 'MagoAssistant_Mago::conversations'],
            'mago_skills_listing' => ['mago_skills_listing', 'MagoAssistant_Mago::skills_read'],
        ];
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function screens(): array
    {
        return [
            'conversations grid' => [ConversationsIndex::class, 'MagoAssistant_Mago::conversations'],
            'conversation view' => [ConversationsView::class, 'MagoAssistant_Mago::conversations'],
            'continue chat' => [ContinueChat::class, 'MagoAssistant_Mago::conversations'],
            'dashboard' => [DashboardIndex::class, 'MagoAssistant_Mago::statistics'],
            'statistics' => [StatisticsIndex::class, 'MagoAssistant_Mago::statistics'],
            'skills grid' => [SkillsIndex::class, 'MagoAssistant_Mago::skills_read'],
            'skill edit' => [SkillsEdit::class, 'MagoAssistant_Mago::skills_read'],
            'save skill permissions' => [SavePermissions::class, 'MagoAssistant_Mago::skills_write'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function declaredResources(): array
    {
        $acl = (string)file_get_contents(self::MODULE_DIR . '/etc/acl.xml');
        preg_match_all('/<resource id="([^"]+)"/', $acl, $matches);

        return $matches[1];
    }
}

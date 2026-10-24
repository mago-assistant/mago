<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;
use MagoAssistant\Mago\Service\Skills\Docs\DocsSearch;
use MagoAssistant\Mago\Service\Skills\Form\PageForm;
use MagoAssistant\Mago\Service\Skills\System\CronStatus;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The skills with no admin screen to derive a resource from declare one by hand (#148). Pinned
 * here so none of them can drift back to the empty declaration, which is refused outright.
 *
 * @see \MagoAssistant\Mago\Service\Acl\ToolAccess
 */
final class DeclaredAclTest extends TestCase
{
    /**
     * @return array<string,array{0:class-string,1:string}>
     */
    public static function skills(): array
    {
        return [
            'cron has no screen, so it shares the developer resource' => [CronStatus::class, 'Magento_Config::dev'],
            "the assistant's own docs are open to any admin" => [DocsSearch::class, 'Magento_Backend::admin'],
            "the open form is the admin's own screen" => [PageForm::class, 'Magento_Backend::admin'],
        ];
    }

    #[Test]
    #[DataProvider('skills')]
    public function itDeclaresAResource(string $skillClass, string $resource): void
    {
        $skill = new $skillClass(new FakeAuthorization(), []);

        self::assertSame($resource, $skill->getMagentoAcl());
        self::assertSame($resource, $skill->getMagentoAcl(['action' => 'anything']));
    }

    /**
     * AbstractSkill's own getMagentoAcl() answers '', which is refused for everyone - so every
     * concrete skill in this module must override it, whether or not its actions declare their
     * own. Found by walking Service/Skills rather than listed, so a new skill cannot be left out.
     */
    #[Test]
    public function everyCoreSkillOverridesGetMagentoAcl(): void
    {
        $base = new \ReflectionClass(AbstractSkill::class);
        $skillsDir = dirname($base->getFileName());
        $relying = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($skillsDir)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($skillsDir) + 1, -4);
            $class = 'MagoAssistant\\Mago\\Service\\Skills\\' . str_replace('/', '\\', $relative);
            if (!class_exists($class) || !is_subclass_of($class, AbstractSkill::class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }
            if ($reflection->getMethod('getMagentoAcl')->getDeclaringClass()->getName() === AbstractSkill::class) {
                $relying[] = $class;
            }
        }

        self::assertSame([], $relying, 'skills relying on AbstractSkill::getMagentoAcl(), which refuses everyone');
    }
}

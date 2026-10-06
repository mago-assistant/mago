<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills;

use MagoAssistant\Mago\Service\Skills\Catalog\ReviewManager;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeModuleManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Stores replace or disable Magento_Review and Magento_CatalogRule. The skills built on them drop out
 * of the registry then, and no class in this module may name those modules' types in its
 * constructor: the object manager and setup:di:compile resolve every constructor in the tool
 * registry, which every admin page builds, so one such dependency breaks the whole admin.
 */
final class OptionalModuleSkillsTest extends TestCase
{
    private const OPTIONAL_NAMESPACES = [
        'Magento\\Review\\',
        'Magento\\CatalogRule\\',
        'Magento\\Inventory',
    ];

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function skills(): array
    {
        return [
            'reviews' => [ReviewManager::class, 'Magento_Review'],
            'catalog price rules' => [CatalogPriceRules::class, 'Magento_CatalogRule'],
        ];
    }

    #[Test]
    #[DataProvider('skills')]
    public function itIsAvailableWhenItsModuleIsEnabled(string $skillClass, string $moduleName): void
    {
        $moduleManager = (new FakeModuleManager())->withEnabledModule($moduleName);
        $skill = new $skillClass(new FakeAuthorization(), $moduleManager);

        $isAvailable = $skill->isAvailable();

        self::assertTrue($isAvailable);
    }

    #[Test]
    #[DataProvider('skills')]
    public function itIsUnavailableWhenItsModuleIsMissing(string $skillClass, string $moduleName): void
    {
        $moduleManager = (new FakeModuleManager())->withEnabledModule('Magento_Catalog');
        $skill = new $skillClass(new FakeAuthorization(), $moduleManager);

        $isAvailable = $skill->isAvailable();

        self::assertFalse($isAvailable);
    }

    #[Test]
    public function noConstructorDependsOnAnOptionalModule(): void
    {
        $offending = array_merge(...array_map($this->optionalConstructorTypes(...), $this->moduleClasses()));

        self::assertSame([], $offending);
    }

    #[Test]
    public function noClassRefersToAGeneratedFactoryOfAnOptionalModule(): void
    {
        $offending = array_keys(array_filter(
            array_map(fn (string $file): bool => $this->refersToOptionalFactory($file), $this->moduleFiles())
        ));

        self::assertSame([], $offending);
    }

    /**
     * @return list<string>
     */
    private function optionalConstructorTypes(string $class): array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return [];
        }

        return array_values(array_map(
            static fn (\ReflectionParameter $parameter): string => $class . '::$' . $parameter->getName(),
            array_filter($constructor->getParameters(), $this->isOptionalModuleParameter(...))
        ));
    }

    private function isOptionalModuleParameter(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType && $this->isOptionalModuleType($type->getName());
    }

    private function isOptionalModuleType(string $typeName): bool
    {
        return array_filter(
            self::OPTIONAL_NAMESPACES,
            static fn (string $namespace): bool => str_starts_with(ltrim($typeName, '\\'), $namespace)
        ) !== [];
    }

    private function refersToOptionalFactory(string $file): bool
    {
        $pattern = '/Magento\\\\(Review|CatalogRule|Inventory\w*)\\\\[\w\\\\]*Factory\b/';

        return preg_match($pattern, (string)file_get_contents($file)) === 1;
    }

    /**
     * @return list<class-string>
     */
    private function moduleClasses(): array
    {
        $root = $this->moduleRoot();

        return array_values(array_filter(
            array_map(
                static fn (string $file): string => 'MagoAssistant\\Mago\\'
                    . str_replace('/', '\\', substr($file, strlen($root) + 1, -4)),
                array_keys($this->moduleFiles())
            ),
            static fn (string $class): bool => class_exists($class) || interface_exists($class)
        ));
    }

    /**
     * @return array<string, string> keyed by path, so a failure names the file
     */
    private function moduleFiles(): array
    {
        $root = $this->moduleRoot();
        $files = [];
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            $path = $file->getPathname();
            $isModuleCode = !str_starts_with($path, $root . '/Test/') && $file->getFilename() !== 'registration.php';
            if ($file->getExtension() === 'php' && $isModuleCode) {
                $files[$path] = $path;
            }
        }

        return $files;
    }

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}

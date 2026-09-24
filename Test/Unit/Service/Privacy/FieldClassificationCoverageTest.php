<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Privacy;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #97 decision 8: classification is a required declaration on every tool and action. This
 * walks every concrete class under Service/Skills and validates the shape of what it declares, so a
 * new action with a malformed map (invalid class, tokenise without a token type) fails the build.
 * An empty map is legal (it means strip everything), so completeness against the actual output
 * shape stays a review concern; the shape itself does not.
 */
final class FieldClassificationCoverageTest extends TestCase
{
    private const VALID_CLASSES = [PiiClass::PUBLIC, PiiClass::TOKENISE, PiiClass::STRIP];

    #[Test]
    public function everyActionAndToolDeclaresAValidClassification(): void
    {
        $checked = 0;
        foreach ($this->skillClasses() as $class) {
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract() || $ref->isInterface() || $ref->isSubclassOf(AbstractSkill::class)) {
                // AbstractSkill subclasses delegate to their actions, which are checked themselves.
                continue;
            }

            $isAction = $ref->implementsInterface(ActionInterface::class);
            $isTool = $ref->implementsInterface(ToolInterface::class);
            if (!$isAction && !$isTool) {
                continue;
            }

            // The declarations are literals, so no constructor dependencies are needed to read them.
            $instance = $ref->newInstanceWithoutConstructor();
            $map = $isAction ? $instance->getFieldClassification() : $instance->getFieldClassification('');

            foreach ($map as $field => $rule) {
                self::assertIsString($field, "$class declares a non-string field name");
                self::assertIsArray($rule, "$class field '$field' rule must be an array");
                self::assertContains(
                    $rule[0] ?? null,
                    self::VALID_CLASSES,
                    "$class field '$field' has an invalid PiiClass"
                );
                if (($rule[0] ?? null) === PiiClass::TOKENISE) {
                    self::assertNotSame(
                        '',
                        (string)($rule[1] ?? ''),
                        "$class field '$field' tokenises without a token type"
                    );
                }
            }
            $checked++;
        }

        // The scan walking zero classes would pass vacuously; the skill tree holds dozens.
        self::assertGreaterThan(50, $checked, 'Service/Skills scan found suspiciously few classes');
    }

    /**
     * @return iterable<string>
     */
    private function skillClasses(): iterable
    {
        $root = dirname(__DIR__, 4) . '/Service/Skills';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'MagoAssistant\\Mago\\Service\\Skills\\' . str_replace('/', '\\', $relative);
            if (class_exists($class)) {
                yield $class;
            }
        }
    }
}

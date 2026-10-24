<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Config\Model\Config\Structure;

/**
 * The merged system.xml structure, declared section by section instead of read from XML. An
 * unknown section comes back as an empty element, exactly as Structure answers one.
 */
final class FakeConfigStructure extends Structure
{
    /** @var array<string, FakeConfigStructureElement> */
    private array $sections = [];

    /** @var array<string, string[]> config path => structure paths of the fields that set it */
    private array $fieldPaths = [];

    /** @var array<string, FakeConfigStructureElement> */
    private array $groups = [];

    /** @var array<string, FakeConfigStructureElement> */
    private array $fields = [];

    // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
    public function __construct()
    {
    }

    public function withSection(string $id, ?string $resource = null): self
    {
        $this->sections[$id] = new FakeConfigStructureElement(
            $resource === null ? ['id' => $id] : ['id' => $id, 'resource' => $resource]
        );

        return $this;
    }

    /**
     * A field whose <config_path> stores its value under another path, usually in another section.
     */
    public function withFieldStoredAt(string $structurePath, string $configPath): self
    {
        $this->fieldPaths[$configPath][] = $structurePath;

        return $this;
    }

    /**
     * A field that stores under its own path
     */
    public function withField(string $path): self
    {
        $this->fieldPaths[$path][] = $path;

        return $this;
    }

    /**
     * A field that stores under its own path, with system.xml data such as its type or backend model
     *
     * @param array<string, string> $data
     */
    public function withFieldData(string $path, array $data): self
    {
        $this->fieldPaths[$path][] = $path;
        $this->fields[$path] = new FakeConfigStructureElement(['id' => $path] + $data);

        return $this;
    }

    /**
     * A group whose field names are free (clone_fields), "section/group"
     */
    public function withCloningGroup(string $path): self
    {
        $this->groups[$path] = new FakeConfigStructureElement(['id' => $path, 'clone_fields' => '1']);

        return $this;
    }

    public function getElement($path): FakeConfigStructureElement
    {
        return $this->sections[$path] ?? $this->groups[$path] ?? $this->fields[$path]
            ?? new FakeConfigStructureElement(['id' => $path]);
    }

    public function getFieldPaths(): array
    {
        return $this->fieldPaths;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Docs;

use MagoAssistant\Mago\Service\Docs\GitHubDocsSource;

/**
 * Serves a fixed tree from memory; a path given to failingOn() comes back as a failed fetch
 */
final class FakeDocsSource extends GitHubDocsSource
{
    /** @var array<string, string> */
    private array $files = [];

    /** @var array<string, true> */
    private array $failingPaths = [];

    public function __construct(private readonly string $sha)
    {
    }

    public function withFile(string $path, string $content): self
    {
        $this->files[$path] = $content;

        return $this;
    }

    public function failingOn(string $path): self
    {
        $this->failingPaths[$path] = true;

        return $this;
    }

    public function fetchTree(string $repo, string $ref): ?array
    {
        return ['sha' => $this->sha, 'paths' => array_keys($this->files)];
    }

    public function fetchRaw(string $repo, string $ref, string $path): ?string
    {
        if (isset($this->failingPaths[$path])) {
            return null;
        }

        return $this->files[$path] ?? null;
    }
}

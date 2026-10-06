<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Docs;

use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;
use MagoAssistant\Mago\Model\Doc\Repository as DocRepository;
use MagoAssistant\Mago\Service\Error\ErrorReporter;

class DocsSyncService
{
    private const FLAG_SHA = 'mago_docs_source_sha';
    private const FLAG_SYNCED_AT = 'mago_docs_synced_at';
    private const FLAG_ERROR = 'mago_docs_last_error';
    private const LOCK_NAME = 'mago_docs_sync';
    private const VARCHAR_MAX = 512;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly GitHubDocsSource $source,
        private readonly ExlMarkdownNormalizer $normalizer,
        private readonly DocRepository $docRepository,
        private readonly FlagManager $flagManager,
        private readonly ErrorReporter $errorReporter,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    /**
     * @param callable(string $step, int $current, int $total): void|null $onProgress
     * @return array<string, mixed> summary of what happened
     */
    public function sync(bool $force = false, ?callable $onProgress = null): array
    {
        $progress = $onProgress ?? static function (): void {};

        if (!$this->config->isDocsEnabled()) {
            return ['skipped' => 'disabled'];
        }

        // Concurrent replaceAll() writers (cron + CLI) can deadlock on the fulltext index;
        // the loser skips instead of waiting.
        try {
            $locked = $this->lockManager->lock(self::LOCK_NAME, 0);
        } catch (\Throwable $e) {
            $this->errorReporter->log('DocsSync', $e);
            return ['error' => $e->getMessage()];
        }
        if (!$locked) {
            return ['skipped' => 'another sync is already running'];
        }

        try {
            return $this->doSync($force, $progress);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * @param callable(string $step, int $current, int $total): void $progress
     * @return array<string, mixed>
     */
    private function doSync(bool $force, callable $progress): array
    {
        $repo = $this->config->getDocsSourceRepo();
        $ref = $this->config->getDocsRef();

        try {
            $progress('tree', 0, 0);
            $tree = $this->source->fetchTree($repo, $ref);
            if ($tree === null) {
                throw new \RuntimeException('Could not fetch doc tree from ' . $repo . '@' . $ref);
            }

            $sha = $tree['sha'];
            $storedShaFlag = $this->flagManager->getFlagData(self::FLAG_SHA);
            $storedSha = is_string($storedShaFlag) ? $storedShaFlag : '';
            $count = $this->docRepository->count();

            if (!$force && $sha !== '' && $sha === $storedSha && $count > 0) {
                return ['skipped' => 'up-to-date', 'sha' => $sha, 'docs' => $count];
            }

            $totalPaths = count($tree['paths']);
            $rawByPath = [];
            foreach ($tree['paths'] as $i => $path) {
                $progress('fetch', $i + 1, $totalPaths);
                $content = $this->source->fetchRaw($repo, $ref, $path);
                if ($content === null) {
                    // A partial corpus would replace the full one and mark this commit synced, so it
                    // would never be retried. Failing here keeps the old corpus and the old SHA.
                    throw new \RuntimeException(sprintf(
                        'Could not fetch %s from %s@%s (file %d of %d), keeping existing corpus',
                        $path,
                        $repo,
                        $ref,
                        $i + 1,
                        $totalPaths
                    ));
                }
                $rawByPath[$path] = $content;
            }

            if (!$rawByPath) {
                throw new \RuntimeException('Fetched 0 files from ' . $repo . '@' . $ref . ' — keeping existing corpus');
            }

            $resolver = static function (string $include) use ($rawByPath): ?string {
                $path = ltrim($include, '/');
                return $rawByPath[$path] ?? null;
            };

            $totalRaw = count($rawByPath);
            $rows = [];
            $normalized_i = 0;
            foreach ($rawByPath as $path => $raw) {
                $normalized_i++;
                $progress('normalize', $normalized_i, $totalRaw);
                if (!$this->normalizer->isIndexable($path)) {
                    continue;
                }
                $normalized = $this->normalizer->normalize($raw, $resolver);
                if ($normalized['content'] === '') {
                    continue;
                }
                $title = $normalized['title'] !== '' ? $normalized['title'] : $path;
                $rows[] = [
                    'source' => $repo,
                    'path' => mb_substr($path, 0, self::VARCHAR_MAX),
                    'title' => mb_substr($title, 0, self::VARCHAR_MAX),
                    'description' => $normalized['description'],
                    'tags' => $normalized['tags'],
                    'edition' => $this->normalizer->edition($path),
                    'url' => $this->normalizer->url($repo, $ref, $path),
                    'content' => $normalized['content'],
                    'source_sha' => $sha,
                ];
            }

            if (!$rows) {
                throw new \RuntimeException('No indexable docs produced (0 rows) — keeping existing corpus');
            }

            $progress('store', 0, count($rows));
            $this->docRepository->replaceAll($rows);
            $progress('store', count($rows), count($rows));

            $this->flagManager->saveFlag(self::FLAG_SHA, $sha);
            $this->flagManager->saveFlag(self::FLAG_SYNCED_AT, time());
            $this->flagManager->deleteFlag(self::FLAG_ERROR);

            return ['indexed' => count($rows), 'sha' => $sha];
        } catch (\Throwable $e) {
            // The CLI and cron answer the server operator with the message; the stored flag only
            // points at the log entry.
            $reference = $this->errorReporter->log('DocsSync', $e);
            $this->flagManager->saveFlag(self::FLAG_ERROR, 'See var/log/mago-error.log, reference ' . $reference);
            return ['error' => $e->getMessage()];
        }
    }
}

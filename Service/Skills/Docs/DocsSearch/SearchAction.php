<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Docs\DocsSearch;

use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Model\Doc\Repository as DocRepository;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class SearchAction implements ActionInterface
{
    public function __construct(
        private readonly DocRepository $docRepository,
        private readonly ConfigRepository $config
    ) {
    }

    public function getName(): string
    {
        return 'search';
    }

    public function getDescription(): string
    {
        // Steering lives here (not only in getInstructions): getInstructions is injected AFTER the
        // first tool call, so it cannot influence the first search.
        return 'Search the docs by keyword and get matching pages (id, title, description, url, snippet). '
            . 'IMPORTANT: search using ENGLISH keywords — the documentation is in English — even when the '
            . 'user writes in another language. Then read the best match with get_doc, and always cite the url.';
    }

    public function getParameterSchema(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'English keywords describing the admin task (e.g. "create terms and conditions").',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'note' => [PiiClass::PUBLIC],
            'id' => [PiiClass::PUBLIC],
            'title' => [PiiClass::PUBLIC],
            'description' => [PiiClass::PUBLIC],
            'url' => [PiiClass::PUBLIC],
            'edition' => [PiiClass::PUBLIC],
            'snippet' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $query = trim((string)($params['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'A "query" is required (use English keywords).'];
        }

        if ($this->docRepository->count() === 0) {
            return [
                'note' => 'The documentation has not been indexed yet. An administrator can run '
                    . '"bin/magento mago:docs:index" (or wait for the sync cron), and it must be enabled '
                    . 'under Stores > Configuration > Mago Assistant > Documentation.',
            ];
        }

        $results = [];
        foreach ($this->docRepository->search($query, $this->config->getDocsTopK()) as $row) {
            $snippet = trim((string)($row['snippet'] ?? ''));
            $results[] = [
                'id' => (int)$row['entity_id'],
                'title' => (string)($row['title'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'url' => (string)($row['url'] ?? ''),
                'edition' => ($row['edition'] ?? null) ?: null,
                'snippet' => $snippet,
            ];
        }

        if (!$results) {
            return ['results' => [], 'note' => 'No documentation matched. Try different English keywords.'];
        }

        return ['results' => $results];
    }
}

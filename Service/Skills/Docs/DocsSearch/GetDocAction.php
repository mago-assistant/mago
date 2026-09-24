<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Docs\DocsSearch;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Model\Doc\Repository as DocRepository;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class GetDocAction implements ActionInterface
{
    private const CONTENT_CAP = 24576; // ~24 KB (~6k tokens)

    public function __construct(
        private readonly DocRepository $docRepository
    ) {
    }

    public function getName(): string
    {
        return 'get_doc';
    }

    public function getDescription(): string
    {
        return 'Fetch the full text of one documentation page by its id (from search results). '
            . 'Answer the user from it and cite its url. Long docs are returned in chunks; if the '
            . 'result says it was truncated, call get_doc again with the given "offset" to read on.';
    }

    public function getParameterSchema(): array
    {
        return [
            'id' => [
                'type' => 'integer',
                'description' => 'The doc id returned by the "search" action.',
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Byte offset to start reading from (default 0). Use the offset a '
                    . 'previous truncated result reported to continue.',
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
            'id' => [PiiClass::PUBLIC],
            'title' => [PiiClass::PUBLIC],
            'url' => [PiiClass::PUBLIC],
            'edition' => [PiiClass::PUBLIC],
            'content' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'A numeric doc "id" (from search results) is required.'];
        }

        $doc = $this->docRepository->getById($id);
        if ($doc === null) {
            return ['error' => 'Doc not found: ' . $id];
        }

        $content = (string)($doc['content'] ?? '');
        $total = strlen($content);
        $offset = min(max(0, (int)($params['offset'] ?? 0)), $total);

        $chunk = mb_strcut($content, $offset, self::CONTENT_CAP);
        $nextOffset = $offset + strlen($chunk);
        if ($nextOffset < $total) {
            $remaining = (int)ceil(($total - $nextOffset) / 1024);
            $chunk .= "\n\n[truncated, {$remaining} KB remaining — call get_doc again with offset={$nextOffset} to continue]";
        }

        return [
            'id' => $id,
            'title' => (string)($doc['title'] ?? ''),
            'url' => (string)($doc['url'] ?? ''),
            'edition' => ($doc['edition'] ?? null) ?: null,
            'content' => $chunk,
        ];
    }
}

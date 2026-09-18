<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class ListBlocksAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient
    ) {
    }

    public function getName(): string
    {
        return 'list_blocks';
    }

    public function getDescription(): string
    {
        return 'List all CMS blocks';
    }

    public function getParameterSchema(): array
    {
        return [];
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
            'total' => [PiiClass::PUBLIC],
            'id' => [PiiClass::PUBLIC],
            'identifier' => [PiiClass::PUBLIC],
            'title' => [PiiClass::PUBLIC],
            'is_active' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $searchParams = $this->apiClient->buildSearchCriteria([], 100);
        $result = $this->apiClient->get('cmsBlock/search', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $blocks = [];
        foreach ($result['items'] ?? [] as $block) {
            $blocks[] = [
                'id' => $block['id'] ?? null,
                'identifier' => $block['identifier'] ?? '',
                'title' => $block['title'] ?? '',
                'is_active' => (bool)($block['active'] ?? false),
            ];
        }

        return ['total' => $result['total_count'] ?? count($blocks), 'blocks' => $blocks];
    }
}

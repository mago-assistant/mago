<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class GetBlockAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient
    ) {
    }

    public function getName(): string
    {
        return 'get_block';
    }

    public function getDescription(): string
    {
        return 'Get CMS block content by identifier or ID';
    }

    public function getParameterSchema(): array
    {
        return [
            'identifier' => [
                'type' => 'string',
                'description' => 'Page/block identifier or ID',
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
            'identifier' => [PiiClass::PUBLIC],
            'title' => [PiiClass::PUBLIC],
            'content' => [PiiClass::PUBLIC],
            'is_active' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $identifier = $params['identifier'] ?? '';
        if (!$identifier) {
            return ['error' => 'Block identifier is required'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        if (is_numeric($identifier)) {
            $result = $this->apiClient->get('cmsBlock/' . $identifier, [], $adminUserId);
        } else {
            $searchParams = $this->apiClient->buildSearchCriteria([
                ['field' => 'identifier', 'value' => $identifier, 'condition_type' => 'eq'],
            ], 1);
            $result = $this->apiClient->get('cmsBlock/search', $searchParams, $adminUserId);

            if (isset($result['error'])) {
                return $result;
            }

            $items = $result['items'] ?? [];
            if (empty($items)) {
                return ['error' => 'Block not found: ' . $identifier];
            }
            $result = reset($items);
        }

        if (isset($result['error'])) {
            return ['error' => 'Block not found: ' . $identifier];
        }

        return [
            'id' => $result['id'] ?? null,
            'identifier' => $result['identifier'] ?? '',
            'title' => $result['title'] ?? '',
            'content' => $result['content'] ?? '',
            'is_active' => (bool)($result['active'] ?? false),
        ];
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class UpdateBlockAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly GetBlockAction $getBlockAction,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'update_block';
    }

    public function getDescription(): string
    {
        return 'Update CMS block content or title';
    }

    public function getParameterSchema(): array
    {
        return [
            'identifier' => [
                'type' => 'string',
                'description' => 'Page/block identifier or ID',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'New content for update actions',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'New title for update actions',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        return [
            'success' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            // _links carries the edit link. Its signed url holds the admin secret key, so it crosses
            // masked as a token and the panel swaps the real url back in on display.
            'label' => [PiiClass::PUBLIC],
            'url' => [PiiClass::TOKENISE, 'url'],
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

        $block = $this->getBlockAction->execute($params, $adminUserId);
        if (isset($block['error'])) {
            return $block;
        }

        $blockId = $block['id'] ?? null;
        if (!$blockId) {
            return ['error' => 'Block not found: ' . $identifier];
        }

        $body = ['block' => ['id' => $blockId]];
        if (!empty($params['content'])) {
            $body['block']['content'] = $params['content'];
        }
        if (!empty($params['title'])) {
            $body['block']['title'] = $params['title'];
        }

        $result = $this->apiClient->put('cmsBlock/' . $blockId, $body, $adminUserId);

        if (isset($result['error'])) {
            return ['error' => 'Failed to update block: ' . $result['error']];
        }

        return [
            'success' => true,
            'message' => 'Block "' . ($block['identifier'] ?? $identifier) . '" updated',
            '_links' => [[
                'label' => 'Edit ' . ($block['identifier'] ?? $identifier),
                'url' => $this->secureAdminUrl->getUrl('cms/block/edit', ['block_id' => $blockId]),
            ]],
        ];
    }
}

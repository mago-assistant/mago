<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class UpdateBlockAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClientInterface $apiClient,
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
        return 'Update CMS block content, title or enabled status';
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
            'is_active' => [
                'type' => 'boolean',
                'description' => 'Enable (true) or disable (false) the block. Leave out to keep the current status',
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

        if (!$this->hasRequestedChange($params)) {
            return ['error' => 'Nothing to update: give content, title or is_active for block "' . $identifier . '"'];
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
        $isActive = $this->getRequestedActiveStatus($params);
        if ($isActive !== null) {
            $body['block']['active'] = $isActive;
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

    /**
     * @param array<string, mixed> $params
     */
    private function hasRequestedChange(array $params): bool
    {
        return !empty($params['content'])
            || !empty($params['title'])
            || $this->getRequestedActiveStatus($params) !== null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function getRequestedActiveStatus(array $params): ?bool
    {
        if (!array_key_exists('is_active', $params) || $params['is_active'] === null) {
            return null;
        }

        return filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}

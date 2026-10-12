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

class UpdatePageAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClientInterface $apiClient,
        private readonly GetPageAction $getPageAction,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'update_page';
    }

    public function getDescription(): string
    {
        return 'Update CMS page content or title';
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
            return ['error' => 'Page identifier is required'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $page = $this->getPageAction->execute($params, $adminUserId);
        if (isset($page['error'])) {
            return $page;
        }

        $pageId = $page['id'] ?? null;
        if (!$pageId) {
            return ['error' => 'Page not found: ' . $identifier];
        }

        $body = ['page' => ['id' => $pageId]];
        // Send the existing URL key back, or Magento regenerates the identifier from the title on
        // save and the old storefront URL 404s with no redirect. This action only edits content and
        // title; it never renames a page.
        $currentIdentifier = (string)($page['identifier'] ?? '');
        if ($currentIdentifier !== '') {
            $body['page']['identifier'] = $currentIdentifier;
        }
        if (!empty($params['content'])) {
            $body['page']['content'] = $params['content'];
        }
        if (!empty($params['title'])) {
            $body['page']['title'] = $params['title'];
        }

        $result = $this->apiClient->put('cmsPage/' . $pageId, $body, $adminUserId);

        if (isset($result['error'])) {
            return ['error' => 'Failed to update page: ' . $result['error']];
        }

        return [
            'success' => true,
            'message' => 'Page "' . ($page['identifier'] ?? $identifier) . '" updated',
            '_links' => [[
                'label' => 'Edit ' . ($page['identifier'] ?? $identifier),
                'url' => $this->secureAdminUrl->getUrl('cms/page/edit', ['page_id' => $pageId]),
            ]],
        ];
    }
}

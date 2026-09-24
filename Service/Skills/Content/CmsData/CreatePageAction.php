<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class CreatePageAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly StoreScopeContext $scopeContext,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'create_page';
    }

    public function getDescription(): string
    {
        return 'Create a new CMS page';
    }

    public function getParameterSchema(): array
    {
        return [
            'identifier' => [
                'type' => 'string',
                'description' => 'URL key for the page (e.g. "about-us")',
                'required' => true,
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Page title',
                'required' => true,
            ],
            'content' => [
                'type' => 'string',
                'description' => 'Page content (HTML)',
                'required' => true,
            ],
            'content_heading' => [
                'type' => 'string',
                'description' => 'Content heading displayed above the content',
            ],
            'is_active' => [
                'type' => 'boolean',
                'description' => 'Whether the page is enabled (default: true)',
            ],
            'store_id' => [
                'type' => 'integer',
                'description' => 'Only for create_page and create_block: store view the new page or block belongs '
                    . 'to. 0 = all store views (default); a store view id from the store scope list limits it to that '
                    . 'store view. Ignored by all other actions.',
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
            'id' => [PiiClass::PUBLIC],
            'store_id' => [PiiClass::PUBLIC],
            'store_label' => [PiiClass::PUBLIC],
            // _links carries the edit link. Its signed url holds the admin secret key, so it crosses
            // masked as a token and the panel swaps the real url back in on display.
            'label' => [PiiClass::PUBLIC],
            'url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return 'New pages belong to all store views unless store_id names one store view. On a multi-store '
            . 'installation, ask which store view the page is for when the user did not say and the content is '
            . 'store-specific (language, brand, region); otherwise create it for all store views.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $identifier = $params['identifier'] ?? '';
        $title = $params['title'] ?? '';
        $content = $params['content'] ?? '';

        if (!$identifier || !$title) {
            return ['error' => 'Both identifier and title are required'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $storeId = (int)($params['store_id'] ?? 0);
        $storeCode = $this->scopeContext->getRestStoreCode($storeId);
        if ($storeCode === null) {
            return ['error' => $this->scopeContext->getUnknownStoreViewError($storeId)];
        }

        $page = [
            'identifier' => $identifier,
            'title' => $title,
            'content' => $content,
            'active' => ($params['is_active'] ?? true) ? true : false,
        ];

        if (!empty($params['content_heading'])) {
            $page['content_heading'] = $params['content_heading'];
        }

        $result = $this->apiClient->post('cmsPage', ['page' => $page], $adminUserId, $storeCode);

        if (isset($result['error'])) {
            return ['error' => 'Failed to create page: ' . $result['error']];
        }

        $storeLabel = $this->scopeContext->describeStoreTarget($storeId);
        $pageId = $result['id'] ?? null;

        $response = [
            'success' => true,
            'message' => 'Page "' . $identifier . '" created for ' . $storeLabel,
            'id' => $pageId,
            'store_id' => $storeId,
            'store_label' => $storeLabel,
        ];

        if ($pageId) {
            $response['_links'] = [[
                'label' => 'Edit ' . $title,
                'url' => $this->secureAdminUrl->getUrl('cms/page/edit', ['page_id' => $pageId]),
            ]];
        }

        return $response;
    }
}

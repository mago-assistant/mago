<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Seo\UrlRewriteManager;

use Magento\UrlRewrite\Model\ResourceModel\UrlRewrite as UrlRewriteResource;
use Magento\UrlRewrite\Model\UrlRewriteFactory;
use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class DeleteAction implements IrreversibleActionInterface
{
    public function __construct(
        private readonly UrlRewriteFactory $urlRewriteFactory,
        private readonly UrlRewriteResource $urlRewriteResource
    ) {
    }

    public function getName(): string
    {
        return 'delete';
    }

    public function getDescription(): string
    {
        return 'Delete a custom URL rewrite (refuses auto-generated rewrites)';
    }

    public function getParameterSchema(): array
    {
        return [
            'url_rewrite_id' => [
                'type' => 'integer',
                'description' => 'The ID of the URL rewrite to delete',
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
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getImpacts(array $params, int $adminUserId): array
    {
        $rewriteId = (int)($params['url_rewrite_id'] ?? 0);
        $lines = [];
        try {
            $urlRewrite = $this->urlRewriteFactory->create();
            $this->urlRewriteResource->load($urlRewrite, $rewriteId);
            if ($urlRewrite->getId()) {
                $lines[] = 'Rewrite "' . $urlRewrite->getData('request_path') . '" (ID ' . $rewriteId . ') is removed; '
                    . 'visitors on that URL get a 404 until a new rewrite exists.';
                if ($urlRewrite->getData('entity_type') !== 'custom') {
                    $lines[] = 'This is an auto-generated rewrite, so the delete will be refused.';
                }
            }
        } catch (\Exception $e) {
            $lines[] = 'The rewrite could not be inspected: ' . $e->getMessage();
        }
        if ($lines === []) {
            $lines[] = 'URL rewrite ' . $rewriteId . ' is removed; visitors on its URL get a 404.';
        }
        $lines[] = 'There is no trash bin; restoring it means recreating it by hand.';

        return $lines;
    }

    public function execute(array $params, int $adminUserId): array
    {
        $rewriteId = (int)($params['url_rewrite_id'] ?? 0);
        if (!$rewriteId) {
            return ['error' => 'url_rewrite_id is required'];
        }

        try {
            $urlRewrite = $this->urlRewriteFactory->create();
            $this->urlRewriteResource->load($urlRewrite, $rewriteId);

            if (!$urlRewrite->getId()) {
                return ['error' => 'URL rewrite with ID ' . $rewriteId . ' not found'];
            }

            $entityType = $urlRewrite->getData('entity_type');
            if ($entityType !== 'custom') {
                return [
                    'error' => 'Cannot delete auto-generated URL rewrite (type: "' . $entityType . '"). '
                        . 'Only "custom" rewrites can be deleted. Auto-generated rewrites are managed by Magento indexers.',
                ];
            }

            $requestPath = $urlRewrite->getData('request_path');
            $this->urlRewriteResource->delete($urlRewrite);

            return [
                'success' => true,
                'message' => 'URL rewrite deleted: "' . $requestPath . '" (ID: ' . $rewriteId . ')',
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to delete URL rewrite: ' . $e->getMessage()];
        }
    }
}

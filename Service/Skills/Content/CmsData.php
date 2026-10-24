<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Content;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;

class CmsData extends AbstractSkill
{
    public function __construct(
        AuthorizationInterface $authorization,
        private readonly EntityRouteMap $entityRouteMap,
        array $actions = []
    ) {
        parent::__construct($authorization, $actions);
    }

    public function getName(): string
    {
        return 'cms_data';
    }

    private const ENTITY_TYPE_BY_ACTION = [
        'list_pages' => 'cms_page',
        'get_page' => 'cms_page',
        'create_page' => 'cms_page',
        'update_page' => 'cms_page',
        'list_blocks' => 'cms_block',
        'get_block' => 'cms_block',
        'create_block' => 'cms_block',
        'update_block' => 'cms_block',
    ];

    /**
     * A read is gated by the grid, a write by the edit screen - for pages a different resource
     * (Magento_Cms::save, which the REST route the write goes through requires too; #148). Empty
     * or unknown input resolves to the page edit screen, the strictest this skill reaches.
     */
    public function getMagentoAcl(array $input = []): string
    {
        $entityType = self::ENTITY_TYPE_BY_ACTION[$input['action'] ?? ''] ?? null;
        if ($entityType === null) {
            return $this->entityRouteMap->getAclResource('cms_page') ?? '';
        }

        $resource = $this->isReadOnlyAction($input)
            ? $this->entityRouteMap->getListAclResource($entityType)
            : $this->entityRouteMap->getAclResource($entityType);

        return $resource ?? '';
    }

    protected function getBaseDescription(): string
    {
        return 'Manage CMS pages and blocks.';
    }

    protected function getBaseInstructions(): string
    {
        return 'store_id only applies to create_page and create_block. Updating a page or block keeps its existing '
            . 'store view assignment; store_id is ignored there. '
            . 'The panel adds a link to the created or updated page or block for you, so do not write an edit or '
            . 'admin url in your answer yourself.';
    }
}

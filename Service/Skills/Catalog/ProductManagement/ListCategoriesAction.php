<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductManagement;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\ActiveStores;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\CategoryTree;

class ListCategoriesAction implements ActionInterface
{
    public function __construct(
        private readonly ActiveStores $activeStores,
        private readonly CategoryTree $categoryTree
    ) {
    }

    public function getName(): string
    {
        return 'list_categories';
    }

    public function getDescription(): string
    {
        return 'List enabled categories with their IDs and paths, so a category name the user gives can be '
            . 'matched to its ID';
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
            'categories' => [PiiClass::PUBLIC],
            'id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'path' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $stores = $this->activeStores->select('') ?? [];
        $categories = [];
        foreach ($stores as $store) {
            $rootCategoryId = $this->activeStores->rootCategoryId($store);
            $tree = $this->categoryTree->below((int)$store->getId(), $rootCategoryId);
            foreach ($tree as $id => $category) {
                if (!$category['is_active'] || isset($categories[$id])) {
                    continue;
                }
                $categories[$id] = [
                    'id' => $id,
                    'name' => $category['name'],
                    'path' => CategoryTree::breadcrumb($tree, $category['path'], $id, $rootCategoryId),
                ];
            }
        }

        return ['categories' => array_values($categories)];
    }
}

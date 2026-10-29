<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\Search\DocumentFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\FilterBuilder;
use MagoAssistant\Mago\Service\Tool\AdminDescription;
use MagoAssistant\Mago\Service\Tool\ToolCategory;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;

class SkillsDataProvider extends DataProvider
{
    private ?array $skillsData = null;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        ReportingInterface $reporting,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RequestInterface $request,
        FilterBuilder $filterBuilder,
        private readonly ToolRegistry $toolRegistry,
        private readonly ResourceConnection $resourceConnection,
        private readonly SearchResultFactory $searchResultFactory,
        private readonly DocumentFactory $documentFactory,
        private readonly AttributeValueFactory $attributeValueFactory,
        private readonly AdminDescription $adminDescription,
        private readonly ToolCategory $toolCategory,
        private readonly ArrayGridQuery $gridQuery,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $reporting,
            $searchCriteriaBuilder,
            $request,
            $filterBuilder,
            $meta,
            $data
        );
    }

    private function getSkillsData(): array
    {
        if ($this->skillsData !== null) {
            return $this->skillsData;
        }

        $assignedUsers = $this->getAssignedUsers();
        $this->skillsData = [];

        foreach ($this->toolRegistry->getAllTools() as $tool) {
            $name = $tool->getName();

            $this->skillsData[] = [
                'name' => $name,
                'description' => $this->adminDescription->of($tool),
                'category' => $this->toolCategory->of($tool),
                'type' => $tool->isReadOnly() ? 'read' : 'write',
                'assigned_users' => $assignedUsers[$name] ?? '',
            ];
        }
        return $this->skillsData;
    }

    private function getAssignedUsers(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $permTable = $this->resourceConnection->getTableName('mago_skill_permission');
        $userTable = $this->resourceConnection->getTableName('admin_user');

        $select = $connection->select()
            ->from(['sp' => $permTable], ['skill_name', 'permission'])
            ->joinLeft(
                ['u' => $userTable],
                'sp.admin_user_id = u.user_id',
                ['username']
            );

        $rows = $connection->fetchAll($select);

        $result = [];
        foreach ($rows as $row) {
            $skillName = $row['skill_name'];
            $label = $row['username'] . ' (' . $row['permission'] . ')';
            $result[$skillName] = isset($result[$skillName])
                ? $result[$skillName] . ', ' . $label
                : $label;
        }
        return $result;
    }

    public function getSearchResult(): SearchResultInterface
    {
        $result = $this->getGridResult();
        $documents = [];
        foreach ($result['items'] as $item) {
            $doc = $this->documentFactory->create();
            $doc->setId($item['name']);
            $attributes = [];
            foreach ($item as $key => $value) {
                $attr = $this->attributeValueFactory->create();
                $attr->setAttributeCode($key);
                $attr->setValue($value);
                $attributes[$key] = $attr;
            }
            $doc->setCustomAttributes($attributes);
            $documents[] = $doc;
        }

        $searchResult = $this->searchResultFactory->create();
        $searchResult->setItems($documents);
        $searchResult->setTotalCount($result['totalRecords']);
        $searchResult->setSearchCriteria($this->getSearchCriteria());

        return $searchResult;
    }

    public function getData(): array
    {
        return $this->getGridResult();
    }

    /**
     * The skills after the grid's filters, sort order and page (#307)
     *
     * @return array{totalRecords: int, items: list<array<string, mixed>>}
     */
    private function getGridResult(): array
    {
        return $this->gridQuery->apply($this->getSkillsData(), $this->getSearchCriteria());
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\ResourceModel\Conversation;

use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * The conversations grid as a SearchResult collection (#310), so Magento applies the toolbar's
 * filters, sorting and paging in SQL. The usage columns are correlated subqueries: sorting uses
 * their alias, and a filter on one repeats the expression in the WHERE clause.
 */
class Grid extends SearchResult implements SearchResultInterface
{
    private const GRID_COLUMNS = ['entity_id', 'admin_user_id', 'title', 'created_at', 'updated_at'];

    private const INPUT_TOKEN_PRICE_PER_MILLION = 3.0;
    private const OUTPUT_TOKEN_PRICE_PER_MILLION = 15.0;

    protected function _initSelect(): self
    {
        parent::_initSelect();

        $joinedFields = ['admin_username' => 'admin_user.firstname'];
        $computedFields = $this->getComputedFields();

        $this->getSelect()
            ->reset(Select::COLUMNS)
            ->columns(self::GRID_COLUMNS, 'main_table')
            ->joinLeft(
                ['admin_user' => $this->getTable('admin_user')],
                'main_table.admin_user_id = admin_user.user_id',
                $joinedFields
            )
            ->columns($computedFields);

        foreach (self::GRID_COLUMNS as $column) {
            $this->addFilterToMap($column, 'main_table.' . $column);
        }
        foreach ($joinedFields as $alias => $field) {
            $this->addFilterToMap($alias, $field);
        }

        return $this;
    }

    public function addFieldToFilter($field, $condition = null): self
    {
        $expression = is_string($field) ? ($this->getComputedFields()[$field] ?? null) : null;
        if ($expression === null || $condition === null) {
            return parent::addFieldToFilter($field, $condition);
        }

        $this->getSelect()->where($this->_getConditionSql((string)$expression, $condition));

        return $this;
    }

    /**
     * @return array<string, \Zend_Db_Expr>
     */
    private function getComputedFields(): array
    {
        $messages = $this->getTable('mago_message');
        $usage = $this->getTable('mago_usage_log');
        $ofConversation = ' WHERE conversation_id = main_table.entity_id';

        return [
            'message_count' => new \Zend_Db_Expr('(SELECT COUNT(*) FROM ' . $messages . $ofConversation . ')'),
            'total_tokens' => new \Zend_Db_Expr(
                '(SELECT COALESCE(SUM(total_tokens), 0) FROM ' . $usage . $ofConversation . ')'
            ),
            'model' => new \Zend_Db_Expr('(SELECT MAX(model) FROM ' . $usage . $ofConversation . ')'),
            'estimated_cost' => new \Zend_Db_Expr(sprintf(
                '(SELECT ROUND(COALESCE(SUM(input_tokens), 0) / 1000000 * %F'
                . ' + COALESCE(SUM(output_tokens), 0) / 1000000 * %F, 4) FROM %s%s)',
                self::INPUT_TOKEN_PRICE_PER_MILLION,
                self::OUTPUT_TOKEN_PRICE_PER_MILLION,
                $usage,
                $ofConversation
            )),
            'skills_used' => new \Zend_Db_Expr(
                '(SELECT GROUP_CONCAT(DISTINCT skill_names) FROM ' . $usage . $ofConversation
                . " AND skill_names IS NOT NULL AND skill_names != '')"
            ),
        ];
    }
}

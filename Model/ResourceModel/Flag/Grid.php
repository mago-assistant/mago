<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\ResourceModel\Flag;

use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * The collection behind the Answer Feedback grid.
 *
 * Deliberately a SearchResult rather than a hand-rolled provider: filtering, sorting, paging and
 * the keyword search are Magento's to do, and a provider that fetches every row and ignores the
 * search criteria only looks like it works until someone types in the filter box.
 *
 * Every column the grid offers therefore has to exist in SQL, which is why the three fields taken
 * from the snapshot are stored as columns of their own.
 */
class Grid extends SearchResult implements SearchResultInterface
{
    /**
     * Every mago_flag column except the snapshot, which is megabytes of payload the grid never shows
     */
    private const GRID_COLUMNS = [
        'entity_id',
        'message_id',
        'conversation_id',
        'admin_user_id',
        'status',
        'rating',
        'note',
        'answer_preview',
        'model',
        'skills',
        'created_at',
        'updated_at',
    ];

    /**
     * Grid fields that live on a joined table, by the column they come from
     */
    private const JOINED_FIELDS = [
        'flagged_by' => 'admin_user.username',
        'conversation_title' => 'conversation.title',
    ];

    protected function _initSelect(): self
    {
        parent::_initSelect();

        $this->getSelect()
            ->reset(Select::COLUMNS)
            ->columns(self::GRID_COLUMNS, 'main_table')
            ->joinLeft(
                ['admin_user' => $this->getTable('admin_user')],
                'main_table.admin_user_id = admin_user.user_id',
                ['flagged_by' => 'admin_user.username']
            )->joinLeft(
                ['conversation' => $this->getTable('mago_conversation')],
                'main_table.conversation_id = conversation.entity_id',
                ['conversation_title' => 'conversation.title']
            );

        return $this;
    }

    /**
     * Both joined tables carry an entity_id of their own, so the grid's own id has to be spelled out.
     *
     * @param string $field
     * @param mixed $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null): self
    {
        if (is_string($field) && !str_contains($field, '.')) {
            $field = self::JOINED_FIELDS[$field] ?? 'main_table.' . $field;
        }

        return parent::addFieldToFilter($field, $condition);
    }
}

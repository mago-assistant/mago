<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Configuration;

use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;
use MagoAssistant\Mago\Api\Tool\ActionScopedToolInterface;
use MagoAssistant\Mago\Api\Tool\UpfrontGuidanceToolInterface;
use MagoAssistant\Mago\Api\Tool\ValidatingToolInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class IndexerManager implements ActionScopedToolInterface, UpfrontGuidanceToolInterface, ValidatingToolInterface
{
    /** An indexer id echoed into an error is capped here so a runaway argument is not stored or re-sent */
    private const MAX_ID_ECHO = 100;

    private const ACTION_DESCRIPTIONS = [
        'status' => 'list all indexers with status',
        'reindex' => 'reindex one indexer by ID in the background, e.g. "catalog_product_price"',
        'reindex_all' => 'reindex all indexers in the background',
        'set_mode' => 'set indexer mode to "realtime" or "schedule"',
    ];

    private const REINDEX_ACTIONS = ['reindex', 'reindex_all'];

    /**
     * Steer the model away from reindex_all and towards the indexers the change actually touches.
     * It is in the description, not getInstructions(), because the description is always in the tool
     * schema, while getInstructions() is injected only after a call runs — too late to stop the
     * full reindex it should have questioned.
     */
    private const PUSHBACK = 'Prefer reindexing the specific indexer(s) a change affects over '
        . 'reindex_all. Common indexers: catalog_product_price (prices), cataloginventory_stock '
        . '(stock/salable quantity), catalogsearch_fulltext (search results), catalog_category_product '
        . 'and catalog_product_category (category/PLP listings), catalogrule_product (cart price '
        . 'rules), catalog_product_attribute (attributes/layered navigation). When the user names or '
        . 'links a specific product or category, deduce which indexers that entity touches and reindex '
        . 'only those. If it is unclear which index the user means, ask which part — search, price, '
        . 'stock, catalog — before acting. '
        . 'Once you know which indexer is needed, DO IT: call indexer_manager with action "reindex" and '
        . 'that indexer_id yourself. The reindex is not executed until the admin approves it on a '
        . 'confirmation card, so proposing the call IS the safe, correct step. Do not answer with '
        . 'instructions telling the admin to type an "/index" slash command or to reindex from the '
        . 'admin grid — you perform the reindex call, they confirm it. '
        . 'And do NOT ask "would you like me to proceed?" or any yes/no in text before the reindex: '
        . 'that question is exactly what the confirmation card asks. Make the reindex call immediately; '
        . 'the card is the admin\'s yes/no.';

    public function __construct(
        private readonly CollectionFactory $indexerCollectionFactory,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly InternalApiClient $apiClient,
        private readonly ConfigRepository $config
    ) {
    }

    public function getName(): string
    {
        return 'indexer_manager';
    }

    public function getDescription(): string
    {
        return $this->getDescriptionForActions($this->getActionNames());
    }

    public function getDescriptionForActions(array $actionNames): string
    {
        $parts = [];
        foreach (self::ACTION_DESCRIPTIONS as $name => $description) {
            if (in_array($name, $actionNames, true)) {
                $parts[] = '"' . $name . '" (' . $description . ')';
            }
        }

        return 'Manage Magento indexers. Actions: ' . implode(', ', $parts) . '.';
    }

    public function getParameterSchema(): array
    {
        return $this->getParameterSchemaForActions($this->getActionNames());
    }

    public function getParameterSchemaForActions(array $actionNames): array
    {
        $properties = [
            'action' => [
                'type' => 'string',
                'description' => 'The action to perform',
                'enum' => array_values(array_intersect(array_keys(self::ACTION_DESCRIPTIONS), $actionNames)),
            ],
        ];
        if (array_intersect(['reindex', 'set_mode'], $actionNames) !== []) {
            // The real indexers are known here, so they are offered as an enum rather than a free
            // string: the model cannot invent an id that then fails only after the admin confirms it.
            $indexers = $this->indexerTitles();
            $properties['indexer_id'] = [
                'type' => 'string',
                'description' => 'Indexer to target for reindex/set_mode. Use one of these exact IDs'
                    . ($indexers === [] ? '.' : ': ' . $this->formatIdList($indexers) . '.')
                    . ' Do not invent an ID; run the "status" action if unsure.',
            ];
            if ($indexers !== []) {
                $properties['indexer_id']['enum'] = array_keys($indexers);
            }
        }
        if (in_array('set_mode', $actionNames, true)) {
            $properties['mode'] = [
                'type' => 'string',
                'description' => 'Indexer mode for set_mode action',
                'enum' => ['realtime', 'schedule'],
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => ['action'],
        ];
    }

    public function execute(array $params): array
    {
        $action = $params['action'] ?? '';
        $indexerId = $params['indexer_id'] ?? '';
        $adminUserId = (int)($params['_admin_user_id'] ?? 0);
        $mode = $params['mode'] ?? '';

        if (in_array($action, self::REINDEX_ACTIONS, true) && !$this->config->isReindexAllowed()) {
            return [
                'error' => 'Reindexing is disabled. Enable it in '
                    . 'Stores > Configuration > Mago Assistant > Tools > Allow reindexing.',
            ];
        }

        return match ($action) {
            'status' => $this->getStatus(),
            'reindex' => $this->reindex($indexerId, $adminUserId),
            'reindex_all' => $this->reindexAll($adminUserId),
            'set_mode' => $this->setMode($indexerId, $mode),
            default => ['error' => 'Unknown action: ' . $action],
        };
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isReadOnlyAction(array $input): bool
    {
        // status only reads indexer state; reindex/reindex_all/set_mode mutate. Unknown actions fail closed to write.
        return ($input['action'] ?? '') === 'status';
    }

    /**
     * Refuse a reindex/set_mode whose indexer_id is not a real indexer before the confirmation card,
     * answering with the valid list so the model can correct itself rather than have the admin allow
     * a card that only fails on execution (and then guess again, or escalate to reindex_all).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function findRefusal(array $input): ?array
    {
        $action = (string)($input['action'] ?? '');
        if ($action !== 'reindex' && $action !== 'set_mode') {
            return null;
        }
        $id = (string)($input['indexer_id'] ?? '');
        // An empty id is the "parameter is required" case, answered by execute(), not an invented one.
        if ($id === '') {
            return null;
        }
        $indexers = $this->indexerTitles();
        if (isset($indexers[$id])) {
            return null;
        }

        return [
            'error' => sprintf('Unknown indexer "%s". It is not one of this store\'s indexers.', $this->truncateId($id)),
            'valid_indexers' => $this->idTitlePairs($indexers),
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getUpfrontGuidance(): string
    {
        return self::PUSHBACK;
    }

    public function getFieldClassification(string $action = ''): array
    {
        return [
            'message' => [PiiClass::PUBLIC],
            'id' => [PiiClass::PUBLIC],
            'title' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'mode' => [PiiClass::PUBLIC],
            'updated' => [PiiClass::PUBLIC],
            'success' => [PiiClass::PUBLIC],
            'reindexed' => [PiiClass::PUBLIC],
            'errors' => [PiiClass::PUBLIC],
            'bulk_uuid' => [PiiClass::PUBLIC],
            'valid_indexers' => [PiiClass::PUBLIC],
        ];
    }

    public function getMagentoAcl(array $input = []): string
    {
        // Mirrors module-indexer acl.xml: index (read, the Index Management grid),
        // invalidate (closest native resource to triggering a rebuild; Magento has
        // no dedicated reindex resource), changeMode for set_mode. Unknown actions
        // fail closed to the mode-change resource.
        return match ($input['action'] ?? '') {
            'status' => 'Magento_Indexer::index',
            'reindex', 'reindex_all' => 'Magento_Indexer::invalidate',
            default => 'Magento_Indexer::changeMode',
        };
    }

    /**
     * @return string[]
     */
    private function getActionNames(): array
    {
        $actionNames = array_keys(self::ACTION_DESCRIPTIONS);
        if ($this->config->isReindexAllowed()) {
            return $actionNames;
        }

        return array_values(array_diff($actionNames, self::REINDEX_ACTIONS));
    }

    private function getStatus(): array
    {
        $collection = $this->indexerCollectionFactory->create();
        $result = [];
        foreach ($collection->getItems() as $indexer) {
            $result[] = [
                'id' => $indexer->getId(),
                'title' => $indexer->getTitle(),
                'status' => $indexer->getStatus(),
                'mode' => $indexer->isScheduled() ? 'schedule' : 'realtime',
                'updated' => $indexer->getUpdated(),
            ];
        }
        return ['indexers' => $result];
    }

    private function reindex(string $indexerId, int $adminUserId): array
    {
        if (!$indexerId) {
            return ['error' => 'indexer_id parameter is required for reindex action'];
        }

        try {
            $this->indexerRegistry->get($indexerId);
        } catch (\Exception $e) {
            return ['error' => 'Unknown indexer: ' . $this->truncateId($indexerId)
                . '. Use "status" action to list available indexers.'];
        }

        return $this->queue(
            'mago/indexers/reindex',
            ['indexerIds' => [$indexerId]],
            $adminUserId
        );
    }

    private function reindexAll(int $adminUserId): array
    {
        return $this->queue('mago/indexers/reindex-all', [], $adminUserId);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function queue(string $endpoint, array $body, int $adminUserId): array
    {
        $response = $this->apiClient->postAsync($endpoint, $body, $adminUserId);
        if (isset($response['error'])) {
            return $response;
        }

        return [
            'success' => true,
            'message' => 'Reindex queued',
            'bulk_uuid' => (string)($response['bulk_uuid'] ?? ''),
        ];
    }

    private function setMode(string $indexerId, string $mode): array
    {
        if (!$indexerId) {
            return ['error' => 'indexer_id parameter is required for set_mode action'];
        }
        if (!in_array($mode, ['realtime', 'schedule'])) {
            return ['error' => 'mode must be "realtime" or "schedule"'];
        }

        try {
            $indexer = $this->indexerRegistry->get($indexerId);
        } catch (\Exception $e) {
            return ['error' => 'Unknown indexer: ' . $this->truncateId($indexerId)];
        }

        $indexer->setScheduled($mode === 'schedule');

        return [
            'success' => true,
            'message' => sprintf('Indexer "%s" mode set to "%s"', $indexer->getTitle(), $mode),
        ];
    }

    /**
     * Live indexers as id => title, or an empty list if the collection cannot be read. A failure
     * here must not break the tool schema (which is built on every chat request), so the id then
     * falls back to a free string rather than taking the assistant down.
     *
     * @return array<string, string>
     */
    private function indexerTitles(): array
    {
        try {
            $titles = [];
            foreach ($this->indexerCollectionFactory->create()->getItems() as $indexer) {
                $titles[(string)$indexer->getId()] = (string)$indexer->getTitle();
            }

            return $titles;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, string> $indexers id => title
     * @return array<int, array{id: string, title: string}>
     */
    private function idTitlePairs(array $indexers): array
    {
        $pairs = [];
        foreach ($indexers as $id => $title) {
            $pairs[] = ['id' => $id, 'title' => $title];
        }

        return $pairs;
    }

    /**
     * "id (Title)" for each indexer, for the parameter description
     *
     * @param array<string, string> $indexers id => title
     */
    private function formatIdList(array $indexers): string
    {
        $parts = [];
        foreach ($indexers as $id => $title) {
            $parts[] = $title === '' ? $id : sprintf('%s (%s)', $id, $title);
        }

        return implode(', ', $parts);
    }

    private function truncateId(string $id): string
    {
        return mb_strlen($id) > self::MAX_ID_ECHO ? mb_substr($id, 0, self::MAX_ID_ECHO) . '…' : $id;
    }
}

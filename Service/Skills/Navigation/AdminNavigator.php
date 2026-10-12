<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Navigation;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\AdminRouteAcl;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class AdminNavigator implements ToolInterface
{
    /**
     * How many ranked matches to filter by permission before cutting to the requested limit;
     * more than the registry holds, so a denied page never costs the admin a result.
     */
    private const SEARCH_POOL = 50;

    public function __construct(
        private readonly PageRegistry $pageRegistry,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly EntityRouteMap $entityRouteMap,
        private readonly AdminRouteAcl $adminRouteAcl,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    public function getName(): string
    {
        return 'admin_navigator';
    }

    public function getDescription(): string
    {
        return 'Find direct clickable links to Magento admin pages. Two modes: '
            . '(1) Search: pass "query" to find admin pages by keyword. '
            . '(2) Direct link: pass "entity_type" + "entity_id" to get a link to a specific record '
            . '(use after fetching entity data from order_manager, customer_data, or product_data). '
            . 'ALWAYS use this tool when the user asks where to find something in the admin. '
            . 'Link only to a url this tool returned, copied exactly as it came back. Never build '
            . 'one from a pattern: an admin url carries a secret key, so a url you assembled '
            . 'yourself does not open anything. Have no url? Name the admin page in words instead.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query to find admin pages (e.g. "orders", "store name", "cache")',
                ],
                'entity_type' => [
                    'type' => 'string',
                    'enum' => $this->entityRouteMap->getEntityTypes(),
                    'description' => 'Entity type for direct link (use with entity_id)',
                ],
                'entity_id' => [
                    'type' => 'integer',
                    'description' => 'Entity ID (the internal Magento entity_id, not the increment_id)',
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => ['Dashboard', 'Sales', 'Catalog', 'Customers', 'Marketing', 'Content', 'Reports', 'Stores', 'System'],
                    'description' => 'Optional: filter search results by category',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of search results (default: 5)',
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        // Direct entity link mode
        if (!empty($params['entity_type']) && !empty($params['entity_id'])) {
            return $this->resolveEntityLink(
                (string)$params['entity_type'],
                (int)$params['entity_id']
            );
        }

        // Search mode
        $query = $params['query'] ?? '';
        if (trim($query) === '') {
            return ['error' => 'Provide either "query" for search or "entity_type" + "entity_id" for a direct link'];
        }

        $category = $params['category'] ?? null;
        $limit = max(1, min((int)($params['limit'] ?? 5), 10));
        // Only pages in the asked category that this admin may open - a link to a page that
        // answers 403 is noise, and the resource guarding each page is the same one Magento checks
        // on arrival. Both filters run before the cut, so neither costs the admin a result: the
        // registry is searched past the limit and trimmed afterwards.
        $matches = array_slice(array_values(array_filter(
            $this->pageRegistry->search($query, self::SEARCH_POOL),
            fn(array $m): bool => ($category === null || mb_strtolower((string)$m['category']) === mb_strtolower((string)$category))
                && $this->mayOpen((string)$m['route'])
        )), 0, $limit);

        if (empty($matches)) {
            return [
                'results' => [],
                'message' => 'No admin pages found for "' . $query . '". Try different keywords.',
            ];
        }

        $results = [];
        foreach ($matches as $match) {
            $results[] = [
                'label' => $match['label'],
                'category' => $match['category'],
                'url' => $this->secureAdminUrl->getUrl($match['route']),
            ];
        }

        return ['results' => $results];
    }

    /**
     * A route Magento resolves no controller for is not offered either: there is no way to tell
     * what guards it, so it is treated as closed.
     */
    private function mayOpen(string $route): bool
    {
        $resource = $this->adminRouteAcl->forRoute($route);

        return $resource !== null && $this->authorization->isAllowed($resource);
    }

    private function resolveEntityLink(string $entityType, int $entityId): array
    {
        $route = $this->entityRouteMap->getRoute($entityType);
        $paramKey = $this->entityRouteMap->getParamKey($entityType);

        if (!$route || !$paramKey) {
            return ['error' => 'Unknown entity type: ' . $entityType];
        }

        $url = $this->secureAdminUrl->getUrl($route, [$paramKey => $entityId]);

        return [
            'results' => [[
                'label' => ucfirst(str_replace('_', ' ', $entityType)) . ' #' . $entityId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'url' => $url,
            ]],
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return $this->isReadOnly();
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getFieldClassification(string $action = ''): array
    {
        // url embeds the admin secret key (/key/<hash>/), so it is tokenised (#107): the provider
        // only ever sees [url_N] while display rehydration hands the admin the real clickable link.
        // entity_id can be a bare customer or order id, so it is tokenised too.
        return [
            'label' => [PiiClass::PUBLIC],
            'category' => [PiiClass::PUBLIC],
            'url' => [PiiClass::TOKENISE, 'url'],
            'entity_type' => [PiiClass::PUBLIC],
            'entity_id' => [PiiClass::TOKENISE, 'entity'],
            'message' => [PiiClass::PUBLIC],
        ];
    }

    /**
     * Direct-link mode hands back a working, secret-key-bearing admin link to a named record, so
     * it is gated by the resource guarding that entity's own admin screen - the way every other
     * lookup in this codebase is. The link does not bypass the target page's access control when
     * clicked, but building one still confirms the record exists and hands a valid deep link to it.
     *
     * Search mode only names the standard admin pages this module ships a registry of and carries
     * no entity to gate on, so any logged-in admin may use it: Magento's root admin resource.
     */
    public function getMagentoAcl(array $input = []): string
    {
        $entityType = (string)($input['entity_type'] ?? '');
        if ($entityType === '' || empty($input['entity_id'])) {
            return 'Magento_Backend::admin';
        }

        // An entity type the map does not know builds no link: execute() answers "Unknown entity
        // type", which says more than a permission error would. A known type whose route Magento
        // resolves nothing for stays closed - that one cannot be told apart from a wrong gate.
        if ($this->entityRouteMap->getRoute($entityType) === null) {
            return 'Magento_Backend::admin';
        }

        return $this->entityRouteMap->getAclResource($entityType) ?? '';
    }
}

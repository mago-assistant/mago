<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Navigation;

class PageRegistry
{
    /**
     * [route, label, category, keywords[]]
     *
     * The first segment of a route is Magento's route id ("adminhtml"), not the front name in the
     * URL ("admin"): the secret key is signed with it, and a key signed for the front name is
     * refused on arrival with a redirect to the dashboard.
     */
    private const PAGES = [
        // Dashboard
        ['adminhtml/dashboard', 'Dashboard', 'Dashboard', ['dashboard', 'home', 'overview', 'start']],

        // Sales
        ['sales/order', 'Orders', 'Sales', ['orders', 'order', 'sales', 'purchases', 'bestellingen']],
        ['sales/invoice', 'Invoices', 'Sales', ['invoices', 'invoice', 'billing', 'facturen']],
        ['sales/shipment', 'Shipments', 'Sales', ['shipments', 'shipment', 'shipping', 'delivery', 'verzendingen']],
        ['sales/creditmemo', 'Credit Memos', 'Sales', ['credit', 'memo', 'refund', 'return', 'creditnota']],

        // Catalog
        ['catalog/product', 'Products', 'Catalog', ['products', 'product', 'catalog', 'items', 'producten', 'artikelen']],
        ['catalog/product/new', 'Add New Product', 'Catalog', ['add', 'new', 'create', 'product', 'nieuw']],
        ['catalog/category', 'Categories', 'Catalog', ['categories', 'category', 'categorieën']],
        ['catalog_rule/promo_catalog', 'Catalog Price Rules', 'Catalog', ['catalog', 'price', 'rule', 'discount', 'promo', 'korting']],

        // Customers
        ['customer/index', 'All Customers', 'Customers', ['customers', 'customer', 'users', 'klanten']],
        ['customer/group', 'Customer Groups', 'Customers', ['customer', 'group', 'groups', 'klantengroepen']],

        // Marketing
        ['sales_rule/promo_quote', 'Cart Price Rules', 'Marketing', ['cart', 'price', 'rule', 'coupon', 'discount', 'promo', 'korting', 'kortingscode']],
        ['newsletter/subscriber', 'Newsletter Subscribers', 'Marketing', ['newsletter', 'subscriber', 'email', 'nieuwsbrief']],
        ['search/term', 'Search Terms', 'Marketing', ['search', 'terms', 'zoekterm']],
        ['adminhtml/url_rewrite', 'URL Rewrites', 'Marketing', ['url', 'rewrite', 'redirect', 'seo']],

        // Content
        ['cms/page', 'CMS Pages', 'Content', ['cms', 'page', 'pages', 'content', 'pagina']],
        ['cms/block', 'CMS Blocks', 'Content', ['cms', 'block', 'blocks', 'static', 'blokken']],
        ['theme/design_config', 'Design Configuration', 'Content', ['design', 'theme', 'layout', 'thema']],

        // Reports
        ['reports/report_product/viewed', 'Product Views Report', 'Reports', ['report', 'product', 'views', 'popular', 'bekeken']],
        ['reports/report_sales/sales', 'Sales Report', 'Reports', ['report', 'sales', 'revenue', 'omzet']],
        ['reports/report_customer/accounts', 'Customer Report', 'Reports', ['report', 'customer', 'accounts', 'klanten']],

        // Stores — Configuration sections
        ['adminhtml/system_config', 'Configuration (All)', 'Stores', ['configuration', 'config', 'settings', 'instellingen', 'configuratie']],
        ['adminhtml/system_config/edit/section/general', 'General Settings', 'Stores', ['general', 'store', 'name', 'country', 'locale', 'winkelnaam', 'naam', 'land', 'taal']],
        ['adminhtml/system_config/edit/section/web', 'Web Settings', 'Stores', ['web', 'url', 'base', 'secure', 'unsecure', 'domain']],
        ['adminhtml/system_config/edit/section/catalog', 'Catalog Settings', 'Stores', ['catalog', 'settings', 'layered', 'navigation', 'search']],
        ['adminhtml/system_config/edit/section/sales', 'Sales Settings', 'Stores', ['sales', 'settings', 'minimum', 'order']],
        ['adminhtml/system_config/edit/section/sales_email', 'Sales Emails', 'Stores', ['sales', 'email', 'template', 'order', 'confirmation', 'bevestiging']],
        ['adminhtml/system_config/edit/section/customer', 'Customer Settings', 'Stores', ['customer', 'account', 'settings', 'registration', 'login']],
        ['adminhtml/system_config/edit/section/carriers', 'Shipping Methods', 'Stores', ['shipping', 'carrier', 'carriers', 'methods', 'delivery', 'verzendmethoden', 'verzending']],
        ['adminhtml/system_config/edit/section/payment', 'Payment Methods', 'Stores', ['payment', 'methods', 'pay', 'betaalmethoden', 'betaling']],
        ['adminhtml/system_config/edit/section/tax', 'Tax Settings', 'Stores', ['tax', 'vat', 'btw', 'belasting']],
        ['adminhtml/system_config/edit/section/currency', 'Currency Settings', 'Stores', ['currency', 'rate', 'exchange', 'valuta']],
        ['adminhtml/system_config/edit/section/trans_email', 'Store Email Addresses', 'Stores', ['store', 'email', 'address', 'sender', 'afzender']],
        ['adminhtml/system_store', 'All Stores', 'Stores', ['stores', 'store', 'views', 'websites', 'winkels']],
        ['tax/rule', 'Tax Rules', 'Stores', ['tax', 'rule', 'rules', 'btw', 'belastingregel']],
        ['tax/rate', 'Tax Rates', 'Stores', ['tax', 'rate', 'rates', 'btw', 'tarief']],

        // System
        ['adminhtml/cache', 'Cache Management', 'System', ['cache', 'flush', 'clean', 'refresh', 'legen']],
        ['indexer/indexer/list', 'Index Management', 'System', ['index', 'indexer', 'reindex', 'indexeren']],
        ['adminhtml/user', 'Admin Users', 'System', ['admin', 'user', 'users', 'beheerder', 'gebruikers']],
        ['adminhtml/user_role', 'Admin Roles', 'System', ['admin', 'role', 'roles', 'permissions', 'acl', 'rechten']],
        ['adminhtml/integration', 'Integrations', 'System', ['integration', 'api', 'integratie']],
        ['backup/index', 'Backups', 'System', ['backup', 'backups', 'restore']],
    ];

    private array $pages;

    /**
     * @param array $additionalPages Additional pages [route, label, category, keywords[]]. The route
     *                               starts with the route id ("adminhtml/..."), not the front name
     *                               ("admin/..."): a link signed for the front name is still offered
     *                               but redirects to the dashboard.
     */
    public function __construct(array $additionalPages = [])
    {
        $this->pages = array_merge(self::PAGES, $additionalPages);
    }

    /**
     * Search pages by query string with keyword scoring
     */
    public function search(string $query, int $limit = 5): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        $terms = preg_split('/[\s,]+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scored = [];

        foreach ($this->pages as $page) {
            [$route, $label, $category, $keywords] = $page;
            $score = 0;

            foreach ($terms as $term) {
                // Exact keyword match
                if (in_array($term, $keywords, true)) {
                    $score += 20;
                    continue;
                }

                // Partial keyword match
                foreach ($keywords as $keyword) {
                    if (str_contains($keyword, $term) || str_contains($term, $keyword)) {
                        $score += 10;
                        break;
                    }
                }

                // Label match
                if (str_contains(mb_strtolower($label), $term)) {
                    $score += 15;
                }

                // Category match
                if (str_contains(mb_strtolower($category), $term)) {
                    $score += 5;
                }
            }

            if ($score > 0) {
                $scored[] = [
                    'route' => $route,
                    'label' => $label,
                    'category' => $category,
                    'score' => $score,
                ];
            }
        }

        usort($scored, fn(array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Get all pages, optionally filtered by category
     */
    public function getAll(?string $category = null): array
    {
        $results = [];
        foreach ($this->pages as $page) {
            [$route, $label, $cat] = $page;

            if ($category !== null && mb_strtolower($cat) !== mb_strtolower($category)) {
                continue;
            }

            $results[] = [
                'route' => $route,
                'label' => $label,
                'category' => $cat,
            ];
        }

        return $results;
    }
}

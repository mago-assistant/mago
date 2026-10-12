<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Seo;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class UrlRewriteManager extends AbstractSkill
{
    private const INTERNAL_PATH_PATTERN = '#\A/?(?![/\\\\])[^\x00-\x20\x7F\\\\:/?\#]*(?:[/?\#][^\x00-\x20\x7F\\\\]*)?\z#';

    public function getName(): string
    {
        return 'url_rewrite_manager';
    }

    protected function getBaseDescription(): string
    {
        return 'Manage URL rewrites and redirects: search existing rewrites, create redirects, and delete custom rewrites.';
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_UrlRewrite::urlrewrite';
    }

    public function isIrreversibleAction(array $input): bool
    {
        return $this->isExternalRedirect($input) || parent::isIrreversibleAction($input);
    }

    public function getImpacts(array $input, int $adminUserId): array
    {
        if (!$this->isExternalRedirect($input)) {
            return parent::getImpacts($input, $adminUserId);
        }

        $requestPath = ltrim(trim((string)($input['request_path'] ?? '')), '/');
        $target = trim((string)($input['target_path'] ?? ''));
        $host = (string)(parse_url($target, PHP_URL_HOST) ?: $target);

        return [
            sprintf('Sends storefront visitors of "/%s" to the external site %s.', $requestPath, $host),
            'They leave your store for that address whenever they open that URL, until the rewrite is removed.',
        ];
    }

    /**
     * A create whose target is not unmistakably an internal store path. Fail closed: anything that is not
     * a plain relative path (optionally with one leading slash, no scheme, no authority, no backslash,
     * no whitespace or control characters) is treated as leaving the store.
     */
    private function isExternalRedirect(array $input): bool
    {
        if (($input['action'] ?? '') !== 'create') {
            return false;
        }

        $target = (string)($input['target_path'] ?? '');

        return $target !== '' && !$this->isInternalPath($target);
    }

    private function isInternalPath(string $target): bool
    {
        return preg_match(self::INTERNAL_PATH_PATTERN, $target) === 1;
    }

    protected function getBaseInstructions(): string
    {
        return 'URL rewrites control how URLs map to Magento entities. '
            . 'Types: "custom" (user-created), "product", "category", "cms-page" (auto-generated). '
            . 'Only delete "custom" type rewrites — auto-generated ones regenerate via indexer. '
            . 'Redirect types: 0 = internal rewrite (no redirect), 301 = permanent, 302 = temporary. '
            . 'Always use 301 for permanent URL changes, 302 for temporary campaigns. '
            . 'Every result carries an admin_url, masked as a token like [url_1]. Always include it as a '
            . 'markdown link, writing the token exactly as it came back; the panel swaps the real address '
            . 'in for the admin. Never write an admin url yourself: one you assembled is missing the '
            . 'secret key and opens nothing.';
    }
}

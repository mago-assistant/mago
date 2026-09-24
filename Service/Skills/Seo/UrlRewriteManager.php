<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Seo;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class UrlRewriteManager extends AbstractSkill
{
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

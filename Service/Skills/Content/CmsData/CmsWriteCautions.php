<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Content\CmsData;

/**
 * Caution lines for CMS page and block writes
 *
 * Magento renders CMS content to storefront visitors as written, without filtering it, and the
 * content can come from text the assistant read earlier (an order note, a review). So the card
 * says what goes live, for whom, and calls out HTML that runs code in the visitor's browser.
 */
class CmsWriteCautions
{
    /** Markup that runs code or loads other pages in a visitor's browser */
    private const ACTIVE_HTML
        = '~<\s*(script|iframe|object|embed|form|meta|link|base)\b|<[^>]*[\s/"\']on[a-z]+\s*=|javascript\s*:~i';

    public const PAGE = 'page';
    public const BLOCK = 'block';

    /**
     * Cautions for creating a page or block
     *
     * @param string $type PAGE or BLOCK
     * @param array $params The create call's parameters
     * @param string $storeLabel Which store views it is for
     * @return string[]
     */
    public function forCreate(string $type, array $params, string $storeLabel): array
    {
        $identifier = (string)($params['identifier'] ?? '');
        $cautions = [];
        if ($params['is_active'] ?? true) {
            $cautions[] = $type === self::PAGE
                ? sprintf(
                    'Page "%s" goes live right away for every storefront visitor in %s, with its HTML exactly as '
                    . 'written: Magento does not filter CMS content.',
                    $identifier,
                    $storeLabel
                )
                : sprintf(
                    'Block "%s" is enabled: wherever the storefront already uses that identifier (a widget, a '
                    . 'layout or a page) every visitor sees it right away, with its HTML exactly as written.',
                    $identifier
                );
        }

        return array_merge($cautions, $this->forContent($params['content'] ?? null));
    }

    /**
     * Cautions for changing an existing page or block
     *
     * @param string $type PAGE or BLOCK
     * @param array $current The page or block as it is now (identifier, is_active)
     * @param array $params The update call's parameters
     * @param bool|null $requestedActive The status the call sets, null when it keeps it
     * @return string[]
     */
    public function forUpdate(string $type, array $current, array $params, ?bool $requestedActive): array
    {
        $identifier = (string)($current['identifier'] ?? $params['identifier'] ?? '');
        $isActive = (bool)($current['is_active'] ?? false);
        $where = $type === self::PAGE ? 'on the storefront' : 'everywhere the block is used on the storefront';
        // A block's title is only shown in the admin; a page's is its storefront title.
        $changesContent = !empty($params['content']) || ($type === self::PAGE && !empty($params['title']));
        $cautions = [];

        if ($requestedActive === false && $isActive) {
            $cautions[] = sprintf(
                'Takes %s "%s" off the storefront right away%s.',
                $type,
                $identifier,
                $type === self::PAGE ? ': visitors get a "page not found"' : ''
            );
        } elseif ($requestedActive === true && !$isActive) {
            $cautions[] = sprintf(
                'Enables %s "%s": every visitor sees it %s right away.',
                $type,
                $identifier,
                $where
            );
        } elseif ($isActive && $changesContent) {
            $cautions[] = sprintf(
                'Replaces the live %s of %s "%s" for every visitor %s right away, with its HTML exactly as '
                . 'written: Magento does not filter CMS content.',
                empty($params['content']) ? 'title' : 'content',
                $type,
                $identifier,
                $where
            );
        }

        return array_merge($cautions, $this->forContent($params['content'] ?? null));
    }

    /**
     * A line when the content holds markup that runs code in a visitor's browser
     *
     * @param mixed $content
     * @return string[]
     */
    private function forContent(mixed $content): array
    {
        if (!is_string($content) || !preg_match(self::ACTIVE_HTML, $content)) {
            return [];
        }

        return [
            'The content holds scripts, frames, forms or event handlers. They run in every visitor\'s browser: '
            . 'only allow this when you wrote or checked that code yourself.',
        ];
    }
}

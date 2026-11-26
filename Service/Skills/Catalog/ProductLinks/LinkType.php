<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

/**
 * The product links this skill manages. Grouped children ("associated") live in the same table but
 * decide what a grouped product sells, so they are deliberately not a case here.
 */
enum LinkType: string
{
    case Related = 'related';
    case Upsell = 'upsell';
    case Crosssell = 'crosssell';

    public const ASSOCIATED = 'associated';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Related => 'related',
            self::Upsell => 'up-sell',
            self::Crosssell => 'cross-sell',
        };
    }

    /**
     * An up-sell points at a better product than the one shown; two products cannot each be the
     * better one, so only related and cross-sell links make sense in both directions.
     */
    public function canLinkBothWays(): bool
    {
        return $this !== self::Upsell;
    }
}

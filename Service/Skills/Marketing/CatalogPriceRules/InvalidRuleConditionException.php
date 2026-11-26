<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

/**
 * A rule condition the model proposed that cannot be saved as asked: an unknown or ambiguous
 * attribute or option, an attribute not enabled for promo rules, or a malformed condition. The
 * message is written for the model, which passes it on or asks the administrator.
 */
class InvalidRuleConditionException extends \InvalidArgumentException
{
}

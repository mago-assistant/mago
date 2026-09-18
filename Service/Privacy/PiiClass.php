<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Privacy;

/**
 * The three ways a tool-output field may cross to the LLM (issue #97). A field the classification
 * does not name is treated as STRIP: undeclared is never public.
 */
final class PiiClass
{
    public const PUBLIC = 'public';
    public const TOKENISE = 'tokenise';
    public const STRIP = 'strip';

    /**
     * Wildcard field name in a classification map: the rule for every key the map does not name.
     * For tools whose output keys are dynamic (config paths, attribute codes) and cannot be
     * enumerated; declaring it is an explicit assertion about all of them.
     */
    public const ANY = '*';
}

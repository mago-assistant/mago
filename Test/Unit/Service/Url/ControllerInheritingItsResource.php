<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Url;

/**
 * Stands in for the other two thirds: an admin controller that declares no resource of its own and
 * takes its module's, which is what AbstractAction::_isAllowed() reads through `static::`.
 */
class ControllerInheritingItsResource extends ControllerWithOwnResource
{
}

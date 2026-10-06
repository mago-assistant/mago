<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Conversation;

/**
 * A pending write that can no longer be confirmed or rejected. The message is written for the
 * admin, so the error reporter passes it through instead of replacing it with a reference.
 */
abstract class ConfirmationUnavailableException extends \RuntimeException
{
}

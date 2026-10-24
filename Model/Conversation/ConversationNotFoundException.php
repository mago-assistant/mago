<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Conversation;

/**
 * A conversation or message that does not exist or belongs to another admin. Still an
 * InvalidArgumentException, which is what the repository has always promised its callers; the
 * own type lets the message reach the admin while other exceptions are reported generically.
 */
class ConversationNotFoundException extends \InvalidArgumentException
{
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

/**
 * The transaction nesting of the database connection that services and the chat share.
 */
interface ConnectionTransactionInterface
{
    public function getLevel(): int;

    public function rollBack(): void;
}

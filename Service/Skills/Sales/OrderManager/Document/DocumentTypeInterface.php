<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document;

interface DocumentTypeInterface
{
    public function getAclResource(): string;

    public function list(array $filters): DocumentList;
}

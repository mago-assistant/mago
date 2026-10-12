<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\App\ProductMetadataInterface;

final class FakeProductMetadata implements ProductMetadataInterface
{
    public function getVersion(): string
    {
        return '2.4.8';
    }

    public function getEdition(): string
    {
        return 'Community';
    }

    public function getName(): string
    {
        return 'Magento';
    }
}

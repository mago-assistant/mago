<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess\Guard;

use MagoAssistant\Mago\Service\Api\InProcess\Guard\CmsPageDesignGuard;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\ProductDesignGuard;
use MagoAssistant\Mago\Service\Api\InProcess\ServiceDispatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The design guards stand in for Magento's webapi_rest-only ProductAuthorization and PageAclPlugin. If
 * etc/di.xml stopped handing them to the dispatcher, any admin could change a page or product design
 * through the chat.
 */
final class ProductionDesignGuardsTest extends TestCase
{
    private const DI_XML = __DIR__ . '/../../../../../../etc/di.xml';

    #[Test]
    public function itRegistersBothDesignGuardsWithTheDispatcher(): void
    {
        $guardTypes = $this->getRegisteredGuardTypes();

        self::assertContains(ProductDesignGuard::class, $guardTypes);
        self::assertContains(CmsPageDesignGuard::class, $guardTypes);
    }

    /**
     * @return string[]
     */
    private function getRegisteredGuardTypes(): array
    {
        $document = new \DOMDocument();
        $document->load(self::DI_XML);
        $guards = (new \DOMXPath($document))->query(
            '/config/type[@name="' . ServiceDispatcher::class . '"]/arguments/argument[@name="guards"]/item'
        );

        return array_map(
            static fn (\DOMNode $item): string => trim((string)$item->textContent),
            iterator_to_array($guards === false ? [] : $guards)
        );
    }
}

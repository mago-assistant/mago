<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Tool\Verify;

use MagoAssistant\Mago\Service\Tool\Verify\AclResourceIndex;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclResourceProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AclResourceIndexTest extends TestCase
{
    #[Test]
    public function itFindsAResourceAtAnyDepthOfTheTree(): void
    {
        $index = new AclResourceIndex(new FakeAclResourceProvider([[
            'id' => 'Magento_Backend::admin',
            'children' => [[
                'id' => 'Magento_Sales::sales',
                'children' => [['id' => 'Magento_Sales::actions_view', 'children' => []]],
            ]],
        ]]));

        self::assertTrue($index->has('Magento_Backend::admin'));
        self::assertTrue($index->has('Magento_Sales::actions_view'));
    }

    #[Test]
    public function itDoesNotFindAResourceNoAclXmlDeclares(): void
    {
        $index = new AclResourceIndex(FakeAclResourceProvider::withResources('Magento_Sales::actions_view'));

        self::assertFalse($index->has('Magento_Sales::action_view'));
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\LayeredNavigation;

use MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation\FilterAttributes;
use MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation\MergeOptionsAction;
use MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation\OptionMerger;
use MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation\OptionSelection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MergeOptionsActionTest extends TestCase
{
    private const RESOLUTION = [
        'attribute_id' => 9,
        'code' => 'resolution',
        'label' => 'Resolution',
        'input' => 'select',
        'backend_type' => 'int',
        'is_filterable' => 1,
        'is_filterable_in_search' => false,
        'position' => 3,
        'is_user_defined' => true,
    ];

    private const OPTIONS = [101 => '8 MP', 102 => '8MP', 103 => '8mp'];

    #[Test]
    public function theCardPreviewsWhichProductsChange(): void
    {
        $impacts = $this->action()->getImpacts(
            ['attribute' => 'resolution', 'target_option_id' => 102, 'option_ids' => [101, 103]],
            1
        );

        self::assertSame(
            '7 product(s) change from "8 MP" to "8MP" in every store view: CAM-1, CAM-2, CAM-3, CAM-4, CAM-5 and 2 '
            . 'more.',
            $impacts[0]
        );
        self::assertSame('"8mp" has no products; it is only deleted.', $impacts[1]);
        self::assertSame(
            'Deletes the option(s) "8 MP", "8mp" of "Resolution", with their store view labels and swatches.',
            $impacts[2]
        );
    }

    #[Test]
    public function theKeptOptionCannotAlsoBeMergedAway(): void
    {
        $refusal = $this->action()->findRefusal(
            ['attribute' => 'resolution', 'target_option_id' => 102, 'option_ids' => [101, 102]]
        );

        self::assertSame('The option to keep cannot also be merged away.', $refusal['error']);
    }

    #[Test]
    public function aMergeWithoutAKeptOptionIsRefused(): void
    {
        $refusal = $this->action()->findRefusal(['attribute' => 'resolution', 'option_ids' => [101]]);

        self::assertStringContainsString('Give "target_option_id"', $refusal['error']);
    }

    #[Test]
    public function anAttributeConfigurablesUseForTheirVariantsIsRefused(): void
    {
        $refusal = $this->action(true)->findRefusal(
            ['attribute' => 'resolution', 'target_option_id' => 102, 'option_ids' => [101]]
        );

        self::assertStringContainsString('Configurable products use "resolution"', $refusal['error']);
    }

    private function action(bool $usedByConfigurables = false): MergeOptionsAction
    {
        $skus = [];
        foreach (range(1, 7) as $id) {
            $skus[$id] = 'CAM-' . $id;
        }
        $filterAttributes = $this->createStub(FilterAttributes::class);
        $filterAttributes->method('attribute')->willReturn(self::RESOLUTION);
        $filterAttributes->method('options')->willReturn(self::OPTIONS);
        $filterAttributes->method('productsByOption')->willReturn([101 => $skus]);
        $filterAttributes->method('isConfigurableAttribute')->willReturn($usedByConfigurables);

        return new MergeOptionsAction(
            $filterAttributes,
            new OptionSelection($filterAttributes),
            $this->createStub(OptionMerger::class)
        );
    }
}

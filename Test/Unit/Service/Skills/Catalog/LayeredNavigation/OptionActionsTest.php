<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\LayeredNavigation;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Attribute as AttributeResource;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation\CleanupOptionsAction;
use MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation\DeleteOptionsAction;
use MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation\FilterAttributes;
use MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation\OptionSelection;
use MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation\UpdateFilterAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * cleanup_options, delete_options and update_filter against a resolution attribute with duplicates
 */
class OptionActionsTest extends TestCase
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

    /** The resolution filter's current settings, as a model sends them along */
    private const UNCHANGED = [
        'attribute' => 'resolution',
        'filterable' => 'with_results',
        'filterable_in_search' => false,
        'position' => 3,
    ];

    private const OPTIONS = [101 => '8 MP', 102 => '8MP', 103 => '8mp', 104 => '12 MP', 105 => '24 MP'];

    /** Option id => product count; 24 MP has none */
    private const COUNTS = [101 => 4, 102 => 9, 103 => 1, 104 => 6];

    /**
     * @return array<string, array{string, string}>
     */
    public static function sameValues(): array
    {
        return [
            'spaces and case' => ['8 MP', '8mp'],
            'hyphen' => ['Full-Grain Leather', 'full grain leather'],
            'accent' => ['Bouclé', 'Boucle'],
            'slash and dot' => ['Oak/Matte Black', 'oak matte. black'],
        ];
    }

    #[Test]
    #[DataProvider('sameValues')]
    public function labelsWrittenDifferentlyAreTheSameValue(string $one, string $other): void
    {
        self::assertSame(CleanupOptionsAction::normalize($one), CleanupOptionsAction::normalize($other));
    }

    #[Test]
    public function differentValuesStayApart(): void
    {
        self::assertNotSame(CleanupOptionsAction::normalize('8 MP'), CleanupOptionsAction::normalize('18 MP'));
    }

    #[Test]
    public function cleanupGroupsDuplicatesAndSuggestsTheOptionWithMostProducts(): void
    {
        $result = (new CleanupOptionsAction($this->filterAttributes()))->execute(['attribute' => 'resolution'], 1);

        self::assertCount(1, $result['duplicate_groups']);
        self::assertSame(102, $result['duplicate_groups'][0]['suggested_target']);
        self::assertSame([102, 101, 103], array_column($result['duplicate_groups'][0]['options'], 'option_id'));
        self::assertSame([['option_id' => 105, 'label' => '24 MP']], $result['unused_options']);
    }

    #[Test]
    public function deletingAnOptionProductsStillUseIsRefused(): void
    {
        $refusal = $this->deleteAction()->findRefusal(['attribute' => 'resolution', 'option_ids' => [105, 103]]);

        self::assertSame(
            'Products still use "8mp" (1 products). Merge them into another option with merge_options instead.',
            $refusal['error']
        );
    }

    #[Test]
    public function deletingUnusedOptionsNamesThemOnTheCard(): void
    {
        $action = $this->deleteAction();
        $params = ['attribute' => 'resolution', 'option_ids' => [105]];

        self::assertNull($action->findRefusal($params));
        self::assertSame('Deletes 1 option(s) of "Resolution": "24 MP".', $action->getImpacts($params, 1)[0]);
    }

    #[Test]
    public function anOptionOfAnotherAttributeIsRefused(): void
    {
        $refusal = $this->deleteAction()->findRefusal(['attribute' => 'resolution', 'option_ids' => [999]]);

        self::assertStringContainsString('has no option with id 999', $refusal['error']);
    }

    /**
     * @return array<string, array{array, string}>
     */
    public static function refusedUpdates(): array
    {
        return [
            'nothing given' => [['attribute' => 'resolution'], 'Nothing changes'],
            'only current values' => [
                self::UNCHANGED,
                'already has these settings',
            ],
            'unknown attribute' => [['attribute' => 'lens', 'position' => 1], 'no product attribute "lens"'],
            'bad level' => [['attribute' => 'resolution', 'filterable' => 'yes'], 'with_results, no_results or no'],
            'negative position' => [['attribute' => 'resolution', 'position' => -1], 'whole number of 0 or more'],
            'text field as filter' => [
                ['attribute' => 'description', 'filterable' => 'with_results'],
                'textarea field',
            ],
        ];
    }

    #[Test]
    #[DataProvider('refusedUpdates')]
    public function anUpdateThatCannotWorkIsRefusedBeforeTheCard(array $params, string $message): void
    {
        $refusal = $this->updateAction()->findRefusal($params);

        self::assertNotNull($refusal);
        self::assertStringContainsString($message, $refusal['error']);
    }

    #[Test]
    public function valuesSentAlongUnchangedAreNotChangesOnTheCard(): void
    {
        $cautions = $this->updateAction()->getCautions(
            ['position' => 5] + self::UNCHANGED,
            1
        );

        self::assertSame(['Filter "Resolution" moves from position 3 to 5.'], $cautions);
    }

    #[Test]
    public function theCardSaysWhenAFilterDisappears(): void
    {
        $cautions = $this->updateAction()->getCautions(
            ['attribute' => 'resolution', 'filterable' => 'no', 'filterable_in_search' => true, 'position' => 3],
            1
        );

        self::assertSame(
            [
                'Filter "Resolution" on category pages: with_results becomes no (the filter disappears from every '
                . 'category page).',
                'Filter "Resolution" on search result pages: turned on.',
            ],
            $cautions
        );
    }

    private function deleteAction(): DeleteOptionsAction
    {
        $filterAttributes = $this->filterAttributes();

        return new DeleteOptionsAction(
            $filterAttributes,
            new OptionSelection($filterAttributes),
            $this->createStub(AttributeOptionManagementInterface::class)
        );
    }

    private function updateAction(): UpdateFilterAction
    {
        return new UpdateFilterAction(
            $this->filterAttributes(),
            $this->createStub(ProductAttributeRepositoryInterface::class),
            $this->createStub(AttributeResource::class)
        );
    }

    private function filterAttributes(): FilterAttributes
    {
        $description = [
            'attribute_id' => 10,
            'code' => 'description',
            'label' => 'Description',
            'input' => 'textarea',
            'is_filterable' => 0,
        ] + self::RESOLUTION;
        $filterAttributes = $this->createStub(FilterAttributes::class);
        $filterAttributes->method('attribute')->willReturnCallback(
            static fn (string $code) => ['resolution' => self::RESOLUTION, 'description' => $description][$code] ?? null
        );
        $filterAttributes->method('options')->willReturn(self::OPTIONS);
        $filterAttributes->method('optionProductCounts')->willReturn(self::COUNTS);

        return $filterAttributes;
    }
}

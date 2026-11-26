<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\AttributeFillRateReport;
use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\AttributeFillRateScope;

/**
 * Answers from fixed attribute rows and records the scope every build received.
 *
 * @phpstan-import-type FillRow from AttributeFillRateReport
 */
final class FakeAttributeFillRateReport extends AttributeFillRateReport
{
    /** @var list<AttributeFillRateScope> */
    public array $scopes = [];

    /** @var list<FillRow> */
    private array $rows = [];

    private int $productCount = 0;

    private int $withoutProducts = 0;

    /**
     * @param array<int,string> $attributeSets Attribute set id => name
     */
    public function __construct(private readonly array $attributeSets = [4 => 'Default'])
    {
    }

    public function withAttribute(string $code, int $filled, int $applicable, bool $isCustom = true): self
    {
        $this->rows[] = [
            'attribute_code' => $code,
            'label' => ucfirst($code),
            'input_type' => 'text',
            'value_scope' => 'store_view',
            'is_required' => false,
            'is_custom' => $isCustom,
            'filled_count' => $filled,
            'applicable_count' => $applicable,
            'empty_count' => $applicable - $filled,
            'fill_rate' => round($filled / $applicable * 100, 1),
        ];
        $this->productCount = max($this->productCount, $applicable);

        return $this;
    }

    public function withAttributesWithoutProducts(int $count): self
    {
        $this->withoutProducts = $count;

        return $this;
    }

    public function build(AttributeFillRateScope $scope): array
    {
        $this->scopes[] = $scope;

        return [
            'product_count' => $this->productCount,
            'attributes_without_products' => $this->withoutProducts,
            'attributes' => $this->rows,
        ];
    }

    public function attributeSets(): array
    {
        return $this->attributeSets;
    }
}

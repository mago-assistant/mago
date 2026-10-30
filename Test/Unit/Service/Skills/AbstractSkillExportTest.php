<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills;

use MagoAssistant\Mago\Api\Data\ExportColumn;
use MagoAssistant\Mago\Service\Export\ExportRefusedException;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeExportableAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeSkill;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #298: a skill offers the download of the named action's full result when that action is
 * exportable, and exports under the same ACL as it executes.
 */
final class AbstractSkillExportTest extends TestCase
{
    private const SKILL_ACL = 'Magento_Catalog::products';
    private const ACTION_ACL = 'Magento_Reports::report_products';

    private FakeExportableAction $lowStock;

    protected function setUp(): void
    {
        $this->lowStock = new FakeExportableAction('low_stock', [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']], true, self::ACTION_ACL);
    }

    #[Test]
    public function itExportsOnlyReadOnlyActionsThatAreExportable(): void
    {
        $skill = $this->skill([self::SKILL_ACL, self::ACTION_ACL]);

        self::assertTrue($skill->isExportableAction(['action' => 'low_stock']));
        self::assertFalse($skill->isExportableAction(['action' => 'get']));
        self::assertFalse($skill->isExportableAction(['action' => 'bulk_update']));
        self::assertFalse($skill->isExportableAction(['action' => 'unknown']));
        self::assertFalse($skill->isExportableAction([]));
    }

    #[Test]
    public function itHandsTheCallAndTheAdminToTheNamedAction(): void
    {
        $rows = iterator_to_array(
            $this->skill([self::SKILL_ACL, self::ACTION_ACL])->exportRows(['action' => 'low_stock', '_admin_user_id' => 7], 2),
            false
        );

        self::assertSame([['sku' => 'A'], ['sku' => 'B']], $rows);
        self::assertSame(7, $this->lowStock->exports[0]['admin_user_id']);
        self::assertSame(2, $this->lowStock->exports[0]['max_rows']);
    }

    #[Test]
    public function itTakesTheColumnsFromTheNamedAction(): void
    {
        $columns = $this->skill([self::SKILL_ACL, self::ACTION_ACL])->getExportColumns(['action' => 'low_stock']);

        self::assertSame(['sku', 'qty'], array_map(static fn (ExportColumn $column): string => $column->key, $columns));
    }

    #[Test]
    public function itTakesTheTitleAndRowCountFromTheNamedAction(): void
    {
        $skill = $this->skill([self::SKILL_ACL, self::ACTION_ACL]);

        self::assertSame('Full low_stock list', $skill->getExportTitle(['action' => 'low_stock']));
        self::assertSame(3, $skill->countExportRows(['action' => 'low_stock', '_admin_user_id' => 7]));
    }

    #[Test]
    public function itRefusesToCountWithoutTheActionsOwnResource(): void
    {
        $this->expectException(ExportRefusedException::class);

        $this->skill([self::SKILL_ACL])->countExportRows(['action' => 'low_stock']);
    }

    #[Test]
    public function itRefusesTheExportWithoutTheActionsOwnResource(): void
    {
        $this->expectException(ExportRefusedException::class);

        $this->skill([self::SKILL_ACL])->exportRows(['action' => 'low_stock'], 10);
    }

    #[Test]
    public function itRefusesToExportAnActionThatIsNotExportable(): void
    {
        $this->expectException(ExportRefusedException::class);

        $this->skill([self::SKILL_ACL, self::ACTION_ACL])->exportRows(['action' => 'get'], 10);
    }

    /**
     * @param list<string> $allowedResources
     */
    private function skill(array $allowedResources): FakeSkill
    {
        return new FakeSkill('product_data', new FakeAclAuthorization($allowedResources), [
            'low_stock' => $this->lowStock,
            'get' => new FakeAction('get', true),
            'bulk_update' => new FakeExportableAction('bulk_update', [], false),
        ], self::SKILL_ACL);
    }
}

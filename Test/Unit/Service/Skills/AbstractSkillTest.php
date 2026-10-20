<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills;

use MagoAssistant\Mago\Test\Unit\Fakes\FakeAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeSkill;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AbstractSkillTest extends TestCase
{
    private const UNDECLARED_IS_ACTIVE = 'The update_block action does not support is_active, so nothing was '
        . 'changed. It accepts: identifier, content. If the request cannot be done with those, tell the '
        . 'administrator instead of reporting success.';

    #[Test]
    public function itRefusesAWriteWithAParameterTheActionDoesNotDeclareBeforeConfirmation(): void
    {
        $refusal = $this->skill()->findRefusal(
            ['action' => 'update_block', 'identifier' => 'footer', 'is_active' => false]
        );

        self::assertSame(['error' => self::UNDECLARED_IS_ACTIVE], $refusal);
    }

    #[Test]
    public function itDoesNotExecuteAWriteWithAParameterTheActionDoesNotDeclare(): void
    {
        $result = $this->skill()->execute(
            ['action' => 'update_block', 'identifier' => 'footer', 'is_active' => false, '_admin_user_id' => 3]
        );

        self::assertSame(['error' => self::UNDECLARED_IS_ACTIVE], $result);
    }

    #[Test]
    public function itExecutesAWriteThatOnlyUsesDeclaredInternalAndEmptyParameters(): void
    {
        $result = $this->skill()->execute(
            ['action' => 'update_block', 'identifier' => 'footer', 'is_active' => null, '_admin_user_id' => 3]
        );

        self::assertSame(['executed' => 'update_block', 'admin_user_id' => 3], $result);
    }

    #[Test]
    public function itLeavesReadsWithUndeclaredParametersAlone(): void
    {
        $result = $this->skill()->execute(['action' => 'list_blocks', 'is_active' => true]);

        self::assertSame(['executed' => 'list_blocks', 'admin_user_id' => 0], $result);
    }

    private function skill(): FakeSkill
    {
        return new FakeSkill('cms_data', new FakeAuthorization(), [
            'list_blocks' => new FakeAction('list_blocks', true),
            'update_block' => new FakeAction('update_block', false, [
                'identifier' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ]),
        ]);
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;

/**
 * Mixed read/write skill built from FakeAction instances
 */
final class FakeSkill extends AbstractSkill
{
    /**
     * @param ActionInterface[] $actions keyed by action name
     */
    public function __construct(
        private readonly string $name,
        AuthorizationInterface $authorization,
        array $actions,
        private readonly string $magentoAcl = 'Magento_Backend::admin'
    ) {
        parent::__construct($authorization, $actions);
    }

    public function getMagentoAcl(array $input = []): string
    {
        return $this->magentoAcl;
    }

    public function getName(): string
    {
        return $this->name;
    }

    protected function getBaseDescription(): string
    {
        return 'Fake ' . $this->name . '.';
    }
}

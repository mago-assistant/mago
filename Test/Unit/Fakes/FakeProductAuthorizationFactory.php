<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Catalog\Model\Product\AuthorizationFactory;
use Magento\Framework\AuthorizationInterface;

/**
 * Builds a FakeProductAuthorization for the admin authorization it is handed and keeps the ones it built.
 */
final class FakeProductAuthorizationFactory extends AuthorizationFactory
{
    /** @var list<FakeProductAuthorization> */
    private array $createdAuthorizations = [];

    public function __construct(
        private readonly bool $isDesignChanged
    ) {
    }

    /**
     * @param array{authorization?: AuthorizationInterface} $data
     */
    public function create(array $data = []): FakeProductAuthorization
    {
        return $this->createdAuthorizations[] = new FakeProductAuthorization(
            $data['authorization'] ?? new FakeAclAuthorization([]),
            $this->isDesignChanged
        );
    }

    /**
     * @return list<FakeProductAuthorization>
     */
    public function createdAuthorizations(): array
    {
        return $this->createdAuthorizations;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Cms\Model\Page\AuthorizationFactory;
use Magento\Framework\AuthorizationInterface;

/**
 * Builds a FakePageAuthorization for the admin authorization it is handed and keeps the ones it built.
 */
final class FakePageAuthorizationFactory extends AuthorizationFactory
{
    /** @var list<FakePageAuthorization> */
    private array $createdAuthorizations = [];

    public function __construct(
        private readonly bool $isDesignChanged
    ) {
    }

    /**
     * @param array{authorization?: AuthorizationInterface} $data
     */
    public function create(array $data = []): FakePageAuthorization
    {
        return $this->createdAuthorizations[] = new FakePageAuthorization(
            $data['authorization'] ?? new FakeAclAuthorization([]),
            $this->isDesignChanged
        );
    }

    /**
     * @return list<FakePageAuthorization>
     */
    public function createdAuthorizations(): array
    {
        return $this->createdAuthorizations;
    }
}

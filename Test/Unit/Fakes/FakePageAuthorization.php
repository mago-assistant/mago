<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Model\Page\Authorization;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\CmsPageDesignGuard;

/**
 * Magento's CMS page design check without the database: a page that changes its design is refused,
 * with core's message, unless the admin's ACL allows design changes. The pages it saw are recorded.
 */
final class FakePageAuthorization extends Authorization
{
    /** @var list<PageInterface> */
    private array $checkedPages = [];

    public function __construct(
        private readonly AuthorizationInterface $adminAuthorization,
        private readonly bool $isDesignChanged
    ) {
    }

    public function authorizeFor(PageInterface $page): void
    {
        $this->checkedPages[] = $page;
        if ($this->isDesignChanged && !$this->adminAuthorization->isAllowed(CmsPageDesignGuard::ACL_RESOURCE)) {
            throw new AuthorizationException(__('You are not allowed to change CMS pages design settings'));
        }
    }

    public function adminAuthorization(): AuthorizationInterface
    {
        return $this->adminAuthorization;
    }

    /**
     * @return list<PageInterface>
     */
    public function checkedPages(): array
    {
        return $this->checkedPages;
    }
}

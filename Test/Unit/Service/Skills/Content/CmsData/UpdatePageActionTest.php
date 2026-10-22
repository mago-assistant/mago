<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Service\Skills\Content\CmsData\GetPageAction;
use MagoAssistant\Mago\Service\Skills\Content\CmsData\UpdatePageAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UpdatePageActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;
    private const PAGE_ID = 5;

    private FakeInternalApiClient $apiClient;

    private function action(): UpdatePageAction
    {
        $this->apiClient = (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'cmsPage/' . self::PAGE_ID, [
                'id' => self::PAGE_ID,
                'identifier' => 'about-us',
                'title' => 'About Us',
                'content' => 'Old body',
                'active' => true,
            ])
            ->withResponseForEvery(FakeInternalApiClient::PUT, ['success' => true]);

        return new UpdatePageAction(
            $this->apiClient,
            new GetPageAction($this->apiClient),
            new SecureAdminUrl(new FakeBackendUrl())
        );
    }

    #[Test]
    public function itKeepsTheExistingUrlKeyWhenUpdatingContent(): void
    {
        $action = $this->action();

        $result = $action->execute(['identifier' => '5', 'content' => 'New body'], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        self::assertSame('about-us', $this->putBody()['page']['identifier']);
        self::assertSame('New body', $this->putBody()['page']['content']);
    }

    #[Test]
    public function itKeepsTheExistingUrlKeyWhenUpdatingTitle(): void
    {
        $action = $this->action();

        $action->execute(['identifier' => '5', 'title' => 'About Our Company'], self::ADMIN_USER_ID);

        self::assertSame('about-us', $this->putBody()['page']['identifier']);
        self::assertSame('About Our Company', $this->putBody()['page']['title']);
    }

    #[Test]
    public function itDisablesThePageWithoutTouchingContentOrTitle(): void
    {
        $action = $this->action();

        $result = $action->execute(['identifier' => '5', 'is_active' => false], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        self::assertSame(
            ['id' => self::PAGE_ID, 'identifier' => 'about-us', 'active' => false],
            $this->putBody()['page']
        );
    }

    #[Test]
    public function itEnablesThePage(): void
    {
        $action = $this->action();

        $action->execute(['identifier' => '5', 'is_active' => true], self::ADMIN_USER_ID);

        self::assertTrue($this->putBody()['page']['active']);
    }

    #[Test]
    public function itDeclaresIsActiveAsBooleanParameter(): void
    {
        $action = $this->action();

        $schema = $action->getParameterSchema();

        self::assertSame('boolean', $schema['is_active']['type']);
    }

    #[Test]
    public function itReturnsAnErrorWithoutSavingWhenNothingWouldChange(): void
    {
        $action = $this->action();

        $result = $action->execute(['identifier' => '5'], self::ADMIN_USER_ID);

        self::assertArrayHasKey('error', $result);
        self::assertSame([], $this->apiClient->callsOf(FakeInternalApiClient::PUT));
    }

    #[Test]
    public function itReturnsAnErrorWhenIsActiveIsNotABoolean(): void
    {
        $action = $this->action();

        $result = $action->execute(['identifier' => '5', 'is_active' => 'maybe'], self::ADMIN_USER_ID);

        self::assertArrayHasKey('error', $result);
        self::assertSame([], $this->apiClient->callsOf(FakeInternalApiClient::PUT));
    }

    /**
     * @return array<string, mixed>
     */
    private function putBody(): array
    {
        return $this->apiClient->callsOf(FakeInternalApiClient::PUT)[0]['payload'];
    }
}

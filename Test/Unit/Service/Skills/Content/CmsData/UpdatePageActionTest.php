<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Service\Skills\Content\CmsData\GetPageAction;
use MagoAssistant\Mago\Service\Skills\Content\CmsData\UpdatePageAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UpdatePageActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private FakeInternalApiClient $apiClient;

    /**
     * @param array<string, mixed> $page What GetPageAction returns for the looked-up page
     */
    private function action(array $page): UpdatePageAction
    {
        $getPageAction = $this->createStub(GetPageAction::class);
        $getPageAction->method('execute')->willReturn($page);

        $this->apiClient = (new FakeInternalApiClient())
            ->withResponseForEvery(FakeInternalApiClient::PUT, ['success' => true]);

        return new UpdatePageAction($this->apiClient, $getPageAction, $this->createStub(SecureAdminUrl::class));
    }

    #[Test]
    public function itKeepsTheExistingUrlKeyWhenUpdatingContent(): void
    {
        $result = $this->action(['id' => 5, 'identifier' => 'about-us'])
            ->execute(['identifier' => 'about-us', 'content' => 'New body'], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        self::assertSame('about-us', $this->putBody()['page']['identifier']);
        self::assertSame('New body', $this->putBody()['page']['content']);
    }

    #[Test]
    public function itKeepsTheExistingUrlKeyWhenUpdatingTitle(): void
    {
        $this->action(['id' => 5, 'identifier' => 'about-us'])
            ->execute(['identifier' => 'about-us', 'title' => 'About Our Company'], self::ADMIN_USER_ID);

        self::assertSame('about-us', $this->putBody()['page']['identifier']);
        self::assertSame('About Our Company', $this->putBody()['page']['title']);
    }

    /**
     * @return array<string, mixed>
     */
    private function putBody(): array
    {
        return $this->apiClient->callsOf(FakeInternalApiClient::PUT)[0]['payload'];
    }
}

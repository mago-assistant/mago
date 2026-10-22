<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Content\CmsData;

use MagoAssistant\Mago\Service\Skills\Content\CmsData\GetBlockAction;
use MagoAssistant\Mago\Service\Skills\Content\CmsData\UpdateBlockAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UpdateBlockActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;
    private const BLOCK_ID = 5;

    private FakeInternalApiClient $apiClient;

    private function action(): UpdateBlockAction
    {
        $this->apiClient = (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'cmsBlock/' . self::BLOCK_ID, [
                'id' => self::BLOCK_ID,
                'identifier' => 'footer_links_block',
                'title' => 'Footer Links Block',
                'content' => 'Old body',
                'active' => true,
            ])
            ->withResponseForEvery(FakeInternalApiClient::PUT, ['success' => true]);

        return new UpdateBlockAction(
            $this->apiClient,
            new GetBlockAction($this->apiClient),
            new SecureAdminUrl(new FakeBackendUrl())
        );
    }

    #[Test]
    public function itUpdatesTheContent(): void
    {
        $action = $this->action();

        $result = $action->execute(['identifier' => '5', 'content' => 'New body'], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        self::assertSame('New body', $this->putBody()['block']['content']);
    }

    #[Test]
    public function itUpdatesTheTitle(): void
    {
        $action = $this->action();

        $action->execute(['identifier' => '5', 'title' => 'Footer Links'], self::ADMIN_USER_ID);

        self::assertSame('Footer Links', $this->putBody()['block']['title']);
    }

    #[Test]
    public function itDisablesTheBlockWithoutTouchingContentOrTitle(): void
    {
        $action = $this->action();

        $result = $action->execute(['identifier' => '5', 'is_active' => false], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        self::assertSame(
            ['id' => self::BLOCK_ID, 'active' => false],
            $this->putBody()['block']
        );
    }

    #[Test]
    public function itEnablesTheBlock(): void
    {
        $action = $this->action();

        $action->execute(['identifier' => '5', 'is_active' => true], self::ADMIN_USER_ID);

        self::assertTrue($this->putBody()['block']['active']);
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

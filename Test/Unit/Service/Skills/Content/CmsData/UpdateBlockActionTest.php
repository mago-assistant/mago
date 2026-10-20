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

    private FakeInternalApiClient $apiClient;

    #[Test]
    public function itDisablesTheBlock(): void
    {
        $result = $this->action()->execute(['identifier' => '5', 'is_active' => false], self::ADMIN_USER_ID);

        self::assertTrue($result['success']);
        self::assertSame(['block' => ['id' => 5, 'active' => false]], $this->putBody());
    }

    #[Test]
    public function itEnablesTheBlock(): void
    {
        $this->action()->execute(['identifier' => '5', 'is_active' => true], self::ADMIN_USER_ID);

        self::assertTrue($this->putBody()['block']['active']);
    }

    #[Test]
    public function itRefusesWhenThereIsNothingToChange(): void
    {
        $result = $this->action()->execute(['identifier' => '5'], self::ADMIN_USER_ID);

        self::assertSame('Nothing to update: pass content, title or is_active', $result['error']);
        self::assertSame([], $this->apiClient->callsOf(FakeInternalApiClient::PUT));
    }

    private function action(): UpdateBlockAction
    {
        $this->apiClient = (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'cmsBlock/5', [
                'id' => 5,
                'identifier' => 'footer_links_block',
                'active' => true,
            ])
            ->withResponseForEvery(FakeInternalApiClient::PUT, ['success' => true]);

        return new UpdateBlockAction(
            $this->apiClient,
            new GetBlockAction($this->apiClient),
            new SecureAdminUrl(new FakeBackendUrl())
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function putBody(): array
    {
        return $this->apiClient->callsOf(FakeInternalApiClient::PUT)[0]['payload'];
    }
}

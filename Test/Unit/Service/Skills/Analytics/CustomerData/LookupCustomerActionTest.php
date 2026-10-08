<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Analytics\CustomerData;

use MagoAssistant\Mago\Service\Skills\Analytics\CustomerData\LookupCustomerAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LookupCustomerActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;
    private const CUSTOMER = [
        'id' => 52,
        'firstname' => 'Sanne',
        'middlename' => 'J.',
        'lastname' => 'de Vries',
        'email' => 's@example.test',
    ];

    private FakeInternalApiClient $apiClient;

    #[Test]
    public function itMatchesEveryWordOfAFullNameAgainstAnyNameField(): void
    {
        $result = $this->lookup('Sanne  de Vries');

        $payload = $this->onlyCall();
        foreach (['Sanne', 'de', 'Vries'] as $group => $word) {
            foreach (['firstname', 'middlename', 'lastname'] as $index => $field) {
                $prefix = "searchCriteria[filter_groups][$group][filters][$index]";
                self::assertSame($field, $payload[$prefix . '[field]']);
                self::assertSame('%' . $word . '%', $payload[$prefix . '[value]']);
                self::assertSame('like', $payload[$prefix . '[conditionType]']);
            }
        }
        self::assertArrayNotHasKey('searchCriteria[filter_groups][3][filters][0][field]', $payload);
        self::assertSame(52, $result['results'][0]['entity_id']);
        self::assertSame('Sanne J. de Vries', $result['results'][0]['name']);
    }

    #[Test]
    public function itTreatsLikeWildcardsAsLiteralCharacters(): void
    {
        $this->lookup('100%_off');

        self::assertSame('%100\\%\\_off%', $this->onlyCall()['searchCriteria[filter_groups][0][filters][0][value]']);
    }

    #[Test]
    public function itSplitsOnCommasAndDropsTheDotAfterAnInitial(): void
    {
        $this->lookup('Dekker, H.');

        $payload = $this->onlyCall();
        self::assertSame('%Dekker%', $payload['searchCriteria[filter_groups][0][filters][0][value]']);
        self::assertSame('%H%', $payload['searchCriteria[filter_groups][1][filters][0][value]']);
        self::assertArrayNotHasKey('searchCriteria[filter_groups][2][filters][0][field]', $payload);
    }

    #[Test]
    public function itSearchesASingleNameInOneCall(): void
    {
        $this->lookup('Vries');

        $payload = $this->onlyCall();
        self::assertSame('lastname', $payload['searchCriteria[filter_groups][0][filters][2][field]']);
        self::assertArrayNotHasKey('searchCriteria[filter_groups][1][filters][0][field]', $payload);
    }

    #[Test]
    public function itCapsTheNumberOfWords(): void
    {
        $this->lookup('a b c d e f g');

        $payload = $this->onlyCall();
        self::assertArrayHasKey('searchCriteria[filter_groups][4][filters][0][field]', $payload);
        self::assertArrayNotHasKey('searchCriteria[filter_groups][5][filters][0][field]', $payload);
    }

    #[Test]
    public function itLooksUpAnIdByEntityId(): void
    {
        $this->lookup(' 52 ');

        $payload = $this->onlyCall();
        self::assertSame('entity_id', $payload['searchCriteria[filter_groups][0][filters][0][field]']);
        self::assertSame('52', $payload['searchCriteria[filter_groups][0][filters][0][value]']);
        self::assertSame('eq', $payload['searchCriteria[filter_groups][0][filters][0][conditionType]']);
    }

    #[Test]
    public function itLooksUpAnEmailAddressByEmail(): void
    {
        $this->lookup('s@example.test');

        $payload = $this->onlyCall();
        self::assertSame('email', $payload['searchCriteria[filter_groups][0][filters][0][field]']);
        self::assertArrayNotHasKey('searchCriteria[filter_groups][0][filters][1][field]', $payload);
    }

    #[Test]
    public function itReportsNoMatchWithAnEmptyList(): void
    {
        $result = $this->lookup('Nobody Here', []);

        self::assertSame([], $result['results']);
        self::assertCount(1, $this->apiClient->callsOf(FakeInternalApiClient::GET));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function lookup(string $search, array $items = [self::CUSTOMER]): array
    {
        $this->apiClient = (new FakeInternalApiClient())
            ->withResponseForEvery(FakeInternalApiClient::GET, ['items' => $items, 'total_count' => count($items)]);
        $adminUrl = $this->createStub(SecureAdminUrl::class);
        $adminUrl->method('getUrl')->willReturn('http://example.test/admin/customer/52');

        return (new LookupCustomerAction($this->apiClient, $adminUrl))
            ->execute(['search' => $search], self::ADMIN_USER_ID);
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyCall(): array
    {
        $calls = $this->apiClient->callsOf(FakeInternalApiClient::GET);
        self::assertCount(1, $calls);
        self::assertSame('customers/search', $calls[0]['endpoint']);

        return $calls[0]['payload'];
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Config;

use Magento\Config\Model\ResourceModel\Config as ConfigData;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\ScopeCodeResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\ScopeInterface;
use MagoAssistant\Mago\Api\Config\RepositoryInterface;
use MagoAssistant\Mago\Model\Config\Repository;
use MagoAssistant\Mago\Model\Config\SystemPromptBuilder;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLocaleResolver;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeComponentRegistrar;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeEncryptor;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeProductMetadata;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeStoreManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase
{
    #[Test]
    public function aStoreViewSettingCannotSwitchTheAssistantBackOn(): void
    {
        $repository = $this->repositoryWith((new FakeScopeConfig([Repository::XML_PATH_EXTENSION_ENABLE => '0']))
            ->withScopeValue(ScopeInterface::SCOPE_STORE, Repository::XML_PATH_EXTENSION_ENABLE, '1')
            ->withScopeValue(ScopeInterface::SCOPE_WEBSITE, Repository::XML_PATH_EXTENSION_ENABLE, '1'));

        self::assertFalse($repository->isEnabled());
        self::assertFalse($repository->isEnabled(1));
    }

    #[Test]
    public function aStoreViewSettingCannotSwitchTheAssistantOff(): void
    {
        $repository = $this->repositoryWith((new FakeScopeConfig([Repository::XML_PATH_EXTENSION_ENABLE => '1']))
            ->withScopeValue(ScopeInterface::SCOPE_STORE, Repository::XML_PATH_EXTENSION_ENABLE, '0'));

        self::assertTrue($repository->isEnabled());
    }

    #[Test]
    public function itReturnsAConfiguredHexAccentColor(): void
    {
        $repository = $this->repository([RepositoryInterface::XML_PATH_ACCENT_COLOR => '#1a2B3c']);

        self::assertSame('#1a2B3c', $repository->getAccentColor());
    }

    #[Test]
    #[DataProvider('invalidColors')]
    public function itFallsBackToTheDefaultAccentColorWhenTheConfiguredValueIsNotAHexColor(string $value): void
    {
        $repository = $this->repository([RepositoryInterface::XML_PATH_ACCENT_COLOR => $value]);

        self::assertSame('#F26322', $repository->getAccentColor());
    }

    #[Test]
    public function itFallsBackToTheDefaultAccentColorWhenNothingIsConfigured(): void
    {
        self::assertSame('#F26322', $this->repository([])->getAccentColor());
    }

    #[Test]
    public function itReturnsAConfiguredHexTextColor(): void
    {
        $repository = $this->repository([RepositoryInterface::XML_PATH_TEXT_COLOR => '#000000']);

        self::assertSame('#000000', $repository->getTextColor());
    }

    #[Test]
    #[DataProvider('invalidColors')]
    public function itFallsBackToTheDefaultTextColorWhenTheConfiguredValueIsNotAHexColor(string $value): void
    {
        $repository = $this->repository([RepositoryInterface::XML_PATH_TEXT_COLOR => $value]);

        self::assertSame('#FFFFFF', $repository->getTextColor());
    }

    #[Test]
    public function itKeepsVoiceInputOffWhenNothingIsConfigured(): void
    {
        self::assertFalse($this->repository([])->isVoiceInputEnabled());
    }

    #[Test]
    public function itTurnsVoiceInputOnWhenEnabled(): void
    {
        $repository = $this->repository([RepositoryInterface::XML_PATH_VOICE_INPUT => '1']);

        self::assertTrue($repository->isVoiceInputEnabled());
    }

    #[Test]
    public function itKeepsVoiceInputOffWhenDisabled(): void
    {
        $repository = $this->repository([RepositoryInterface::XML_PATH_VOICE_INPUT => '0']);

        self::assertFalse($repository->isVoiceInputEnabled());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidColors(): array
    {
        return [
            'css injection' => ['#fff;}</style><script>alert(1)</script>'],
            'trailing declaration' => ['#F26322; background: url(https://evil.example)'],
            'named color' => ['red'],
            'short hex' => ['#fff'],
            'missing hash' => ['F26322'],
            'non hex digits' => ['#GGGGGG'],
            'trailing newline' => ["#F26322\n"],
        ];
    }

    /**
     * @param array<string, string> $config
     */
    private function repository(array $config): Repository
    {
        return $this->repositoryWith(new FakeScopeConfig($config));
    }

    private function repositoryWith(FakeScopeConfig $scopeConfig): Repository
    {
        return new Repository(
            new FakeStoreManager(),
            $scopeConfig,
            $this->withoutConstructor(ConfigDataCollectionFactory::class),
            $this->withoutConstructor(ConfigData::class),
            new Json(),
            new FakeProductMetadata(),
            new FakeEncryptor(),
            $this->withoutConstructor(ResourceConnection::class),
            $this->withoutConstructor(ScopeCodeResolver::class),
            $this->withoutConstructor(DateTime::class),
            new FakeComponentRegistrar(),
            $this->withoutConstructor(FileDriver::class),
            $this->withoutConstructor(SystemPromptBuilder::class),
            new FakeLocaleResolver()
        );
    }

    /**
     * The color getters only read scope config; the other dependencies are never touched.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function withoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}

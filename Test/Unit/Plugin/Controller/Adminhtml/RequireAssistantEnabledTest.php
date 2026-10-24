<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Plugin\Controller\Adminhtml;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Model\Config\EnabledGuard;
use MagoAssistant\Mago\Plugin\Controller\Adminhtml\RequireAssistantEnabled;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequireAssistantEnabledTest extends TestCase
{
    /**
     * Stream answers with server-sent events and checks the switch itself; Addons is the add-on
     * feed, not the assistant. Every other chat controller must carry the plugin.
     */
    private const UNGUARDED_CONTROLLERS = ['Addons', 'FormKeyJsonValidation', 'Stream'];

    private const CONTROLLER_NAMESPACE = 'MagoAssistant\\Mago\\Controller\\Adminhtml\\Chat\\';

    #[Test]
    public function itAnswersWithAnErrorAndSkipsTheControllerWhenTheAssistantIsDisabled(): void
    {
        $resultFactory = new JsonOnlyResultFactory();
        $plugin = new RequireAssistantEnabled($this->guard(false), $resultFactory);
        $hasProceeded = false;

        $result = $plugin->aroundExecute($this->action(), function () use (&$hasProceeded): ResultInterface {
            $hasProceeded = true;

            return new RecordingJsonResult();
        });

        self::assertFalse($hasProceeded);
        self::assertSame($resultFactory->lastResult, $result);
        self::assertSame(
            ['error' => 'The assistant is currently disabled. Enable it in Stores > Configuration > Mago Assistant.'],
            $resultFactory->lastResult?->getRecordedData()
        );
    }

    #[Test]
    public function itRunsTheControllerWhenTheAssistantIsEnabled(): void
    {
        $resultFactory = new JsonOnlyResultFactory();
        $plugin = new RequireAssistantEnabled($this->guard(true), $resultFactory);
        $controllerResult = new RecordingJsonResult();

        $result = $plugin->aroundExecute($this->action(), fn (): ResultInterface => $controllerResult);

        self::assertSame($controllerResult, $result);
        self::assertNull($resultFactory->lastResult);
    }

    #[Test]
    public function itIsRegisteredOnEveryChatControllerThatReachesTheAssistant(): void
    {
        $config = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/di.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $config, 'etc/adminhtml/di.xml must parse');
        $guarded = [];
        foreach ($config->type as $type) {
            foreach ($type->plugin as $plugin) {
                if ((string)$plugin['type'] === RequireAssistantEnabled::class) {
                    $guarded[] = (string)$type['name'];
                }
            }
        }

        $controllers = array_map(
            fn (string $file): string => self::CONTROLLER_NAMESPACE . basename($file, '.php'),
            glob(__DIR__ . '/../../../../../Controller/Adminhtml/Chat/*.php') ?: []
        );
        $expected = array_values(array_filter(
            $controllers,
            // A trait in the folder (ReleasesSessionLock, FormKeyJsonValidation) is not a controller
            fn (string $class): bool => !trait_exists($class) && !in_array(
                substr($class, strlen(self::CONTROLLER_NAMESPACE)),
                self::UNGUARDED_CONTROLLERS,
                true
            )
        ));

        sort($guarded);
        sort($expected);
        self::assertSame($expected, $guarded);
    }

    private function guard(bool $isEnabled): EnabledGuard
    {
        return new EnabledGuard((new FakeConfigRepository())->withEnabled($isEnabled));
    }

    private function action(): ActionInterface
    {
        return new class implements ActionInterface {
            public function execute(): ResultInterface|ResponseInterface
            {
                throw new \LogicException('The plugin calls $proceed, not the subject');
            }
        };
    }
}

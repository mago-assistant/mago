<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml\Skills;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Controller\Adminhtml\Skills\SavePermissions;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SavePermissionsTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private ManagerInterface&MockObject $messages;
    private ToolRegistry&MockObject $toolRegistry;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->messages = $this->createMock(ManagerInterface::class);
        $this->toolRegistry = $this->createMock(ToolRegistry::class);
    }

    #[Test]
    public function aNameNoToolCarriesIsNotStored(): void
    {
        $this->toolRegistry->method('getToolByName')->willReturn(null);
        $this->connection->expects(self::never())->method('insertOnDuplicate');
        $this->messages->expects(self::once())->method('addErrorMessage');

        $this->controller('made_up_tool')->execute();
    }

    #[Test]
    public function aRegisteredSkillIsStored(): void
    {
        $this->toolRegistry->method('getToolByName')->with('cms_data')
            ->willReturn($this->createMock(ToolInterface::class));
        $this->connection->expects(self::once())->method('insertOnDuplicate')
            ->with('mago_skill_permission', ['admin_user_id' => 3, 'skill_name' => 'cms_data', 'permission' => 'read']);

        $this->controller('cms_data')->execute();
    }

    private function controller(string $skillName): SavePermissions
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([
            ['skill_name', '', $skillName],
            ['permissions', [], [3 => 'read']],
        ]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($this->messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new SavePermissions(
            $context,
            $resource,
            $this->toolRegistry,
            new ErrorReporter(new ErrorLogger(new FakeLogger(), new Json()), new PiiHeuristic())
        );
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml\DeadStock;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use MagoAssistant\Mago\Controller\Adminhtml\DeadStock\Export;
use MagoAssistant\Mago\Service\Skills\Inventory\DeadStock;
use MagoAssistant\Mago\Service\Skills\Inventory\DeadStockCsv;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeDeadStockReport;
use MagoAssistant\Mago\Service\Export\CsvWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The backend Context, request and results are framework classes without an interface this
 * controller can be given instead, so they are the one place this test stubs.
 */
final class ExportTest extends TestCase
{
    private FakeDeadStockReport $report;

    /** @var list<string> */
    private array $errors = [];

    private ?string $redirectPath = null;

    private ?string $contents = null;

    /** @var array<string,string> */
    private array $headers = [];

    #[Test]
    public function itAsksForTheSameResourceAsTheTool(): void
    {
        self::assertSame(DeadStock::ACL, Export::ADMIN_RESOURCE);
    }

    #[Test]
    public function itRedirectsToTheDashboardOnAnUnknownType(): void
    {
        $this->controller(['type' => 'overstock'])->execute();

        self::assertSame('admin/dashboard', $this->redirectPath);
        self::assertSame(['Unknown dead stock report type.'], $this->errors);
        self::assertSame([], $this->report->calls);
        self::assertNull($this->contents);
    }

    #[Test]
    public function itDownloadsTheClampedReportAsCsv(): void
    {
        $this->controller(['type' => 'slow_moving', 'months' => '99', 'min_days_of_cover' => '-3'])->execute();

        self::assertSame(
            [['method' => 'chunks', 'type' => 'slow_moving', 'months' => 36, 'min_days_of_cover' => 1]],
            $this->report->calls
        );
        self::assertStringStartsWith('SKU-1,', explode("\n", (string)$this->contents)[1]);
        self::assertSame('text/csv; charset=UTF-8', $this->headers['Content-Type']);
        self::assertStringStartsWith(
            'attachment; filename="slow-moving-inventory-',
            $this->headers['Content-Disposition']
        );
        self::assertSame('no-store', $this->headers['Cache-Control']);
    }

    /**
     * @param array<string,string> $params
     */
    private function controller(array $params): Export
    {
        $this->report = new FakeDeadStockReport([['sku' => 'SKU-1', 'qty' => 3.0, 'stock_value' => 9.0]]);

        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
        );

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->errors[] = (string)$message;
            return $messages;
        });

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path) use ($redirect) {
            $this->redirectPath = $path;
            return $redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($raw) {
            $this->headers[$name] = $value;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function (string $contents) use ($raw) {
            $this->contents = $contents;
            return $raw;
        });
        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturn($raw);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getResultFactory')->willReturn($resultFactory);

        return new Export($context, $this->report, new DeadStockCsv(new CsvWriter()));
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess;

use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Phrase\RendererInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use MagoAssistant\Mago\Service\Api\InProcess\AccessDeniedException;
use MagoAssistant\Mago\Service\Api\InProcess\ErrorMapper;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\DesignChangeRefusedException;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeExceptionMasker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorMapperTest extends TestCase
{
    private FakeExceptionMasker $masker;
    private ErrorMapper $errorMapper;
    private ?RendererInterface $previousRenderer = null;

    protected function setUp(): void
    {
        $this->masker = new FakeExceptionMasker();
        $this->errorMapper = new ErrorMapper($this->masker);
        $this->previousRenderer = Phrase::getRenderer();
    }

    protected function tearDown(): void
    {
        if ($this->previousRenderer !== null) {
            Phrase::setRenderer($this->previousRenderer);
        }
    }

    #[Test]
    public function itReportsAMissingEntityAsResourceNotFound(): void
    {
        $error = $this->errorMapper->toError(new NoSuchEntityException(__('The product with SKU "%1" does not exist.', 'x')));

        self::assertSame(['error' => 'Resource not found'], $error);
    }

    #[Test]
    public function itKeepsMagentosDesignRefusalAndNamesThePermissionInsteadOfMaskingIt(): void
    {
        $refusal = new AuthorizationException(__('You are not allowed to change CMS pages design settings'));

        $error = $this->errorMapper->toError(
            DesignChangeRefusedException::fromCoreRefusal($refusal, 'Magento_Cms::save_design')
        );

        self::assertSame(
            ['error' => 'You are not allowed to change CMS pages design settings. '
                . 'This needs the Magento_Cms::save_design permission.'],
            $error
        );
    }

    #[Test]
    public function itNamesTheMissingAclResourceWhenTheRouteIsDenied(): void
    {
        $error = $this->errorMapper->toError(
            new AccessDeniedException(__('The admin user is not allowed to use %1.', 'Magento_Sales::actions_cancel'))
        );

        self::assertSame(
            ['error' => 'You do not have permission to access this data. '
                . 'The admin user is not allowed to use Magento_Sales::actions_cancel.'],
            $error
        );
    }

    #[Test]
    public function itReportsAnAuthorizationFailureInsideTheServiceAsMissingPermission(): void
    {
        $error = $this->errorMapper->toError(new AuthorizationException(__('Not allowed to edit the product\'s design attributes')));

        self::assertSame(['error' => 'You do not have permission to access this data'], $error);
    }

    #[Test]
    public function itReportsAForbiddenResponseAsMissingPermission(): void
    {
        $error = $this->errorMapper->toError(
            new WebapiException(__('Forbidden'), 0, WebapiException::HTTP_FORBIDDEN)
        );

        self::assertSame(['error' => 'You do not have permission to access this data'], $error);
    }

    #[Test]
    public function itFillsInThePlaceholdersOfABadRequest(): void
    {
        $error = $this->errorMapper->toError(new LocalizedException(
            __('The status "%1" is not part of the order status history of "%2"', 'holded', new Phrase('Order %1', ['5']))
        ));

        self::assertSame(['error' => 'The status "holded" is not part of the order status history of "Order 5"'], $error);
    }

    #[Test]
    public function itFillsInNamedPlaceholders(): void
    {
        $error = $this->errorMapper->toError(new LocalizedException(
            __('"%fieldName" is required. Enter and try again.', ['fieldName' => 'sku'])
        ));

        self::assertSame(['error' => '"sku" is required. Enter and try again.'], $error);
    }

    /**
     * AddConfigurableVariantsAction recognises "The product is already attached." by its English
     * text, so a translated interface locale must not reach the message.
     */
    #[Test]
    public function itKeepsTheMessageUntranslated(): void
    {
        Phrase::setRenderer(new class implements RendererInterface {
            public function render(array $source, array $arguments): string
            {
                return 'Het product is al gekoppeld.';
            }
        });

        $error = $this->errorMapper->toError(new LocalizedException(__('The product is already attached.')));

        self::assertSame(['error' => 'The product is already attached.'], $error);
    }

    #[Test]
    public function itReportsAnInputThatDoesNotFitTheServiceAsABadRequest(): void
    {
        $error = $this->errorMapper->toError(new \TypeError('Argument #1 ($id) must be of type int, string given'));

        self::assertSame(['error' => 'Argument #1 ($id) must be of type int, string given'], $error);
    }

    #[Test]
    public function itLeavesTheServerPathOutOfAnInputThatDoesNotFitTheService(): void
    {
        $error = $this->errorMapper->toError(new \TypeError(
            'Magento\Sales\Model\OrderRepository::get(): Argument #1 ($id) must be of type int, string given, '
            . 'called in /var/www/html/vendor/magento/framework/Webapi/ServiceInputProcessor.php on line 210'
        ));

        self::assertSame(
            ['error' => 'Magento\Sales\Model\OrderRepository::get(): Argument #1 ($id) must be of type int, string given'],
            $error
        );
    }

    #[Test]
    public function itMasksAnErrorThatCrashedTheServiceAsAnInternalError(): void
    {
        $error = $this->errorMapper->toError(new \Error('Call to a member function getId() on null'));

        self::assertSame(['error' => FakeExceptionMasker::INTERNAL_ERROR], $error);
        self::assertInstanceOf(\Error::class, $this->masker->masked()[0]->getPrevious());
    }
}

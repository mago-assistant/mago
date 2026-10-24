<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Service\Api\InProcess\OutputNormalizer;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;
use MagoAssistant\Mago\Service\Api\InProcess\ServiceOutputConverter;

/**
 * Hands an array output back as it is and puts a scalar under "result", as REST decodes it.
 */
final class FakeServiceOutputConverter extends ServiceOutputConverter
{
    public function __construct()
    {
    }

    public function convert(mixed $output, ResolvedRoute $route, AuthorizationInterface $authorization): array
    {
        return is_array($output) ? $output : [OutputNormalizer::SCALAR_KEY => $output];
    }
}

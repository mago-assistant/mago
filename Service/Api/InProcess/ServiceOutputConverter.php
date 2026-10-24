<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Framework\Reflection\DataObjectProcessorFactory;
use Magento\Framework\Reflection\ExtensionAttributesProcessorFactory;
use Magento\Framework\Webapi\ServiceOutputProcessorFactory;

/**
 * Turns a service's return value into arrays the way REST does, including the field-level ACL REST
 * applies in webapi_rest (DataObjectProcessorPermissionChecked): an extension attribute guarded by an ACL
 * resource, such as a product's stock_item, is left out for an admin whose role lacks that resource.
 */
class ServiceOutputConverter
{
    public function __construct(
        private readonly ServiceOutputProcessorFactory $serviceOutputProcessorFactory,
        private readonly DataObjectProcessorFactory $dataObjectProcessorFactory,
        private readonly ExtensionAttributesProcessorFactory $extensionAttributesProcessorFactory,
        private readonly DataObjectProcessor $dataObjectProcessor,
        private readonly OutputNormalizer $outputNormalizer
    ) {
    }

    /**
     * @return array<array-key, mixed>
     */
    public function convert(mixed $output, ResolvedRoute $route, AuthorizationInterface $authorization): array
    {
        $processor = $this->serviceOutputProcessorFactory->create([
            'dataObjectProcessor' => $this->createPermissionCheckedProcessor($authorization),
        ]);

        return $this->outputNormalizer->normalize(
            $processor->process($output, $route->serviceClass, $route->serviceMethod)
        );
    }

    private function createPermissionCheckedProcessor(AuthorizationInterface $authorization): DataObjectProcessor
    {
        return $this->dataObjectProcessorFactory->create([
            'extensionAttributesProcessor' => $this->extensionAttributesProcessorFactory->create([
                'dataObjectProcessor' => $this->dataObjectProcessor,
                'authorization' => $authorization,
                'isPermissionChecked' => true,
            ]),
        ]);
    }
}

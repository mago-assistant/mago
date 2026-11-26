<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class SetPositionsAction implements CautionedActionInterface, ValidatingActionInterface
{
    public function __construct(
        private readonly LinkPlanner $planner,
        private readonly ProductLinkGatewayInterface $gateway,
        private readonly ChangeLines $changeLines
    ) {
    }

    public function getName(): string
    {
        return 'set_positions';
    }

    public function getDescription(): string
    {
        return 'Reorder the link_type links of one product (sku): linked_skus first, in the order given, then '
            . 'the others in their current order';
    }

    public function getParameterSchema(): array
    {
        return [
            'sku' => LinkParameters::sku(),
            'linked_skus' => LinkParameters::linkedSkus(),
            'link_type' => LinkParameters::linkType(),
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        return [
            'success' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            'link_type' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'position' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function findRefusal(array $params): ?array
    {
        $refusal = $this->planner->planPositions(LinkRequest::fromParams($params))->refusal();

        return $refusal === null ? null : ['error' => $refusal];
    }

    public function getCautions(array $params, int $adminUserId): array
    {
        $plan = $this->planner->planPositions(LinkRequest::fromParams($params));

        return $plan->refusal() === null ? $this->changeLines->forPositions($plan) : [];
    }

    public function execute(array $params, int $adminUserId): array
    {
        $plan = $this->planner->planPositions(LinkRequest::fromParams($params));
        if ($plan->refusal() !== null) {
            return ['error' => $plan->refusal()];
        }

        $changes = $plan->changes();
        try {
            $this->gateway->apply($changes);
        } catch (LinkWriteFailedException $exception) {
            return ['error' => $exception->getMessage()];
        }

        $productSku = $changes->changedProductSkus()[0] ?? '';

        return [
            'success' => true,
            'message' => 'Moved ' . count($changes->saves) . ' ' . $changes->type->label() . ' links of '
                . $productSku . '.',
            'link_type' => $changes->type->value,
            'sku' => $productSku,
            'moved' => array_map(
                static fn (ProductLink $link): array => ['sku' => $link->linkedSku, 'position' => $link->position],
                $changes->saves
            ),
        ];
    }
}

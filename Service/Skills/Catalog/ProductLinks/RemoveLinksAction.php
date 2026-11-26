<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Removed links can be added again, only their positions are lost, so this asks with the caution
 * card that lists every link it removes rather than the irreversible one.
 */
class RemoveLinksAction implements CautionedActionInterface, ValidatingActionInterface
{
    public function __construct(
        private readonly LinkPlanner $planner,
        private readonly ProductLinkGatewayInterface $gateway,
        private readonly ChangeLines $changeLines
    ) {
    }

    public function getName(): string
    {
        return 'remove_links';
    }

    public function getDescription(): string
    {
        return 'Remove the link_type links from the products in skus: the ones to linked_skus, or all of '
            . 'that type when linked_skus is omitted. two_way also removes the links pointing back';
    }

    public function getParameterSchema(): array
    {
        return [
            'skus' => LinkParameters::skus(),
            'linked_skus' => LinkParameters::linkedSkus(),
            'link_type' => LinkParameters::linkType(),
            'two_way' => LinkParameters::twoWay(),
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
            'removed_count' => [PiiClass::PUBLIC],
            'not_linked_count' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'removed' => [PiiClass::PUBLIC],
            'not_linked' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'Say how many links were removed from how many products, and name the ones that were not linked.';
    }

    public function findRefusal(array $params): ?array
    {
        $refusal = $this->planner->planRemovals(LinkRequest::fromParams($params))->refusal();

        return $refusal === null ? null : ['error' => $refusal];
    }

    public function getCautions(array $params, int $adminUserId): array
    {
        $plan = $this->planner->planRemovals(LinkRequest::fromParams($params));

        return $plan->refusal() === null ? $this->changeLines->forRemovals($plan) : [];
    }

    public function execute(array $params, int $adminUserId): array
    {
        $plan = $this->planner->planRemovals(LinkRequest::fromParams($params));
        if ($plan->refusal() !== null) {
            return ['error' => $plan->refusal()];
        }

        $changes = $plan->changes();
        try {
            $this->gateway->apply($changes);
        } catch (LinkWriteFailedException $exception) {
            return ['error' => $exception->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Removed ' . count($changes->deletes) . ' ' . $changes->type->label() . ' links from '
                . count($changes->changedProductSkus()) . ' products.',
            'link_type' => $changes->type->value,
            'removed_count' => count($changes->deletes),
            'not_linked_count' => count($changes->skipped),
            'products' => array_map(
                static fn (string $sku): array => [
                    'sku' => $sku,
                    'removed' => LinkChangeSet::linkedSkusFrom($sku, $changes->deletes),
                    'not_linked' => LinkChangeSet::linkedSkusFrom($sku, $changes->skipped),
                ],
                $changes->productSkus()
            ),
        ];
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class AddLinksAction implements CautionedActionInterface, ValidatingActionInterface
{
    public function __construct(
        private readonly LinkPlanner $planner,
        private readonly ProductLinkGatewayInterface $gateway,
        private readonly ChangeLines $changeLines
    ) {
    }

    public function getName(): string
    {
        return 'add_links';
    }

    public function getDescription(): string
    {
        return 'Link every SKU in linked_skus to every product in skus as link_type, optionally both ways. '
            . 'Existing links are kept as they are; new ones go after the last';
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
            'added_count' => [PiiClass::PUBLIC],
            'already_linked_count' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'added' => [PiiClass::PUBLIC],
            'already_linked' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'Say how many links were added to how many products, and name the ones that were already linked.';
    }

    public function findRefusal(array $params): ?array
    {
        $refusal = $this->planner->planAdditions(LinkRequest::fromParams($params))->refusal();

        return $refusal === null ? null : ['error' => $refusal];
    }

    public function getCautions(array $params, int $adminUserId): array
    {
        $plan = $this->planner->planAdditions(LinkRequest::fromParams($params));

        return $plan->refusal() === null ? $this->changeLines->forAdditions($plan) : [];
    }

    public function execute(array $params, int $adminUserId): array
    {
        $plan = $this->planner->planAdditions(LinkRequest::fromParams($params));
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
            'message' => 'Added ' . count($changes->saves) . ' ' . $changes->type->label() . ' links to '
                . count($changes->changedProductSkus()) . ' products.',
            'link_type' => $changes->type->value,
            'added_count' => count($changes->saves),
            'already_linked_count' => count($changes->skipped),
            'products' => array_map(
                static fn (string $sku): array => [
                    'sku' => $sku,
                    'added' => LinkChangeSet::linkedSkusFrom($sku, $changes->saves),
                    'already_linked' => LinkChangeSet::linkedSkusFrom($sku, $changes->skipped),
                ],
                $changes->productSkus()
            ),
        ];
    }
}

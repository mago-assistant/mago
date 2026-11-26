<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as CustomerGroupCollectionFactory;
use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\Marketing\DiscountRuleCautions;
use MagoAssistant\Mago\Service\Skills\Marketing\RuleIds;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class CreateRuleAction implements CautionedActionInterface, ValidatingActionInterface
{
    public function __construct(
        private readonly CatalogRuleServices $catalogRules,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerGroupCollectionFactory $customerGroupCollectionFactory,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly ErrorReporter $errorReporter,
        private readonly DiscountRuleCautions $cautions,
        private readonly RuleConditionReview $conditionReview,
        private readonly RulePriceReindexer $reindexer
    ) {
    }

    public function getName(): string
    {
        return 'create_rule';
    }

    public function getDescription(): string
    {
        return 'Create a new catalog price rule';
    }

    public function getParameterSchema(): array
    {
        return RuleFields::schema();
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'message' => [PiiClass::PUBLIC],
            'success' => [PiiClass::PUBLIC],
            'rule_id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'Discount types: "percent" = X% off, "fixed" = X amount off, '
            . '"to_percent" = set price to X% of original, "to_fixed" = set price to X. '
            . 'To reach products by attribute values or SKUs, pass conditions; there is no need for a '
            . 'category holding exactly those products.';
    }

    /**
     * A new catalog rule changes the price of every matching product, and by default it reaches
     * every website and customer group, guests included, at once: always worth a second look.
     * An empty website or group list is kept as empty, as execute() saves it, and a comma list
     * ("1,0") is read as the ids Magento's save splits it into.
     */
    public function getCautions(array $params, int $adminUserId): array
    {
        return array_values(array_filter([
            $this->describeDiscount($params),
            ...$this->conditionReview->describe($params, $this->websiteIdsFor($params)),
            ...$this->cautions->describeScope(
                RuleIds::fromInput($params['website_ids'] ?? null),
                RuleIds::fromInput($params['customer_group_ids'] ?? null),
                trim((string)($params['from_date'] ?? '')),
                trim((string)($params['to_date'] ?? '')),
                $adminUserId
            ),
        ]));
    }

    /**
     * Conditions on an unknown, ambiguous or non-promo attribute, or that match no product, are
     * refused before the card: the admin is never asked to approve a rule that would not save as shown.
     */
    public function findRefusal(array $params): ?array
    {
        return $this->conditionReview->findRefusal($params, $this->websiteIdsFor($params), $this->toolName());
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $name = $params['name'] ?? '';
        if (empty($name)) {
            return ['error' => 'name parameter is required for create_rule'];
        }

        $discountType = $params['discount_type'] ?? '';
        if (empty($discountType)) {
            return ['error' => 'discount_type parameter is required for create_rule'];
        }

        $discountAmount = (float)($params['discount_amount'] ?? 0);
        if ($discountAmount <= 0) {
            return ['error' => 'discount_amount must be greater than 0'];
        }

        $simpleAction = RuleFields::DISCOUNT_TYPES[$discountType] ?? null;
        if (!$simpleAction) {
            return ['error' => 'Invalid discount_type. Use: percent, fixed, to_percent, or to_fixed'];
        }

        $refusal = $this->findRefusal($params);
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            // Read the way getCautions() reads them, so the rule saved is the rule the card described
            $websiteIds = RuleIds::fromInput($params['website_ids'] ?? null) ?? $this->getAllWebsiteIds();
            $customerGroupIds = RuleIds::fromInput($params['customer_group_ids'] ?? null)
                ?? $this->getAllCustomerGroupIds();

            $rule = $this->catalogRules->createRule();
            $rule->setData('name', $name);
            $rule->setData('is_active', 1);
            $rule->setData('simple_action', $simpleAction);
            $rule->setData('discount_amount', $discountAmount);
            $rule->setData('website_ids', $websiteIds);
            $rule->setData('customer_group_ids', $customerGroupIds);
            $rule->setData('sort_order', (int)($params['priority'] ?? 0));
            $rule->setData('stop_rules_processing', !empty($params['stop_further_rules']));

            if (!empty($params['from_date'])) {
                $rule->setData('from_date', $params['from_date']);
            }
            if (!empty($params['to_date'])) {
                $rule->setData('to_date', $params['to_date']);
            }

            $conditions = $this->conditionReview->build($params);
            if (!$conditions->isEmpty()) {
                $rule->getConditions()->loadArray($conditions->toMagentoArray());
            }

            // The admin's save controller runs this and CatalogRuleRepositoryInterface::save() does
            // not; without it a percentage above 100 indexes a negative price (ProductPriceCalculator).
            $validation = $rule->validateData(new DataObject($rule->getData()));
            if ($validation !== true) {
                return ['error' => implode(' ', array_map('strval', (array)$validation))];
            }

            $this->catalogRules->getRepository()->save($rule);
            $ruleId = (int)$rule->getId();

            return [
                'success' => true,
                'rule_id' => $ruleId,
                'name' => $name,
                'message' => 'Catalog price rule "' . $name . '" created successfully. '
                    . $this->reindexer->describeAfterSave($ruleId),
                'admin_url' => $this->secureAdminUrl->getUrl(
                    'catalog_rule/promo_catalog/edit',
                    ['id' => $ruleId]
                ),
            ];
        } catch (\Exception $e) {
            $error = $this->errorReporter->reportToolFailure($this->toolName(), $e);
            return ['error' => 'Failed to create catalog price rule: ' . $error];
        }
    }

    /**
     * Empty for an unknown discount type, which execute() refuses anyway
     *
     * @param array<string, mixed> $params
     */
    private function describeDiscount(array $params): string
    {
        $amount = $this->cautions->formatAmount((float)($params['discount_amount'] ?? 0));
        $products = $this->conditionReview->restrictsProducts($params) ? 'every matching product' : 'every product';

        return match ($params['discount_type'] ?? '') {
            'percent' => 'Takes ' . $amount . '% off the price of ' . $products . '.',
            'fixed' => 'Takes ' . $amount . ' off the price of ' . $products . '.',
            'to_percent' => 'Sets the price of ' . $products . ' to ' . $amount . '% of its original price.',
            'to_fixed' => 'Sets the price of ' . $products . ' to ' . $amount . '.',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return int[] The websites the rule will apply on, read the way execute() reads them
     */
    private function websiteIdsFor(array $params): array
    {
        if (!$this->conditionReview->hasConditions($params)) {
            return [];
        }

        return RuleIds::fromInput($params['website_ids'] ?? null) ?? $this->getAllWebsiteIds();
    }

    private function toolName(): string
    {
        return 'catalog_price_rules ' . $this->getName();
    }

    private function getAllWebsiteIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $ids[] = (int)$website->getId();
        }
        return $ids;
    }

    private function getAllCustomerGroupIds(): array
    {
        $ids = [];
        $collection = $this->customerGroupCollectionFactory->create();
        foreach ($collection as $group) {
            $ids[] = (int)$group->getId();
        }
        return $ids;
    }
}

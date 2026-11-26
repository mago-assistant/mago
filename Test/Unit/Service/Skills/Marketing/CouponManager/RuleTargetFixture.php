<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\CreateRuleAction;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionBuilder;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionDescriber;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionReader;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionValidator;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleDiscountValidator;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleTargetCatalog;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\UpdateRuleAction;
use MagoAssistant\Mago\Service\Skills\Marketing\DiscountRuleCautions;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;

/**
 * A small store for cart rule condition tests: a category tree with two "Tees", a promo dropdown
 * attribute (brand), one that is not used for promo rules (description), a multiple select
 * (activity), a text attribute that is used for promo rules (material_note), and two countries.
 */
final class RuleTargetFixture
{
    public const MEN_TEES = 16;
    public const WOMEN_TEES = 25;
    public const HIKVISION = '5437';
    public const DAHUA = '5438';

    public static function store(): FakeInternalApiClient
    {
        return (new FakeInternalApiClient())
            ->withResponse(FakeInternalApiClient::GET, 'categories/list', ['items' => [
                ['id' => 1, 'name' => 'Root Catalog', 'path' => '1', 'level' => 0],
                ['id' => 2, 'name' => 'Default Category', 'path' => '1/2', 'level' => 1],
                ['id' => 11, 'name' => 'Men', 'path' => '1/2/11', 'level' => 2],
                ['id' => 20, 'name' => 'Women', 'path' => '1/2/20', 'level' => 2],
                ['id' => self::MEN_TEES, 'name' => 'Tees', 'path' => '1/2/11/16', 'level' => 3],
                ['id' => self::WOMEN_TEES, 'name' => 'Tees', 'path' => '1/2/20/25', 'level' => 3],
            ]])
            ->withResponse(FakeInternalApiClient::GET, 'products/attributes/brand', [
                'attribute_code' => 'brand',
                'default_frontend_label' => 'Brand',
                'frontend_input' => 'select',
                'is_used_for_promo_rules' => '1',
                'options' => [
                    ['label' => ' ', 'value' => ''],
                    ['label' => 'Hikvision', 'value' => self::HIKVISION],
                    ['label' => 'Dahua', 'value' => self::DAHUA],
                ],
            ])
            ->withResponse(FakeInternalApiClient::GET, 'products/attributes/activity', [
                'attribute_code' => 'activity',
                'default_frontend_label' => 'Activity',
                'frontend_input' => 'multiselect',
                'is_used_for_promo_rules' => '1',
                'options' => [['label' => 'Yoga', 'value' => '8'], ['label' => 'Running', 'value' => '9']],
            ])
            ->withResponse(FakeInternalApiClient::GET, 'products/attributes/description', [
                'attribute_code' => 'description',
                'default_frontend_label' => 'Description',
                'frontend_input' => 'textarea',
                'is_used_for_promo_rules' => '0',
            ])
            ->withResponse(FakeInternalApiClient::GET, 'products/attributes/material_note', [
                'attribute_code' => 'material_note',
                'default_frontend_label' => 'Material note',
                'frontend_input' => 'text',
                'is_used_for_promo_rules' => '1',
            ])
            ->withResponse(FakeInternalApiClient::GET, 'products/attributes/unknown', [
                'error' => 'The attribute with a "unknown" attributeCode doesn\'t exist.',
            ])
            ->withResponse(FakeInternalApiClient::GET, 'directory/countries', [
                ['id' => 'NL', 'full_name_english' => 'Netherlands'],
                ['id' => 'BE', 'full_name_english' => 'Belgium'],
            ])
            ->withResponse(FakeInternalApiClient::GET, 'store/websites', [
                ['id' => 0, 'name' => 'Admin'],
                ['id' => 1, 'name' => 'Main Website'],
            ])
            ->withResponse(FakeInternalApiClient::GET, 'customerGroups/search', ['items' => [
                ['id' => 0, 'code' => 'NOT LOGGED IN'],
                ['id' => 1, 'code' => 'General'],
            ]]);
    }

    public static function createAction(FakeInternalApiClient $apiClient): CreateRuleAction
    {
        $catalog = new RuleTargetCatalog($apiClient);

        return new CreateRuleAction(
            $apiClient,
            new SecureAdminUrl(new FakeBackendUrl()),
            new DiscountRuleCautions($apiClient),
            new RuleConditionValidator($catalog),
            new RuleConditionBuilder(),
            new RuleConditionDescriber($catalog),
            new RuleDiscountValidator()
        );
    }

    public static function updateAction(FakeInternalApiClient $apiClient): UpdateRuleAction
    {
        $catalog = new RuleTargetCatalog($apiClient);

        return new UpdateRuleAction(
            $apiClient,
            new SecureAdminUrl(new FakeBackendUrl()),
            new DiscountRuleCautions($apiClient),
            new RuleConditionValidator($catalog),
            new RuleConditionBuilder(),
            new RuleConditionReader(),
            new RuleConditionDescriber($catalog),
            new RuleDiscountValidator()
        );
    }
}

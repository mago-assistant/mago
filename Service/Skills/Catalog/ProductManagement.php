<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class ProductManagement extends AbstractSkill
{
    public function getName(): string
    {
        return 'product_management';
    }

    protected function getBaseDescription(): string
    {
        return 'Create catalog products of any type (simple, virtual, downloadable, configurable, grouped, bundle).';
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Catalog::products';
    }

    protected function getBaseInstructions(): string
    {
        return <<<'TEXT'
Merchants think in SKU, name, price, image and description — not in product types, attribute sets,
category IDs or website IDs. Work from what they give you:
- Collect everything the user already gave in a single create_product call — do not call it once per
  field, and do not pre-emptively ask about price or anything else in plain text before calling it.
  Only the name and a price are truly needed. Derive a SKU from the name when none is given
  (uppercase, dashes) and mention it. Assume product_type "simple" and the default attribute set unless
  the request clearly says otherwise (variants → configurable, service → virtual, file → downloadable,
  set of existing products → grouped, build-your-own kit → bundle).
- If price is still missing, create_product says so immediately without touching the database. Once
  price is given, a SKU that already exists and a similar product name are always checked and
  reported together, never one after the other. Ask the user everything a response raises in one
  short message, not one question per turn, then call create_product again with every answer at once
  (price filled in, a different sku if needed, ignore_similar: true if they confirmed despite the
  similar names) - do not ask again. Never ask about product type, attribute sets or stock. Do not
  invent descriptions, stock, weight or categories. Propose the minimal product; the confirmation
  prompt is the user's check.
- If a request needs many details (images, several attributes, long descriptions), create the minimal
  product anyway and point the user to the admin edit link to finish it there — do not interrogate.
- Never ask the user for a category ID or website ID. For a named category, resolve it with
  "list_categories" first. For a named website, match it against the [Store scope] section already in
  the system prompt. Only ask a short clarifying question if the name does not match anything.
- Attribute sets matter only for configurable products: the parent's set must contain the variant
  attributes (e.g. color). Use "list_attribute_sets" to pick one that lists them under
  variant_attributes and tell the user which set you picked and why. Never guess set IDs.
- Configurable flow: "create_product" for the parent, then in the same turn "add_configurable_variants"
  with one variant per combination (check labels with "get_attribute_options" first). Never reply with
  "I will now add the variants" without actually calling the action.
- Grouped and bundle children must already exist; create_product reports every unknown child SKU. Offer
  to create those as simple products first (ask name/price only if not given), then retry the parent.
After creating, reply short: what was created (SKU, name, price), the admin edit link from the result,
and the "missing" list as things they can fill in there (image, description, stock, categories).
Prices are in the store's base currency.
TEXT;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Form;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class PageForm extends AbstractSkill
{
    public function getName(): string
    {
        return 'page_form';
    }

    /**
     * The floor every call passes: any logged-in admin. What actually guards a call is the
     * resource of the admin page it acts on - the form open in the browser, or the page
     * write_fields would navigate to - and that comes from the request, not from the call's input,
     * so the actions check it themselves through PageAccess before they read or stage anything
     * (#203). mago:tool:verify has no open page to resolve and reports this floor.
     */
    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Backend::admin';
    }

    protected function getBaseDescription(): string
    {
        return 'Read the fields of the admin form currently open in the browser, including any '
            . 'unsaved edits the administrator has made, and stage new field values for the '
            . 'administrator to confirm. Reading only sees the page on screen right now; writing can '
            . 'also send the browser to another entity\'s form, or to the New form of a product, '
            . 'category, CMS page or CMS block to create one, and stage the values there. Customer, '
            . 'customer address, order and admin user forms hold personal data and are off limits: '
            . 'this cannot create or edit a customer, order or admin user.';
    }
}

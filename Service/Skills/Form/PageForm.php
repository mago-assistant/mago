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
     * What comes back is the browser's own snapshot of a form the admin already has open, so any
     * logged-in admin may use it. The navigate-then-act path is #203 and is not settled by this.
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

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\ValidatorException;
use MagoAssistant\Mago\Model\Config\HexColor as HexColorValidator;

class HexColor extends Value
{
    /**
     * Refuse anything but #RRGGBB, the color is printed unescaped inside <style> blocks.
     *
     * @throws ValidatorException
     */
    public function beforeSave(): self
    {
        if (!HexColorValidator::isValid((string)$this->getValue())) {
            throw new ValidatorException(
                __('Please enter a hex color like #F26322.')
            );
        }

        parent::beforeSave();
        return $this;
    }
}

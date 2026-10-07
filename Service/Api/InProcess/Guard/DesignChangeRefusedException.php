<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess\Guard;

use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Magento's own refusal of a design change, with the ACL resource that would allow it. Left as the core
 * AuthorizationException, it would be masked into a bare permission error that does not say what is
 * missing.
 */
final class DesignChangeRefusedException extends LocalizedException
{
    public static function fromCoreRefusal(AuthorizationException $refusal, string $aclResource): self
    {
        return new self(
            __('%1. This needs the %2 permission.', $refusal->getRawMessage(), $aclResource),
            $refusal
        );
    }
}

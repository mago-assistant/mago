<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api;

/**
 * What a tool's or action's ACL resource can be, besides a Magento resource id.
 *
 * A tool declares the native Magento resource guarding the data it touches, and a call is refused
 * unless the admin holds it. A tool that touches no Magento data at all - one that talks to an
 * external service, say - has no such resource to name. It
 * says so with MAGO_PER_USER rather than an empty string: the empty string is what a tool that
 * forgot to declare anything returns, and that one is refused, so that forgetting can never open
 * data by accident.
 *
 * @api
 */
class Acl
{
    /**
     * The tool is gated by the assistant's own per-user skill permission alone (Stores > Admin
     * Assistant > Skills & Permissions), and only by an explicit grant there: the module-wide
     * assistant_read/assistant_write resources do not stand in for it. Use it for a tool that
     * touches no Magento data.
     */
    public const MAGO_PER_USER = 'mago:per_user';
}

<?php

declare(strict_types=1);

namespace App\Application\Event\Security;

use App\Application\Event\AbstractAuditableEvent;

/**
 * Émis quand un utilisateur authentifié est refusé par un filtre applicatif
 * (typiquement `OIDC_REQUIRED_GROUPS` au callback, ou un voter sur une
 * route admin). Le contexte transporte la raison du refus pour faciliter
 * le diagnostic admin (cf. specs §3.10).
 */
final class AccessDenied extends AbstractAuditableEvent
{
    public function category(): string
    {
        return 'security';
    }

    public function action(): string
    {
        return 'access.denied';
    }
}

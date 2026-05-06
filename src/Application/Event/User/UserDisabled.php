<?php

declare(strict_types=1);

namespace App\Application\Event\User;

use App\Application\Event\AbstractAuditableEvent;

/**
 * Émis quand un user est désactivé (Authentik le supprime ou le sort
 * du groupe `OIDC_REQUIRED_GROUPS`, ou désactivation manuelle admin).
 * Permet de tracer la déconnexion forcée et la conservation du compte
 * pour l'historique des contributions (cf. specs §3.1).
 */
final class UserDisabled extends AbstractAuditableEvent
{
    public function category(): string
    {
        return 'user';
    }

    public function action(): string
    {
        return 'disabled';
    }
}

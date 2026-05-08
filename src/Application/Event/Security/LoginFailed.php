<?php

declare(strict_types=1);

namespace App\Application\Event\Security;

use App\Application\Event\AbstractAuditableEvent;

/**
 * Émis lorsqu'un callback OIDC se solde par un échec technique
 * (token invalide, mauvaise signature, code expiré, etc.).
 * Distinct d'un `AccessDenied` qui correspond à un user *connu* refusé
 * par les filtres applicatifs.
 */
final class LoginFailed extends AbstractAuditableEvent
{
    public function category(): string
    {
        return 'security';
    }

    public function action(): string
    {
        return 'login.failed';
    }
}

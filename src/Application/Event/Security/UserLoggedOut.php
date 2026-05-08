<?php

declare(strict_types=1);

namespace App\Application\Event\Security;

use App\Application\Event\AbstractAuditableEvent;

/**
 * Émis lors d'un logout (déclenché via `/logout` ou expiration session).
 */
final class UserLoggedOut extends AbstractAuditableEvent
{
    public function category(): string
    {
        return 'security';
    }

    public function action(): string
    {
        return 'logout';
    }
}

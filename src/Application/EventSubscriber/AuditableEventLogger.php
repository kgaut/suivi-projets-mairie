<?php

declare(strict_types=1);

namespace App\Application\EventSubscriber;

use App\Application\Event\AuditableEvent;
use App\Application\Event\Security\AccessDenied;
use App\Application\Event\Security\LoginFailed;
use App\Application\Event\Security\SessionExpired;
use App\Application\Event\Security\UserLoggedIn;
use App\Application\Event\Security\UserLoggedOut;
use App\Application\Event\User\UserDisabled;
use App\Application\Event\User\UserFirstSeen;
use App\Application\Event\User\UserProfileUpdated;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Logger générique des événements auditables.
 *
 * Utilisé en dev/test pour vérifier que les events sont bien dispatchés
 * (cf. `bin/console debug:event-dispatcher`). En prod, ce subscriber reste
 * actif et écrit dans le canal Monolog `audit` (configurable côté monolog).
 *
 * La persistance dans la table `audit_events` viendra au Lot 2
 * (cf. specs §3.10).
 */
final readonly class AuditableEventLogger implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Symfony EventDispatcher accepte un nom de classe comme "event name".
        // On souscrit à l'interface : tous les événements qui implémentent
        // AuditableEvent passeront ici tant qu'ils sont dispatchés
        // *par leur nom de classe concrète* — Symfony ne fait pas
        // l'auto-héritage. Du coup on s'abonne au plus large via un
        // listener générique côté dispatchEvent (cf. specs §3.10).
        // Pour le Lot 0, on liste explicitement chaque event concret.
        return [
            UserLoggedIn::class => 'log',
            UserLoggedOut::class => 'log',
            LoginFailed::class => 'log',
            AccessDenied::class => 'log',
            SessionExpired::class => 'log',
            UserFirstSeen::class => 'log',
            UserProfileUpdated::class => 'log',
            UserDisabled::class => 'log',
        ];
    }

    public function log(AuditableEvent $event): void
    {
        $this->logger->info(sprintf('[audit] %s.%s', $event->category(), $event->action()), [
            'subject' => $event->subjectAuthentikId(),
            'context' => $event->context(),
            'occurred_at' => $event->occurredAt()->format(DateTimeInterface::ATOM),
        ]);
    }
}

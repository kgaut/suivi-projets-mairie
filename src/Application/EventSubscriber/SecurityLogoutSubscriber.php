<?php

declare(strict_types=1);

namespace App\Application\EventSubscriber;

use App\Application\Event\Security\UserLoggedOut;
use App\Domain\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Bridge le `LogoutEvent` natif du firewall Symfony vers l'événement
 * applicatif `UserLoggedOut` (AuditableEvent). Le LogoutEvent est émis par
 * Symfony lors de la déconnexion ; on dispatche un nouvel événement pour
 * préserver la couche audit applicatif (cf. specs §3.10).
 */
final readonly class SecurityLogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(private EventDispatcherInterface $eventDispatcher)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserLoggedOut($user->getAuthentikId()));
    }
}

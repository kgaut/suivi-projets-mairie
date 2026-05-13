<?php

declare(strict_types=1);

namespace App\Application\EventSubscriber;

use App\Application\Event\Security\LoginFailed;
use App\Application\Event\Security\UserLoggedOut;
use App\Domain\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Convertit les événements de sécurité Symfony en `AuditableEvent` applicatifs.
 *
 * Symfony émet `LogoutEvent`, `LoginFailureEvent` etc. via le firewall ; ils
 * portent le `Request` et le token ou l'exception. On les traduit dans le
 * vocabulaire applicatif (`UserLoggedOut`, `LoginFailed`) qui est ensuite
 * écrit par `AuditableEventLogger`.
 *
 * Les events `UserLoggedIn`, `UserFirstSeen`, `UserProfileUpdated`,
 * `AccessDenied` et `UserDisabled` sont dispatchés directement dans
 * `OidcUserProvider` / `OidcAccessGuard` car ils ont besoin du `User`
 * applicatif et du contexte métier (groupes, rôles).
 */
final readonly class SecurityAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private EventDispatcherInterface $eventDispatcher)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => 'onLogout',
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        $user = $token?->getUser();
        $authentikId = $user instanceof User ? $user->getAuthentikId() : null;

        $this->eventDispatcher->dispatch(new UserLoggedOut(
            subjectAuthentikId: $authentikId,
            context: [
                'ip' => $this->remoteIp($event->getRequest()),
            ],
        ));
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $exception = $event->getException();

        $this->eventDispatcher->dispatch(new LoginFailed(
            subjectAuthentikId: $this->resolveAuthentikIdFromException($exception),
            context: [
                'reason' => $exception::class,
                'message' => $exception->getMessageKey(),
                'ip' => $this->remoteIp($event->getRequest()),
                'firewall' => $event->getFirewallName(),
            ],
        ));
    }

    private function remoteIp(?Request $request): ?string
    {
        return $request?->getClientIp();
    }

    /**
     * Cherche un identifiant Authentik dans l'exception d'authentification.
     * `getUserIdentifier()` est disponible quand l'auth a réussi à identifier
     * un user (échec à un stade ultérieur, e.g. accessGuard).
     */
    private function resolveAuthentikIdFromException(AuthenticationException $exception): ?string
    {
        $identifier = $exception->getToken()?->getUserIdentifier() ?? null;

        return is_string($identifier) && $identifier !== '' ? $identifier : null;
    }
}

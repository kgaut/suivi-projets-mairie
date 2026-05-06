<?php

declare(strict_types=1);

namespace App\Controller;

use Drenso\OidcBundle\Security\OidcClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Routes d'authentification :
 *
 * - `/login` : entrée de l'app, redirige vers Authentik via drenso
 * - `/access-denied` : page d'erreur si le filtrage `OIDC_REQUIRED_GROUPS`
 *   rejette l'utilisateur (cf. specs §5.3)
 *
 * Le logout est entièrement géré par le firewall Symfony (cf. security.yaml).
 * La route `app_logout` est interceptée par Symfony, pas besoin d'action.
 */
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function login(OidcClientInterface $oidcClient): RedirectResponse
    {
        // Redirection vers le provider OIDC (Authentik). Au retour sur
        // `/login_check`, drenso valide les tokens et appelle
        // OidcUserProvider::ensureUserExists() pour la réconciliation.
        return $oidcClient->generateAuthorizationRedirect();
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        // Intercepté par le firewall (cf. security.yaml).
        throw new \LogicException('This method should never be reached — Symfony intercepts /logout.');
    }

    #[Route('/access-denied', name: 'app_access_denied', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function accessDenied(AuthenticationUtils $authenticationUtils): Response
    {
        // AuthenticationUtils récupère depuis la session le message de la
        // dernière exception d'authentification (CustomUserMessageAuthenticationException
        // levée par OidcAccessGuard si l'utilisateur n'a pas les groupes requis).
        $error = $authenticationUtils->getLastAuthenticationError();

        return $this->render('security/access_denied.html.twig', [
            'message' => $error?->getMessageKey(),
        ]);
    }
}

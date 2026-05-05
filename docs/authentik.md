# Configuration Authentik

L'application délègue l'authentification à une instance Authentik existante via OpenID Connect (OIDC). Les groupes Authentik sont récupérés dans le token et mappés sur des rôles Symfony.

## Vue d'ensemble du flux

```
Utilisateur → Symfony (/login) → redirection Authentik (/application/o/authorize)
           ← Authentik (page de login + consentement éventuel)
           → callback Symfony (/oidc/callback?code=...)
           → Symfony échange le code contre un token
           → Symfony lit les claims (sub, email, name, groups)
           → Symfony crée/met à jour l'utilisateur local
           → Symfony pose un cookie de session (stocké dans Redis)
```

## 1. Configuration côté Authentik

### 1.1 Créer le provider OAuth2/OpenID

Dans l'admin Authentik : **Applications → Providers → Create → OAuth2/OpenID Provider**.

| Champ | Valeur |
|---|---|
| Name | `suivi-projets-mairie` |
| Authentication flow | `default-authentication-flow` |
| Authorization flow | `default-provider-authorization-implicit-consent` (ou explicit selon ta politique) |
| Client type | `Confidential` |
| Client ID | (généré automatiquement, à copier) |
| Client Secret | (généré automatiquement, à copier) |
| Redirect URIs | `https://projets.mairie.example.fr/oidc/callback` (prod)<br>`http://localhost:8080/oidc/callback` (dev) |
| Signing Key | `authentik Self-signed Certificate` |
| Scopes | `openid`, `email`, `profile`, `groups` |

> Important : **un seul provider par environnement**. Crée-en un pour la prod et un pour le dev (avec son propre redirect URI).

### 1.2 Créer le mapping de claim "groups"

Authentik n'expose pas le claim `groups` par défaut dans le token. Il faut créer un **Property Mapping** :

**Customisation → Property Mappings → Create → Scope Mapping**

| Champ | Valeur |
|---|---|
| Name | `OAuth Mapping: groups` |
| Scope name | `groups` |
| Description | "Liste des groupes de l'utilisateur" |
| Expression | `return [group.name for group in user.ak_groups.all()]` |

Puis dans le provider, ajouter ce scope mapping à la liste des scopes.

### 1.3 Créer l'application

**Applications → Applications → Create**

| Champ | Valeur |
|---|---|
| Name | `Suivi Projets Mairie` |
| Slug | `suivi-projets-mairie` |
| Provider | `suivi-projets-mairie` (le provider créé en 1.1) |
| Launch URL | `https://projets.mairie.example.fr` |
| Icon | (optionnel) logo de la mairie |

### 1.4 Créer les groupes

**Directory → Groups → Create**

Crée les groupes suivants (les noms sont configurables côté app) :

- `mairie-projets-lecteur`
- `mairie-projets-agent`
- `mairie-projets-chef`
- `mairie-projets-admin`

Affecte tes utilisateurs aux groupes correspondants.

### 1.5 Restreindre l'accès à l'application

**Applications → Applications → Suivi Projets Mairie → Policy / Group / User Bindings**

Lier les groupes ci-dessus à l'application avec une politique "any of" pour que **seuls** les membres de ces groupes puissent se connecter.

## 2. Configuration côté Symfony

### 2.1 Variables d'environnement

Dans `.env.local` (dev) ou les variables d'environnement de la prod :

```dotenv
# URL de découverte OIDC (Authentik expose /.well-known/openid-configuration)
OIDC_ISSUER_URL=https://authentik.mairie.example.fr/application/o/suivi-projets-mairie/

# Identifiants du client
OIDC_CLIENT_ID=xxxxxxxxxxxxxxxxxxxx
OIDC_CLIENT_SECRET=yyyyyyyyyyyyyyyyyyyy

# URL de callback (doit correspondre à ce qui est configuré dans Authentik)
OIDC_REDIRECT_URI=https://projets.mairie.example.fr/oidc/callback

# Scopes demandés
OIDC_SCOPES="openid email profile groups"

# Mapping groupes Authentik → rôles Symfony (séparateur : virgule, format : groupe:role)
OIDC_GROUP_ROLE_MAPPING="mairie-projets-lecteur:ROLE_LECTEUR,mairie-projets-agent:ROLE_AGENT,mairie-projets-chef:ROLE_CHEF_PROJET,mairie-projets-admin:ROLE_ADMIN"
```

### 2.2 Bundle utilisé

Choix retenu : **`drenso/symfony-oidc-bundle`** — bundle léger spécialisé OIDC, plus simple que `KnpUOAuth2ClientBundle` pour ce cas d'usage.

> Décision à confirmer au moment du Lot 0. Si le bundle se révèle limitant (p. ex. logout SSO complexe), on basculera sur KnpU OAuth2 Client + custom resource owner.

### 2.3 Mapping des claims vers `User`

Au callback, Symfony reçoit un payload du type :

```json
{
  "sub": "9f3c...uuid...Authentik",
  "email": "j.dupont@mairie.example.fr",
  "name": "Jean Dupont",
  "preferred_username": "jdupont",
  "groups": ["mairie-projets-agent", "mairie-projets-chef"]
}
```

Le mapping côté app :

| Claim OIDC | Champ `User` |
|---|---|
| `sub` | `authentikId` (clé de réconciliation, immuable) |
| `email` | `email` |
| `name` | `displayName` |
| `preferred_username` | `username` |
| `groups` | converti en `roles` via `OIDC_GROUP_ROLE_MAPPING` |

**Règle de réconciliation** : on cherche d'abord par `authentikId`. Si non trouvé, on crée. **On ne réconcilie jamais par e-mail seul** (un changement d'e-mail dans Authentik ne doit pas casser le compte).

### 2.4 Logout

Deux niveaux :

1. **Logout local** : `/logout` détruit la session Symfony.
2. **Logout SSO** (recommandé) : redirige ensuite vers `https://authentik.mairie.example.fr/application/o/suivi-projets-mairie/end-session/` pour invalider la session Authentik.

À configurer dans `security.yaml` (`logout.delete_cookies` + `logout.target` pointant vers l'endpoint SSO).

## 3. Test de la configuration

### 3.1 Test manuel

1. Crée un utilisateur de test dans Authentik, mets-le dans `mairie-projets-agent`.
2. Va sur l'app, clique "Se connecter".
3. Tu es redirigé vers Authentik, tu te logges.
4. Tu es redirigé sur `/profile` (ou la page d'accueil) connecté.
5. Sur `/profile`, tu vois ton nom, e-mail et les groupes Authentik récupérés.

### 3.2 Test du token

Pour debug, depuis l'app `bin/console debug:oidc:token` (commande à fournir au Lot 0) affichera les claims reçus du dernier login.

## 4. Diagnostic des problèmes courants

| Symptôme | Cause probable | Solution |
|---|---|---|
| Redirection en boucle | `OIDC_REDIRECT_URI` ne correspond pas exactement à ce qui est dans Authentik | Vérifier slash final, http/https |
| `groups` vide dans le token | Property Mapping non créé ou non ajouté au scope | Suivre 1.2 |
| `invalid_client` | Mauvais client_secret ou client_id | Régénérer côté Authentik et reporter |
| `Access denied` | L'utilisateur n'est pas dans un groupe lié à l'app Authentik | Voir 1.5 |
| Les rôles ne sont pas appliqués | `OIDC_GROUP_ROLE_MAPPING` mal formé | Format strict : `groupe:ROLE_X,...` sans espace |

## 5. Sécurité

- Le `client_secret` ne doit **jamais** être committé. Stockage dans `.env.local` (dev) ou variables d'env / secret manager (prod).
- Le cookie de session Symfony doit avoir `HttpOnly`, `Secure`, `SameSite=Lax` (configuration par défaut conservée).
- TTL de session court (4 h glissantes) + refresh token côté Authentik si tu veux des sessions longues.
- En cas de fuite du `client_secret`, régénérer côté Authentik invalide instantanément les tokens en cours.

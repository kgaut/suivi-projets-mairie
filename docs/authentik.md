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

### 1.4 Organiser les groupes Authentik

Avec le modèle de rôles dynamique (cf. specs §2), il n'y a **plus qu'un seul groupe Authentik dédié à l'application** : `admin_spm`. Tous les autres rôles (`ROLE_CHEF_PROJET`, `ROLE_ACTEUR`, `ROLE_LECTEUR`) sont calculés dynamiquement à chaque action en fonction de l'objet (Project ou Task) et de l'appartenance de l'utilisateur aux **groupes de travail** (qui sont des groupes Authentik existants — commissions, services, etc.).

L'application distingue donc deux types de groupes :

1. **Groupe d'admin applicatif** : `admin_spm` (nom configurable via `OIDC_ADMIN_GROUP`). Les membres ont `ROLE_ADMIN` partout dans l'app.
2. **Groupes "métier"** — correspondent à l'organigramme (commissions, services, groupes-projet). Ils existent **déjà** côté Authentik. L'admin les déclare comme **groupes de travail** dans l'app (cf. specs §3.11), et l'appartenance d'un utilisateur à ces groupes alimente le calcul de `ROLE_ACTEUR` sur les Project/Task associés.

#### Convention proposée (à adapter à votre namespace existant)

**Groupe d'admin applicatif** :

- `admin_spm` (administrateurs de l'application Suivi Projets Mairie)

**Groupes métier** (exemples — utilisez les noms en place dans votre Authentik, l'app accepte n'importe lesquels) :

- `commission-numerique`
- `commission-jeunesse`
- `agents-services-techniques`
- `elus-conseil-municipal`
- ...

> 💡 Le PO utilise déjà des noms naturels du type `commission-numerique`. Aucun préfixe imposé : l'app récupère les noms de groupes via l'API Authentik et l'admin sélectionne ceux qui correspondent à des groupes de travail (cf. checkbox "Groupe Visible" dans l'admin, specs §3.11).

Crée le groupe `admin_spm` via **Directory → Groups → Create** et affecte les administrateurs. Les groupes métier existent normalement déjà.

### 1.5 Restreindre l'accès à l'application (filtrage SSO)

Décision tranchée : **filtrage à deux niveaux** (defense in depth).

#### Côté Authentik (Policy Binding)

**Applications → Applications → Suivi Projets Mairie → Policy / Group / User Bindings**

Lier un (ou plusieurs) groupe(s) Authentik à l'application avec une politique "any of" pour que **seuls** leurs membres puissent se connecter. Recommandation :

- Créer un **méga-groupe** parent `spm_users` qui contient `admin_spm` + tous les groupes métier (commissions, services) destinés à utiliser l'application. Lier uniquement celui-là à l'application Authentik.
- Plus simple à maintenir : à chaque nouveau groupe métier ajouté à l'app, on l'inclut dans `spm_users` et il a accès à l'app.

Un utilisateur non membre verra une page d'erreur Authentik au login (jamais l'app).

#### Côté app (variable `OIDC_REQUIRED_GROUPS`)

L'application revérifie au callback OIDC qu'au moins un groupe Authentik de l'utilisateur figure dans la liste configurée. Si non, page "Accès non autorisé" + événement audit `security.access_denied`.

```dotenv
# .env (exemple)
OIDC_REQUIRED_GROUPS=spm_users
# ou si tu listes explicitement les groupes autorisés
# OIDC_REQUIRED_GROUPS=admin_spm,commission-numerique,agents-services-techniques,elus-conseil-municipal
```

> Cette double vérification protège contre une mauvaise configuration côté Authentik (binding oublié après création d'un nouveau groupe). Si tu ne veux qu'un seul niveau, garde Authentik (plus solide), mais l'idéal est d'avoir les deux.

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

# Groupe Authentik des admins applicatifs (seul rôle "statique").
# Les autres rôles (CHEF_PROJET, ACTEUR, LECTEUR) sont calculés dynamiquement
# par les voters (cf. specs §2).
OIDC_ADMIN_GROUP="admin_spm"

# Filtrage d'accès à l'application (defense in depth — voir §1.5)
# L'utilisateur doit appartenir à au moins un de ces groupes pour pouvoir se connecter
OIDC_REQUIRED_GROUPS="spm_users"
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
  "groups": ["commission-numerique", "agents-services-techniques"]
}
```

Le mapping côté app :

| Claim OIDC | Champ `User` |
|---|---|
| `sub` | `authentikId` (clé de réconciliation, immuable) |
| `email` | `email` |
| `name` | `displayName` |
| `preferred_username` | `username` |
| `groups` | stocké dans `groupsSnapshot` ; donne `ROLE_ADMIN` si contient `OIDC_ADMIN_GROUP`. Sert aussi au calcul dynamique des autres rôles (cf. specs §2 et §3.11) |

**Règle de réconciliation** : on cherche d'abord par `authentikId`. Si non trouvé, on crée. **On ne réconcilie jamais par e-mail seul** (un changement d'e-mail dans Authentik ne doit pas casser le compte).

### 2.4 Logout

Deux niveaux :

1. **Logout local** : `/logout` détruit la session Symfony.
2. **Logout SSO** (recommandé) : redirige ensuite vers `https://authentik.mairie.example.fr/application/o/suivi-projets-mairie/end-session/` pour invalider la session Authentik.

À configurer dans `security.yaml` (`logout.delete_cookies` + `logout.target` pointant vers l'endpoint SSO).

## 3. Test de la configuration

### 3.1 Test manuel

1. Crée un utilisateur de test dans Authentik, mets-le dans un groupe de travail (ex. `commission-numerique`) et dans `spm_users`.
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

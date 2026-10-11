# Gandalf : administration à deux niveaux

[English version](ADMIN.md)

Gandalf a deux niveaux d'administration :

- le **superadmin**, réservé à Claims IA, crée et supprime les applications,
  nomme leurs admins, agit dans toutes les applications et gère tous les
  comptes. Chacune de ses requêtes est journalisée ;
- l'**admin d'application**, qui peut être un utilisateur d'un client, gère les
  utilisateurs, les droits et les clés d'API de son application, sans jamais
  donner plus que ce qu'il a.

Une clé d'API n'accède jamais au rôle de superadmin.

## Sommaire

- [Rôles](#rôles)
- [Attribuer le rôle de superadmin](#attribuer-le-rôle-de-superadmin)
- [Ce que fait un superadmin](#ce-que-fait-un-superadmin)
- [Ce que fait un admin d'application](#ce-que-fait-un-admin-dapplication)
- [Comptes et mots de passe temporaires](#comptes-et-mots-de-passe-temporaires)
- [Journal d'administration](#journal-dadministration)
- [API HTTP](#api-http)
- [Changements et migration](#changements-et-migration)
- [Plan du code](#plan-du-code)
- [Tests](#tests)

---

## Rôles

| Rôle | Détenu par | Peut |
| ---- | ---------- | ---- |
| Superadmin | Comptes Claims IA, indicateur `superadmin: true` sur l'utilisateur | Créer, lister et supprimer toutes les applications ; agir comme un admin qui a tous les droits utilisateur dans toutes les applications, membre ou non ; lister tous les comptes, leur donner un mot de passe temporaire, les supprimer ; lire le journal d'administration |
| Admin d'application | Un membre avec `role: admin` | Tout ce que ses droits permettent dans son application : utilisateurs, droits, clés d'API, tables, paramètres. Inviter un autre admin. Jamais supprimer l'application |
| Membre | Un membre avec toute autre valeur de rôle : `member` (gandalf.web actuel), `manager` (ancienne interface), `user` (jeu de démonstration)… | Ce que ses droits permettent |
| Clé d'API (consumer) | `client_id` et `client_secret` d'une application | Ses droits de clé (`decisions_make`, `decisions_view`) |

Le rôle est un texte libre enregistré pour chaque membre : seule la valeur
`admin` a un effet, et le code ne lit aucune autre valeur. Il est donné par une
invitation (seul un admin invite avec le rôle `admin`) ou par
`POST /api/v1/projects/users/admin`, par lequel un admin nomme un autre admin,
qui reçoit les droits de l'appelant. L'ajout d'un compte
(`POST /api/v1/projects/users`) et la modification d'un membre
(`PUT /api/v1/projects/users`) refusent le rôle `admin`. Le superadmin n'est pas
une valeur de rôle mais un indicateur du compte ; les clés d'API ne sont pas des
membres.

Un superadmin garde ses propres appartenances : Claims IA peut utiliser ses
propres applications comme un membre ordinaire. Son rôle n'apparaît pas parmi
les collaborateurs des applications dont il n'est pas membre.

---

## Attribuer le rôle de superadmin

Seule la commande Artisan `gandalf:superadmin` pose ou retire l'indicateur.
Aucune route d'API ne l'écrit : `superadmin` n'est pas un attribut remplissable
de [app/Models/User.php](app/Models/User.php), donc `PUT /api/v1/users/current`
l'ignore.

Depuis le répertoire de l'API (`php artisan` dans le conteneur PHP) :

```bash
php artisan gandalf:superadmin quelquun@claims-ia.fr            # attribuer
php artisan gandalf:superadmin quelquun@claims-ia.fr --remove   # retirer
php artisan gandalf:superadmin --list                           # lister
```

Le compte doit exister (il s'inscrit comme d'habitude). Chaque attribution et
chaque retrait est écrit dans le journal d'administration. Un retrait prend
effet à la requête suivante.

---

## Ce que fait un superadmin

- **Créer une application** (`POST /api/v1/projects`) : le superadmin n'en
  devient pas membre. L'application n'a aucun membre tant que son admin ne l'a
  pas rejointe.
- **Nommer ses admins** : dans la nouvelle application (`X-Application`),
  inviter avec le rôle admin (`POST /api/v1/invite`, `role: admin`, tous les
  droits), puis, pour un compte existant, confirmer
  (`POST /api/v1/projects/collaborators/confirm`). L'application exécute des
  décisions une fois qu'elle a un admin actif (`admin_not_activated` sinon,
  inchangé).
- **Lister toutes les applications** (`GET /api/v1/projects`).
- **Agir dans n'importe quelle application** : avec son `X-Application`, le
  superadmin est un admin qui a tous les droits utilisateur.
  `GET /api/v1/projects/users` renvoie cette entrée, avec `superadmin: true` et
  `member` (s'il est aussi membre de l'application). La copie et le déplacement
  entre deux applications suivent la même règle.
- **Supprimer une application** (`DELETE /api/v1/projects`).
- **Lister tous les comptes** (`GET /api/v1/superadmin/users`), avec leurs
  applications, y compris ceux qui n'appartiennent à aucune application :
  aucun écran d'application ne les atteint.
- **Donner un mot de passe temporaire à un compte**
  (`POST /api/v1/superadmin/users/{id}/password`) et **supprimer un compte
  définitivement** (`DELETE /api/v1/superadmin/users/{id}`), voir
  [Comptes et mots de passe temporaires](#comptes-et-mots-de-passe-temporaires).
- **Lire le journal d'administration** (`GET /api/v1/superadmin/log`).

Un admin d'application ne peut pas supprimer le compte d'un superadmin avec
`DELETE /api/v1/projects/collaborators/account` (403).

---

## Ce que fait un admin d'application

L'admin d'une application la gère sans le superadmin, dans la limite de ses
droits. L'API vérifie que personne ne donne plus que ce qu'il a :

| Action | Vérification |
| ------ | ------------ |
| Inviter un utilisateur (`POST /api/v1/invite`) | Droits invités détenus par l'invitant ; seul un admin invite un admin |
| Ajouter un utilisateur (`POST /api/v1/projects/users`) | Droits détenus par l'appelant ; le rôle admin ne se donne pas ici |
| Créer un compte (`POST /api/v1/projects/collaborators/account`) | Admin seulement ; droits détenus par l'appelant, dont les droits requis |
| Modifier les droits d'un utilisateur (`PUT /api/v1/projects/users`) | Droits détenus par l'appelant ; seul un admin modifie un admin ; personne ne se modifie lui-même |
| Nommer un admin (`POST /api/v1/projects/users/admin`) | Admin seulement ; le nouvel admin reçoit les droits de l'appelant |
| Créer une clé d'API (`POST /api/v1/projects/consumers`) | Droits de la clé détenus par l'appelant |
| Modifier une clé d'API (`PUT /api/v1/projects/consumers`) | Droits de la clé détenus par l'appelant |

Les clés se gèrent dans gandalf.web. Les gérer par l'API demanderait une clé de
gestion distincte des clés de décision ; il n'y en a pas.

---

## Comptes et mots de passe temporaires

Un **mot de passe temporaire** est généré par l'API, rendu une seule fois à
qui l'a demandé, et envoyé à l'adresse du compte quand l'envoi d'e-mails
fonctionne (`email_sent` dans la réponse). Un e-mail refusé est écrit dans le
journal de l'application et ne fait pas échouer la requête : le compte est
déjà enregistré. Trois routes en donnent un :

| Route | Qui | Effet |
| ----- | --- | ----- |
| `POST /api/v1/projects/collaborators/confirm` (invitation en attente) | Admin d'application | Crée le compte, actif, dans l'application avec le rôle et les droits de l'invitation |
| `POST /api/v1/projects/collaborators/account` | Admin d'application | Crée le compte, actif, dans l'application avec le rôle et les droits donnés. Corps : `email`, `username` (facultatif, tiré de l'adresse sinon), `role`, `scope`. Une adresse déjà utilisée par un compte est refusée (422) : ce compte s'ajoute avec `POST /api/v1/projects/users`. Une invitation en attente de cette adresse dans cette application est consommée |
| `POST /api/v1/superadmin/users/{id}/password` | Superadmin | Remplace le mot de passe d'un compte existant, l'active si son adresse n'était pas vérifiée, et ferme ses sessions (ses jetons d'accès et de rafraîchissement sont supprimés) |

Un mot de passe temporaire **doit être remplacé**. Le compte porte
`must_change_password: true` (dans `GET /api/v1/users/current`) ; d'ici là, le
middleware de route `oauth` ([app/Http/Middleware/Authenticate.php](app/Http/Middleware/Authenticate.php))
le laisse seulement lire et modifier son profil (`GET` et
`PUT /api/v1/users/current`). Toute autre route utilisateur répond
`403 password_change_required`. Tout mot de passe choisi par la personne lève
l'indicateur : `PUT /api/v1/users/current` avec `password` et
`current_password` (le mot de passe temporaire), ou le lien de
`PUT /api/v1/users/password/reset`.

Les routes de comptes du superadmin refusent son propre compte (403
`own_account` : passer par le profil) et celui d'un superadmin (403
`superadmin_account` : retirer d'abord l'indicateur avec la commande). La
suppression d'un compte le retire de toutes ses applications, annule ses
invitations en attente et le supprime ; une application qui se retrouve sans
admin est signalée par gandalf.web. `GET /api/v1/superadmin/users` accepte
`search` (début d'un mot du nom d'utilisateur ou de l'adresse e-mail, adresse
en attente comprise, sans tenir compte de la casse), `without_application=1`,
`size` (1 à 200, 50 par défaut) et `page` ; chaque compte est accompagné de
`applications` (`_id`, `title`, `role`) et de `created_at`.

---

## Journal d'administration

Chaque requête d'API d'un superadmin est enregistrée une fois traitée, lectures
comprises, requêtes refusées ou en échec comprises, dans la collection
`admin_log` ([app/Models/AdminLogEntry.php](app/Models/AdminLogEntry.php)) :

| Champ | Contenu |
| ----- | ------- |
| `user_id`, `email` | Le superadmin ; null pour une commande |
| `action` | `request`, `superadmin.grant` ou `superadmin.revoke` |
| `method`, `path` | La requête telle que routée |
| `application_id` | L'application de `X-Application`, s'il y en a une |
| `status` | Statut HTTP de la réponse |
| `ip` | IP du client |
| `details` | Ce que le contrôleur a noté : `application_id` et `title` d'une application créée, `title` d'une application supprimée ; `user_id`, `email`, `username` d'un compte créé, doté d'un mot de passe temporaire ou supprimé (avec ses `applications`) ; pour une commande, le compte concerné |
| `created_at` | Date de l'entrée, en ISO 8601 avec son décalage |

Les corps des requêtes et les réponses ne sont pas conservés : ils contiennent
les données des applications, et un mot de passe temporaire n'atteint jamais le
journal. Les requêtes des autres utilisateurs et des clés d'API ne sont
pas enregistrées. L'application ne modifie ni ne purge jamais les entrées.

L'enregistrement a lieu après l'envoi de la réponse
([SuperadminAuditMiddleware](app/Http/Middleware/SuperadminAuditMiddleware.php)) :
un échec d'enregistrement est écrit dans le journal de l'application et ne
change pas la réponse.

---

## API HTTP

| Route | Qui | Notes |
| ----- | --- | ----- |
| `POST /api/v1/projects` | Superadmin | 201 avec l'application, `users: []` ; 403 `access_denied` sinon |
| `GET /api/v1/projects` | Tout utilisateur | Ses applications ; toutes les applications pour un superadmin |
| `DELETE /api/v1/projects` | Superadmin | Avec `X-Application` ; 403 sinon |
| `GET /api/v1/projects/users` | Membre ou superadmin | L'entrée de l'appelant ; pour un superadmin, `role: admin`, tous les droits utilisateur, `superadmin: true`, `member` |
| `PUT /api/v1/projects/consumers` | `consumers_manage` | 422 quand un droit de la clé n'est pas détenu par l'appelant |
| `GET /api/v1/superadmin/log` | Superadmin | Plus récent d'abord ; `application_id` (sa création comprise), `user_id`, `size` (1 à 200, 50 par défaut), `page` ; `paging` comme les autres listes |
| `GET /api/v1/superadmin/users` | Superadmin | Tous les comptes avec leurs applications ; `search`, `without_application`, `size`, `page` |
| `POST /api/v1/superadmin/users/{id}/password` | Superadmin | `temporary_password`, `email_sent` ; 403 sur son propre compte ou celui d'un superadmin |
| `DELETE /api/v1/superadmin/users/{id}` | Superadmin | Les applications quittées ; 403 sur son propre compte ou celui d'un superadmin, 404 inconnu |
| `POST /api/v1/projects/collaborators/account` | Admin d'application | 201 avec `user_id`, `username`, `email`, `temporary_password`, `email_sent` ; 422 sur une adresse ou un nom d'utilisateur déjà pris, ou des droits non détenus |
| `GET /api/v1/users/current` | Tout utilisateur | `superadmin: true` pour un superadmin, absent sinon ; `must_change_password: true` tant que le mot de passe est temporaire |

Les routes de superadmin demandent un token utilisateur
(`Authorization: Bearer`) : une clé d'API y reçoit 401, un utilisateur qui n'est
pas superadmin reçoit 403.

---

## Changements et migration

- **Créer une application** est réservé aux superadmins. Un utilisateur sans
  application ne peut plus en créer : gandalf.web lui demande de contacter
  Claims IA.
- **Supprimer une application** est réservé aux superadmins. Le droit
  utilisateur `project_delete` n'existe plus.
- La migration `2026_10_11_100000_TwoLevelAdministration` retire
  `project_delete` des membres et des invitations en attente, et indexe
  `admin_log`. Son `down` rend `project_delete` aux admins d'application et
  supprime les index. Elle ne nomme aucun superadmin.
- Le jeu de données local (`database/seeds/DatabaseSeeder.php`) fait de
  `admin@gndf.io` un superadmin.

Ordre de déploiement :

1. Déployer l'API et lancer `php artisan migrate`.
2. Attribuer le rôle aux comptes Claims IA avec `php artisan gandalf:superadmin`.
3. Déployer gandalf.web.

Retour arrière : revenir à la version précédente de l'API, puis
`php artisan migrate:rollback` pour cette migration. Les indicateurs
`superadmin` et la collection `admin_log` restent et sont ignorés.

---

## Plan du code

| Fichier | Rôle |
| ------- | ---- |
| [app/Models/User.php](app/Models/User.php) | `isSuperadmin()`, `memberOf()` : l'entrée admin du superadmin dans chaque application |
| [app/Http/Controllers/ApplicationsController.php](app/Http/Controllers/ApplicationsController.php) | Remplace `POST /projects`, `GET /projects`, `PUT /projects/consumers` du paquet |
| [app/Http/Controllers/SuperadminController.php](app/Http/Controllers/SuperadminController.php) | `GET /superadmin/log` ; les comptes : liste, mot de passe temporaire, suppression |
| [app/Http/Controllers/ProjectsController.php](app/Http/Controllers/ProjectsController.php) | Confirmation et création d'un compte dans une application |
| [app/Services/Accounts.php](app/Services/Accounts.php) | Mot de passe temporaire, nom d'utilisateur tiré d'une adresse, e-mail qui ne fait pas échouer la requête |
| [app/Http/Middleware/Authenticate.php](app/Http/Middleware/Authenticate.php) | Middleware de route `oauth` : un mot de passe temporaire n'ouvre que le profil |
| [app/Http/Middleware/SuperadminMiddleware.php](app/Http/Middleware/SuperadminMiddleware.php) | Middleware de route `superadmin` |
| [app/Http/Middleware/SuperadminAuditMiddleware.php](app/Http/Middleware/SuperadminAuditMiddleware.php) | Middleware global de fin de requête : enregistre les requêtes des superadmins |
| [app/Services/AdminLog.php](app/Services/AdminLog.php) | Écrit le journal ; `note()` ajoute les détails d'un contrôleur à la requête en cours |
| [app/Console/Commands/Superadmin.php](app/Console/Commands/Superadmin.php) | `gandalf:superadmin` |
| [app/Http/routes.php](app/Http/routes.php) | Les routes remplacées, déclarées après celles du paquet |
| [config/applicationable.php](config/applicationable.php) | Droits utilisateur, ACL de `DELETE /projects` |

---

## Tests

- Unitaires, sans base de données :
  [tests/unit/SuperadminTest.php](tests/unit/SuperadminTest.php) (appartenance,
  indicateur, routes, middlewares, journal),
  [tests/unit/AccountsTest.php](tests/unit/AccountsTest.php) (mots de passe
  temporaires, middleware `oauth`, routes des comptes) et
  [tests/unit/AclCoverageTest.php](tests/unit/AclCoverageTest.php). Depuis le
  répertoire de l'API : `vendor/bin/codecept run unit`.
- API : [tests/api/ProjectsCest.php](tests/api/ProjectsCest.php) (création et
  suppression réservées aux superadmins). L'assistant
  `ApiTester::createProject()` écrit l'application directement, son utilisateur
  en étant l'admin, et `makeSuperadmin()` pose l'indicateur.

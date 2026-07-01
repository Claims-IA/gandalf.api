# Gandalf API — Documentation complète

## Table des matières

1. [Présentation du projet][1]
2. [Diagramme d'architecture][2]
3. [Description des modules][3]
4. [Référence des classes][4]
5. [Flux d'authentification API][5]
6. [Flux du moteur de décision][6]
7. [Tâches planifiées][7]
8. [Variables d'environnement][8]

---

## Présentation du projet

Gandalf est une API de moteur de décision multi-tenant construite sur **PHP Lumen 5.2** et **MongoDB**. 

Elle permet aux utilisateurs de la plateforme et à leurs applications clientes de :

- Créer des **tables de décision** avec des champs typés, des variantes et des ensembles de règles/conditions
- Soumettre des valeurs de champs et recevoir un **résultat de décision** (approuvé/refusé/score)
- Prendre en charge les **tests A/B** (split testing) en exécutant plusieurs variantes d'une même table
- Consulter l'**historique d'audit** (changelog) pour chaque modification de table avec diff et rollback
- Gérer les **utilisateurs, les tokens OAuth2** et les **projets applicatifs** multi-tenant
- Exporter les données d'une application sous forme d'archive compressée pour sauvegarde ou migration

Le système repose sur trois niveaux d'accès :

| Niveau       | Authentification                     | Consommateur typique                                 |
| ------------ | ------------------------------------ | ---------------------------------------------------- |
| Public       | Identifiants client OAuth2 seulement | Flux d'inscription/réinitialisation non authentifiés |
| Utilisateur  | Token Bearer OAuth2                  | Admins connectés via le frontend web                 |
| Consommateur | Application + utilisateur ou client  | Applications externes effectuant des décisions       |

---

## Diagramme d'architecture


	
	┌────────────────────────────────────────────────────────────────┐
	│                      Couche HTTP                               │
	│  GET /  ·  POST /api/v1/users  ·  POST /api/v1/tables/…        │
	│                   app/Http/routes.php                          │
	└─────────┬──────────────────────────────────────────────────────┘
	          │ Pile de middlewares Lumen
	          │  JsonMiddleware (Accept/Content-Type)
	          │  NewRelicMiddleware (nommage des transactions APM)
	          │  oauth / oauth.basic.client (LumenOauth2)
	          │  applicationable / applicationable.acl
	          ▼
	┌────────────────────────────────────────────────────────────────┐
	│                      Contrôleurs                               │
	│  UsersController · TablesController · DecisionsController      │
	│  ConsumerController · ChangelogController · ProjectsController │
	└─────────┬───────────────────────────────────┬──────────────────┘
	          │                                   │
	          ▼                                   ▼
	┌───────────────────┐       ┌────────────────────────────────────┐
	│  Dépôts           │       │         Services                   │
	│  UsersRepository  │       │  Scoring (cœur du moteur)          │
	│  TablesRepository │       │  ConditionsTypes (opérateurs)      │
	│  DecisionsRepo    │       │  Mail (Postmark)                   │
	└─────────┬─────────┘       │  Intercom · Mixpanel               │
	          │                 │  DbTransfer (mongoexport)          │
	          ▼                 │  Hasher (génération de tokens)     │
	┌───────────────────┐       └────────────────────────────────────┘
	│     Modèles       │
	│  User · Table     │       ┌────────────────────────────────────┐
	│  Decision · Rule  │       │     Système d'événements           │
	│  Condition        │       │  Événements : Decisions\Make       │
	│  Variant · Field  │       │               Users\Create/Update  │
	│  Preset           │       │  EventListener → Intercom/Mixpanel │
	│  Invitation       │       └────────────────────────────────────┘
	│  ConditionType    │
	└─────────┬─────────┘       ┌────────────────────────────────────┐
	          │                 │     Observateurs                   │
	          ▼                 │  UserObserver  (hash mdp, email)   │
	┌───────────────────┐       │  TableObserver (changelog)         │
	│    MongoDB        │       │  InvitationsObserver (invitation)  │
	│  users            │       └────────────────────────────────────┘
	│  tables           │
	│  decisions        │       ┌────────────────────────────────────┐
	│  applications     │       │     Commandes console              │
	│  changelogs       │       │  SendStatistic    (chaque minute)  │
	│  oauth_clients    │       │  DeleteExpiredTokens (toutes les h │
	│  invitations      │       │  DeleteExpiredProjectDumps (2×/j)  │
	└───────────────────┘       └────────────────────────────────────┘

---

## Description des modules

### Contrôleurs (`app/Http/Controllers/`)

Les contrôleurs gèrent l'analyse des requêtes HTTP, la validation des entrées et le formatage des réponses. Ils sont minces — toute la logique métier est déléguée aux dépôts ou aux services.

| Classe                | Rôle                                                                                            |
| --------------------- | ----------------------------------------------------------------------------------------------- |
| `Controller`          | Classe de base ; simple wrapper du contrôleur de routage Lumen                                  |
| `UsersController`     | Inscription, vérification, réinitialisation de mot de passe, mise à jour du profil, invitations |
| `TablesController`    | CRUD des tables de décision et endpoint d'analyse                                               |
| `DecisionsController` | Accès administrateur en lecture seule aux décisions et mise à jour des métadonnées              |
| `ConsumerController`  | Évaluation des décisions et récupération des résultats côté consommateur                        |
| `ChangelogController` | Liste de l'historique d'audit, diff et rollback                                                 |
| `ProjectsController`  | Suppression d'application et export des données                                                 |

### Modèles (`app/Models/`)

Tous les modèles étendent `Base` (qui étend Jenssegers MongoDB Eloquent). Les documents embarqués (`Field`, `Rule`, `Condition`, `Preset`, `Variant`) sont stockés sous forme de tableaux imbriqués dans leur document parent.

| Classe          | Collection MongoDB           | Rôle                                                                 |
| --------------- | ---------------------------- | -------------------------------------------------------------------- |
| `User`          | `users`                      | Utilisateur de la plateforme avec tokens OAuth et vérification email |
| `Table`         | `tables`                     | Table de décision avec variantes, champs et règles                   |
| `Decision`      | `decisions`                  | Enregistrement d'audit immuable d'une évaluation                     |
| `Field`         | embarqué dans Table/Decision | Définition d'un champ d'entrée (clé, type, source, preset)           |
| `Rule`          | embarqué dans Variant        | Règle ordonnée avec conditions et valeur de résultat                 |
| `Condition`     | embarqué dans Rule           | Comparaison d'un seul champ (field\\\_key, opérateur, valeur)        |
| `Variant`       | embarqué dans Table          | Variante A/B contenant des règles et une décision par défaut         |
| `Preset`        | embarqué dans Field          | Condition de pré-traitement appliquée avant l'évaluation des règles  |
| `Invitation`    | `invitations`                | Invitation en attente pour rejoindre une application                 |
| `ConditionType` | `condition_types`            | Espace réservé (types de conditions gérés dans le code)              |

### Dépôts (`app/Repositories/`)

Les dépôts encapsulent toute la logique de requête MongoDB. Ils étendent `AbstractRepository` de Nebo15/REST.

| Classe                | Modèles gérés | Méthodes clés                                                                       |
| --------------------- | ------------- | ----------------------------------------------------------------------------------- |
| `UsersRepository`     | User          | `createOrUpdate()` — gère le flux de vérification email et déclenche des événements |
| `TablesRepository`    | Table         | `createOrUpdate()`, `readListWithFilters()`, `analyzeTableDecisions()`              |
| `DecisionsRepository` | Decision      | `getDecisions()`, `getConsumerDecision()`, `updateMeta()`                           |

### Services (`app/Services/`)

| Classe            | Rôle                                                                                                    |
| ----------------- | ------------------------------------------------------------------------------------------------------- |
| `Scoring`         | Cœur du moteur de décision — évalue les règles, accumule les résultats, persiste les décisions          |
| `ConditionsTypes` | Définit tous les opérateurs de comparaison sous forme de closures ; évalue les conditions à l'exécution |
| `Mail`            | Intégration email Postmark (vérification, réinitialisation de mot de passe, invitations)                |
| `Intercom`        | Intégration CRM Intercom (synchronisation des profils, événements de décision, code sécurisé)           |
| `Mixpanel`        | Intégration analytics Mixpanel (événements utilisateur, compteurs de décisions)                         |
| `Hasher`          | Génération de tokens cryptographiquement forts et adaptés aux URL                                       |
| `DbTransfer`      | Export de données via mongoexport vers une archive .tar.gz                                              |
| `BaseEvents`      | Classe abstraite de base pour Intercom/Mixpanel ; fournit un helper de détection IP                     |

### Fournisseurs de services (`app/Providers/`)

| Classe                      | Rôle                                                                                           |
| --------------------------- | ---------------------------------------------------------------------------------------------- |
| `AppServiceProvider`        | Enregistre le singleton DbTransfer ; fournit des stubs no-op pour les intégrations désactivées |
| `AuthServiceProvider`       | Enregistre le driver `api_token` pour l'authentification par token simple                      |
| `BugsnagServiceProvider`    | Configure et enregistre le client de suivi d'erreurs Bugsnag                                   |
| `EventServiceProvider`      | Enregistre EventListener comme subscriber                                                      |
| `ObserverServiceProvider`   | Attache TableObserver, UserObserver, InvitationsObserver                                       |
| `ValidationServiceProvider` | Enregistre les règles de validation personnalisées et la classe Validator                      |

### Validateurs (`app/Validators/`)

| Classe             | Règles enregistrées                                                                                         |
| ------------------ | ----------------------------------------------------------------------------------------------------------- |
| `GeneralValidator` | `mongoId`, `json`, `betweenString`                                                                          |
| `TableValidator`   | `conditionType`, `conditionsCount`, `conditionsFieldKey`, `ruleThanType`, `probabilitySum`, `decision_type` |
| `UserValidator`    | `password`, `current_password`, `username`, `last_name`, `uniqueExceptUser`                                 |

### Middlewares (`app/Http/Middleware/`)

| Classe               | Rôle                                                                                      |
| -------------------- | ----------------------------------------------------------------------------------------- |
| `JsonMiddleware`     | Définit `Accept: application/json` et `Content-Type: application/json` sur chaque requête |
| `NewRelicMiddleware` | Nomme chaque transaction New Relic sous la forme `URI (METHOD)` pour la visibilité APM    |

### Exceptions (`app/Exceptions/`)

| Classe                         | Statut HTTP | Code d'erreur          | Déclenchée quand                                          |
| ------------------------------ | ----------- | ---------------------- | --------------------------------------------------------- |
| `Handler`                      | —           | —                      | Gestionnaire global d'exceptions ; convertit tout en JSON |
| `AdminIsNotActivatedException` | 403         | `admin_not_activated`  | L'application n'a aucun administrateur actif (vérifié)    |
| `ConditionException`           | —           | —                      | Clé d'opérateur de condition inconnue (capturée par TableValidator) |
| `FailedToSaveModel`            | 400         | `failed_to_save_model` | L'écriture MongoDB retourne false                         |
| `IdNotFoundException`          | 404         | `mongo_id_not_found`   | ID vide passé à `findById()`                              |
| `TokenExpiredException`        | 422         | `token_expired`        | Le token email/mot de passe a dépassé son TTL             |
| `TokenNotFoundException`       | 404         | `token_not_found`      | Aucun utilisateur trouvé avec le token donné              |
| `VariantNotFound`              | 404         | `variant_not_found`    | L'ID de variante demandé est introuvable sur la table     |

### Événements et écouteurs (`app/Events/`, `app/Listeners/`)

| Classe           | Type           | Déclenché par                                                    |
| ---------------- | -------------- | ---------------------------------------------------------------- |
| `Event`          | Base abstraite | —                                                                |
| `Decisions\Make` | Événement      | `Scoring::check()` après la persistance de la décision           |
| `Users\Create`   | Événement      | `UsersRepository::createOrUpdate()` lors d'un nouvel utilisateur |
| `Users\Update`   | Événement      | `UsersRepository::createOrUpdate()` lors d'une mise à jour       |
| `EventListener`  | Subscriber     | Gère les trois événements ; route vers Intercom et Mixpanel      |

### Observateurs (`app/Observers/`)

| Classe                | Modèle     | Hooks actifs                                       |
| --------------------- | ---------- | -------------------------------------------------- |
| `UserObserver`        | User       | `creating` (username auto, activation),            |
|                       |            | `created` (envoi email de vérification),           |
|                       |            | `saving` (hash du mot de passe)                    |
| `TableObserver`       | Table      | `saved` (écriture d'un snapshot dans le changelog) |
| `InvitationsObserver` | Invitation | `created` (envoi de l'email d'invitation)          |

### Commandes console (`app/Console/Commands/`)

| Commande                    | Signature        | Planification | Rôle                                                                 |
| --------------------------- | ---------------- | ------------- | -------------------------------------------------------------------- |
| `SendStatistic`             | `send:statistic` | Chaque minute | Envoie le nombre de décisions par minute à CachetHQ                  |
| `DeleteExpiredTokens`       | `tokens:delete`  | Toutes les h  | Supprime les tokens OAuth et email expirés des documents utilisateur |
| `DeleteExpiredProjectDumps` | `dump:delete`    | 2× par jour   | Supprime les archives d'export de plus de 24 heures                  |

---

## Référence des classes

### `App\Application`

Étend `Laravel\Lumen\Application`.

| Méthode                   | Description                                                                                     |
| ------------------------- | ----------------------------------------------------------------------------------------------- |
| `registerErrorHandling()` | Appelle le parent puis réapplique la suppression de `E_DEPRECATED` pour la compatibilité PHP 8+ |
| `getMonologHandler()`     | Retourne un StreamHandler ligne unique vers stdout en production ; handler parent sinon         |

---

### `App\Models\Base`

Modèle MongoDB Eloquent abstrait.

| Méthode                     | Description                                                                                  |
| --------------------------- | -------------------------------------------------------------------------------------------- |
| `getId()`                   | Retourne la valeur de l'attribut `_id`                                                       |
| `isNew()`                   | Retourne true si le modèle n'a pas encore de `_id`                                           |
| `createId()`                | Génère et assigne un nouvel ObjectID MongoDB                                                 |
| `save(array $options = [])` | Sauvegarde et retourne `$this` ; lève `FailedToSaveModel` en cas d'échec                     |
| `static findById($id)`      | Recherche par `_id` ; lève `IdNotFoundException` si vide, `ModelNotFoundException` si absent |

---

### `App\Models\User`

| Méthode                            | Description                                                                        |
| ---------------------------------- | ---------------------------------------------------------------------------------- |
| `createResetPasswordToken()`       | Génère un token de réinitialisation (TTL 1h), stocké dans la map `tokens`          |
| `getResetPasswordToken()`          | Retourne le tableau du token `reset_password` ou false                             |
| `findByResetPasswordToken($token)` | Recherche l'utilisateur par token ; lève une exception si absent ou expiré         |
| `removeResetPasswordToken()`       | Supprime l'entrée `reset_password` de `tokens`                                     |
| `changePassword($new_password)`    | Définit un nouveau mot de passe en clair (hashé par l'observateur à la sauvegarde) |
| `createVerifyEmailToken()`         | Génère un token de vérification email (TTL 1h), stocké dans la map `tokens`        |
| `getVerifyEmailToken()`            | Retourne le tableau du token `verify_email` ou false                               |
| `verifyEmail()`                    | Promeut `temporary_email` → `email`, définit `active=true`, supprime le token      |
| `findByVerifyEmailToken($token)`   | Recherche l'utilisateur par token ; lève une exception si absent ou expiré         |
| `removeVerifyEmailToken()`         | Supprime l'entrée `verify_email` de `tokens`                                       |
| `findByToken($token, $type, ...)`  | Recherche générique de token avec vérification d'expiration                        |
| `isActive()`                       | Retourne `$this->active`                                                           |

---

### `App\Models\Table`

| Méthode                                 | Description                                                                         |
| --------------------------------------- | ----------------------------------------------------------------------------------- |
| `fields()`                              | Relation embedded-many vers les modèles `Field`                                     |
| `variants()`                            | Relation embedded-many vers les modèles `Variant`                                   |
| `toListArray()`                         | Retourne une représentation minimale de liste avec un résumé des variantes          |
| `setFields($fields)`                    | Remplace tous les champs embarqués (supprime d'abord, puis recrée avec Presets)     |
| `setVariants($variants)`                | Remplace toutes les variantes embarquées (délègue les règles à `Variant::setRules`) |
| `getVariantForCheck($variantId = null)` | Sélectionne une variante selon la stratégie first/random/percent                    |
| `getFieldsKeys()`                       | Retourne une Collection de chaînes de clés de champs                                |

---

### `App\Models\Decision`

| Méthode             | Description                                                                      |
| ------------------- | -------------------------------------------------------------------------------- |
| `rules()`           | Relation embedded-many vers les snapshots de `Rule`                              |
| `fields()`          | Relation embedded-many vers les snapshots de `Field`                             |
| `toConsumerArray()` | Retourne la décision adaptée aux consommateurs avec des timestamps ISO-8601      |
| `toArray()`         | Surcharge le parent pour convertir les ObjectIDs MongoDB dans `table` en chaînes |
| `getTableArray()`   | Retourne le sous-document `table` avec les ObjectIDs en chaînes                  |

---

### `App\Models\Variant`

| Méthode            | Description                                                                 |
| ------------------ | --------------------------------------------------------------------------- |
| `rules()`          | Relation embedded-many vers les modèles `Rule`                              |
| `setRules($rules)` | Remplace toutes les règles (délègue les conditions à `Rule::setConditions`) |

---

### `App\Models\Rule`

| Méthode                      | Description                                              |
| ---------------------------- | -------------------------------------------------------- |
| `conditions()`               | Relation embedded-many vers les modèles `Condition`      |
| `setConditions($conditions)` | Remplace toutes les conditions                           |
| `setThanAttribute($value)`   | Arrondit les flottants à 5 décimales avant la sauvegarde |
 
---

### `App\Services\Scoring`

| Méthode                                  | Description                                                                  |
| ---------------------------------------- | ---------------------------------------------------------------------------- |
| `check($id, $values, $appId, $showMeta)` | Pipeline complet d'évaluation ; retourne un tableau adapté aux consommateurs |
| `checkCondition(Condition, $value)`      | Définit `condition->matched` via ConditionsTypes                             |
| `prepareFieldPreset(Field, $value)`      | Applique la transformation de preset avec mise en cache par exécution        |
| `createValidationRules(Table)`           | Construit les règles de validation Lumen à partir des champs de la table     |
| `getValidationRuleByType($type)`         | Mappe une chaîne de type de champ vers un nom de règle Lumen                 |

---

### `App\Services\ConditionsTypes`

Opérateurs supportés :

| Opérateur  | Type d'entrée | Description                                         |
| ---------- | ------------- | --------------------------------------------------- |
| `$is_set`  | —             | Toujours vrai (le champ existe dans la requête)     |
| `$is_null` | —             | Vrai si la valeur du champ est null                 |
| `$eq`      | —             | Égalité non stricte (`==`)                          |
| `$ne`      | —             | Inégalité non stricte (`!=`)                        |
| `$gt`      | numérique     | Strictement supérieur à                             |
| `$gte`     | numérique     | Supérieur ou égal à                                 |
| `$lt`      | numérique     | Strictement inférieur à                             |
| `$lte`     | numérique     | Inférieur ou égal à                                 |
| `$between` | betweenString | Entre deux valeurs (format : `min;max`)             |
| `$in`      | —             | Valeur dans une liste séparée par des virgules      |
| `$nin`     | —             | Valeur absente d'une liste séparée par des virgules |

`$any`
`$between` =>  `a <= x <= b`
`$between_excl` =>  `a < x < b`
`$between_lexcl` => `a < x <= b``
`$between_rexcl` => `a <= x < b``


| Méthode                                          | Description                                                                               |
| ------------------------------------------------ | ----------------------------------------------------------------------------------------- |
| `getConditionsRules()`                           | Retourne une chaîne des clés d'opérateurs séparées par des virgules pour les règles `in:` |
| `checkConditionValue($key, $condVal, $fieldVal)` | Évalue une condition ; retourne un booléen                                                |
| `getCondition($key)`                             | Retourne le tableau de définition de l'opérateur ; lève `ConditionException` si inconnu   |

---

### `App\Repositories\TablesRepository`

| Méthode                                         | Description                                                                                     |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `readListWithFilters(array $filters)`           | Pagine les tables avec des filtres optionnels (titre/description/matching\\\_type)              |
| `createOrUpdate($values, $id = null)`           | Crée ou met à jour une table avec remplacement complet des champs et variantes                  |
| `analyzeTableDecisions($table_id, $variant_id)` | Agrège l'historique des décisions pour calculer les taux de déclenchement des règles/conditions |

---

### `App\Repositories\DecisionsRepository`

| Méthode                                       | Description                                                         |
| --------------------------------------------- | ------------------------------------------------------------------- |
| `getDecisions($size, $table_id, $variant_id)` | Pagine les décisions avec filtre optionnel par table/variante       |
| `getConsumerDecision($id)`                    | Retourne la décision sous forme de tableau adapté aux consommateurs |
| `updateMeta($id, $meta)`                      | Valide et persiste les métadonnées sur une décision existante       |

---

### `App\Repositories\UsersRepository`

| Méthode                               | Description                                                                                            |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `createOrUpdate($values, $id = null)` | Crée/met à jour l'utilisateur avec flux de vérification email ; déclenche les événements Create/Update |

---

## Flux d'authentification API

	1. Le client obtient un token d'accès OAuth2
	   POST /oauth/access_token
	   Corps : { grant_type, client_id, client_secret, username, password }
	   → Retourne : { access_token, refresh_token, expires_in, token_type }
	
	2. Le client inclut le token dans les requêtes suivantes
	   Authorization: Bearer <access_token>
	   X-Application: <application_id>   ← identifie le projet tenant
	
	3. Le middleware LumenOauth2 valide le Bearer token dans la collection users
	   → Résout $request->user() vers le modèle User authentifié
	
	4. Le middleware LumenApplicationable valide l'en-tête X-Application
	   → Résout le modèle Application et vérifie le rôle et le scope de l'utilisateur
	
	5. Le middleware applicationable.acl vérifie que le scope de l'utilisateur inclut
	   la permission requise pour la route (ex. 'tables_create', 'decisions_make')
	
	6. La méthode du contrôleur s'exécute avec $request->user() et Application disponibles

### Flux d'inscription utilisateur

	POST /api/v1/users                     ← Identifiants client OAuth2 seulement
	  ↓ UsersController::create()
	  ↓ UsersRepository::createOrUpdate()
	    → stocke l'email comme temporary_email
	    → génère un token verify_email (TTL 1h)
	    → déclenche l'événement Users\Create
	  ↓ UserObserver::created()
	    → envoie l'email de vérification via le service Mail
	  → Retourne HTTP 201 avec les données utilisateur
	
	POST /api/v1/users/verify/email        ← Identifiants client OAuth2 seulement
	  Corps : { token }
	  ↓ User::findByVerifyEmailToken()     ← lève TokenNotFound/TokenExpired
	  ↓ User::verifyEmail()
	    → déplace temporary_email → email
	    → définit active = true
	    → supprime le token verify_email
	  → Retourne HTTP 200 avec l'utilisateur mis à jour

### Flux de réinitialisation de mot de passe

	POST /api/v1/users/password/reset      ← initiation
	  Corps : { email }
	  ↓ User::createResetPasswordToken()   ← token TTL 1h
	  ↓ Mail::sendRecoveryPassword()       ← email Postmark avec lien de réinitialisation
	
	PUT /api/v1/users/password/reset       ← finalisation
	  Corps : { token, password }
	  ↓ User::findByResetPasswordToken()   ← valide le token et l'expiration
	  ↓ User::changePassword()             ← définit le mot de passe en clair
	  ↓ UserObserver::saving()             ← hash avec bcrypt
	  → Retourne HTTP 200 avec l'utilisateur mis à jour

---

## Flux du moteur de décision

	POST /api/v1/tables/{id}/decisions
	  Corps : { field_key_1: valeur, field_key_2: valeur, ..., variant_id?: id }
	
	1. ConsumerController::tableCheck()
	   → Vérifier que l'application a au moins un administrateur actif
	   → Déléguer à Scoring::check()
	
	2. Scoring::check($id, $values, $appId, $showMeta)
	
	   a. Charger la table depuis MongoDB via TablesRepository::read($id)
	
	   b. Valider les valeurs soumises par rapport au schéma de champs de la table
	      - Chaque champ devient : "field_key" => "present|{type}"
	      - 'present' autorise null ; le type est numeric / boolean / string
	
	   c. Sélectionner la variante :
	      - Si variant_id fourni : utiliser cette variante spécifique
	      - variants_probability = 'first':   toujours utiliser la première variante
	      - variants_probability = 'random':  sélection aléatoire uniforme
	      - variants_probability = 'percent': aléatoire pondéré via distribution cumulée
	
	   d. Pour chaque Règle dans la variante (dans l'ordre) :
	      Pour chaque Condition dans la règle :
	        i.  Trouver la définition de Field correspondant à condition.field_key
	        ii. Appliquer le Preset du champ (si configuré) :
	              preset.condition évaluée par rapport à la valeur brute
	              → résultat (vrai/faux ou valeur transformée) mis en cache pour ce champ
	        iii. Évaluer : ConditionsTypes::checkConditionValue(
	               condition.condition,  ← clé d'opérateur ex. '$gt'
	               condition.value,      ← seuil de la table
	               field_value           ← de la requête (ou résultat du preset)
	             ) → stocke le résultat dans condition.matched
	
	      Déterminer si TOUTES les conditions ont correspondu (logique ET) :
	        - Table de décision : si tout correspond ET final_decision est encore null
	                              → définir final_decision = rule.than
	                              → mettre à jour title/description depuis la règle
	        - Table de scoring :  si tout correspond → ajouter rule.than (float) au total
	
	   e. Si aucune règle n'a correspondu (ou score = 0) : utiliser variant.default_decision
	
	   f. Construire le snapshot scoring_data :
	      { table, application, applications, title, description,
	        default_decision, fields, rules (avec flags matched par condition),
	        request, final_decision }
	
	   g. Persister le document Decision dans MongoDB
	
	   h. Déclencher l'événement Decisions\Make
	      → EventListener trouve les IDs des admins pour l'application
	      → Intercom : crée un événement 'decision-made' par admin
	      → Mixpanel : incrémente 'Decisions count' par admin
	
	   i. Retourner decision.toConsumerArray()
	      { _id, table, application, title, description,
	        final_decision, request, created_at, updated_at,
	        rules: [{ title, description, decision }] }
	      Note : 'rules' est omis quand le paramètre show_meta de l'application est false
	
	Exemple — Résultat d'une table de scoring :
	  Règles : "Bonne IP" (+10), "Banque de confiance" (+10), "Faible rotation" (+60)
	  Si les trois correspondent : final_decision = 10 + 10 + 60 = 80
	  Si seules les deux premières correspondent : final_decision = 10 + 10 = 20
	  Si rien ne correspond : final_decision = default_decision (ex. 30)
	
	Exemple — Résultat d'une table de décision :
	  Règles évaluées dans l'ordre : "Approuvé", "Refusé", "En révision"
	  La première règle entièrement correspondante gagne : final_decision = "Approuvé"

---

## Tâches planifiées

Le Kernel Console planifie trois tâches récurrentes via le planificateur de Laravel. Exécuter `php artisan schedule:run` chaque minute (typiquement via cron : `* * * * * php /path/to/artisan schedule:run`).

| Tâche            | Fréquence                      | Rôle                                                                                        |
| ---------------- | ------------------------------ | ------------------------------------------------------------------------------------------- |
| `send:statistic` | Chaque minute                  | Compte les décisions des 60 dernières secondes, envoie à la page de statut CachetHQ         |
| `tokens:delete`  | Toutes les heures              | Supprime les tokens OAuth accès/rafraîchissement et email expirés des documents utilisateur |
| `dump:delete`    | 2× par jour (01:00, 13:00 UTC) | Supprime les archives d'export .tar.gz de plus de 24h dans `public/dump/`                   |

---

## Variables d'environnement

| Variable              | Défaut         | Description                                                       |
| --------------------- | -------------- | ----------------------------------------------------------------- |
| `APP_ENV`             | —              | Nom de l'environnement (`local`, `staging`, `prod`)               |
| `APP_DEBUG`           | —              | Si `true`, les erreurs 500 exposent la trace complète de la pile  |
| `APP_LOG_PATH`        | `php://stdout` | Destination des logs (stdout en production)                       |
| `DB_HOST`             | —              | Hôte MongoDB                                                      |
| `DB_PORT`             | —              | Port MongoDB                                                      |
| `DB_DATABASE`         | —              | Nom de la base de données MongoDB                                 |
| `ACTIVATE_ALL_USERS`  | false          | Si `true`, ignore la vérification email (développement seulement) |
| `INTERCOM_ENABLED`    | false          | Active l'intégration Intercom                                     |
| `INTERCOM_APP_SECRET` | —              | Secret HMAC pour la vérification d'identité Intercom              |
| `MIXPANEL_ENABLED`    | false          | Active l'intégration Mixpanel                                     |
| `BUGSNAG_ENABLED`     | —              | Active le suivi d'erreurs Bugsnag                                 |

[1]:	#présentation-du-projet
[2]:	#diagramme-darchitecture
[3]:	#description-des-modules
[4]:	#référence-des-classes
[5]:	#flux-dauthentification-api
[6]:	#flux-du-moteur-de-décision
[7]:	#tâches-planifiées
[8]:	#variables-denvironnement
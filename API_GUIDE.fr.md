# Gandalf Decision Engine — Guide de l'API

> **Spécification lisible par machine :** [openapi.yaml](openapi.yaml) (OpenAPI 3.0)

---

## Table des matières

1. [Vue d'ensemble](#vue-densemble)
2. [Concepts clés](#concepts-clés)
3. [Authentification](#authentification)
4. [En-tête de contexte applicatif](#en-tête-de-contexte-applicatif)
5. [Format des requêtes et réponses](#format-des-requêtes-et-réponses)
6. [Pagination](#pagination)
7. [Gestion des erreurs](#gestion-des-erreurs)
8. [Mode sandbox](#mode-sandbox)
9. [Référence des endpoints](#référence-des-endpoints)
   - [Santé du service](#santé-du-service)
   - [Auth — OAuth 2.0](#auth--oauth-20)
   - [Utilisateurs](#utilisateurs)
   - [Projets](#projets)
   - [Tables de décision](#tables-de-décision)
   - [Décisions (Admin)](#décisions-admin)
   - [Décisions (Consommateur)](#décisions-consommateur)
   - [Journal des modifications](#journal-des-modifications)
10. [Fonctionnement du moteur de décision](#fonctionnement-du-moteur-de-décision)
    - [Types de correspondance](#types-de-correspondance)
    - [Opérateurs de condition](#opérateurs-de-condition)
    - [Présets](#présets)
    - [Sélection de variante](#sélection-de-variante)
11. [Types de champs](#types-de-champs)
12. [Types de décision](#types-de-décision)
13. [Guide de démarrage rapide](#guide-de-démarrage-rapide)

---

## Vue d'ensemble

Gandalf est un **moteur de décision basé sur des règles**. Vous définissez des *tables de décision*
qui décrivent la logique d'une décision métier (ex. : approbation de crédit, scoring de fraude,
routage d'appels). Votre application appelle ensuite l'API à l'exécution en fournissant des valeurs
de champs et reçoit en retour une décision déterministe (ou probabiliste).

Chaque évaluation est enregistrée sous forme d'une *Décision* immuable, vous offrant un journal
d'audit complet avec un instantané de toutes les entrées et sorties.

---

## Concepts clés

| Concept | Description |
|---------|-------------|
| **Table** | Une table de décision. Contient le schéma des champs, la stratégie de correspondance, et une ou plusieurs variantes. |
| **Champ** | Un champ d'entrée que le consommateur doit fournir (ex. : `credit_score`, `age`). Possède un type et une transformation de préset optionnelle. |
| **Variante** | Une variante de test A/B au sein d'une table. Contient une liste ordonnée de règles. Une seule variante est évaluée par requête. |
| **Règle** | Un ensemble de conditions (toutes doivent être vérifiées — logique ET). Si elle se déclenche, elle contribue sa valeur `than` à la décision finale. |
| **Condition** | Une comparaison d'une valeur de champ contre un seuil à l'aide d'un opérateur (ex. : `$gte 700`). |
| **Préset** | Une transformation appliquée à la valeur d'un champ *avant* l'évaluation des conditions (ex. : convertir toute valeur non nulle en `true`). |
| **Décision** | Enregistrement d'audit immuable créé à chaque évaluation d'une table. Stocke l'instantané complet de la requête, les règles déclenchées et le résultat final. |

---

## Authentification

L'API utilise **OAuth 2.0**. Trois méthodes d'authentification sont supportées :

### 1. Token Bearer OAuth (endpoints admin et utilisateur)

Obtenez un token via `POST /oauth/token` avec le grant `password` :

```http
POST /oauth/token
Authorization: Basic base64(client_id:client_secret)
Content-Type: application/json

{
  "grant_type": "password",
  "username": "john.doe",
  "password": "s3cr3t!"
}
```

Réponse :
```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJhbGci...",
  "refresh_token": "def502..."
}
```

Transmettez ensuite ce token dans chaque requête :
```http
Authorization: Bearer eyJhbGci...
```

### 2. Credentials client OAuth en Basic auth (endpoints publics utilisateur)

Requis pour les endpoints d'inscription et de réinitialisation de mot de passe.
Encodez votre `client_id:client_secret` OAuth en HTTP Basic auth.

### 3. Clé API consommateur (endpoints d'évaluation de décision)

Vos credentials consommateur (émis lors de la création d'un consommateur dans le
système Applicationable) sont transmis en HTTP Basic auth.

---

## En-tête de contexte applicatif

La plupart des endpoints nécessitent l'en-tête **`X-Application`**. Il s'agit de l'ObjectID MongoDB
de l'*application* (projet) propriétaire de la ressource accédée.

```http
X-Application: 507f1f77bcf86cd799439011
```

Toutes les requêtes sont automatiquement isolées à cette application pour garantir
la séparation multi-tenant — vous ne pouvez pas accéder aux ressources d'autres applications.

---

## Format des requêtes et réponses

Toutes les requêtes et réponses utilisent **JSON**.

```http
Content-Type: application/json
Accept: application/json
```

Les réponses réussies suivent cette enveloppe :

```json
{
  "meta": { "code": 200 },
  "data": { ... }
}
```

---

## Pagination

Les endpoints de liste retournent des résultats paginés :

```json
{
  "meta": { "code": 200 },
  "data": [ ... ],
  "paging": {
    "total": 42,
    "per_page": 20,
    "current_page": 1,
    "last_page": 3,
    "next_page_url": "https://api.example.com/api/v1/admin/tables?page=2",
    "prev_page_url": null
  }
}
```

Utilisez le paramètre `size` pour contrôler la taille de page (défaut : `20`) :

```
GET /api/v1/admin/tables?size=50&page=2
```

---

## Gestion des erreurs

```json
{
  "meta": { "code": 422 },
  "error": {
    "type": "validation_failed",
    "message": "The given data failed to pass validation.",
    "fields": {
      "email": ["The email has already been taken."],
      "password": ["The password must be at least 6 characters."]
    }
  }
}
```

| Code HTTP | Signification |
|-----------|--------------|
| `200` | Succès |
| `201` | Ressource créée |
| `401` | Non authentifié |
| `403` | Accès refusé (ex. : aucun admin actif) |
| `404` | Ressource introuvable |
| `410` | Token expiré |
| `422` | Erreur de validation |

---

## Mode sandbox

Lorsque le serveur fonctionne avec `APP_ENV=local`, certaines réponses contiennent un objet
`sandbox` avec les tokens normalement envoyés par e-mail. Cela permet de tester le flux
d'authentification complet sans serveur d'e-mail.

**Inscription** (`POST /api/v1/users`) :
```json
{
  "sandbox": {
    "token_email": { "token": "abc123...", "expired": 1705320600 }
  }
}
```

**Réinitialisation de mot de passe** (`POST /api/v1/users/password/reset`) :
```json
{
  "sandbox": {
    "reset_password_token": { "token": "def456...", "expired": 1705320600 }
  }
}
```

Le champ `expired` est un timestamp Unix indiquant la date d'expiration du token (TTL : **1 heure**).

---

## Référence des endpoints

### Santé du service

#### `GET /`

Retourne `ok` en texte brut. Aucune authentification requise. Destiné aux sondes de load balancer.

---

### Auth — OAuth 2.0

#### `POST /oauth/token`

| Type de grant | Cas d'usage |
|---------------|-------------|
| `password` | Connexion d'un utilisateur avec nom d'utilisateur + mot de passe |
| `client_credentials` | Authentification machine-à-machine (consommateur) |
| `refresh_token` | Renouveler un token d'accès à partir d'un refresh token |

---

### Utilisateurs

Tous les endpoints publics utilisateur nécessitent des credentials client OAuth en Basic auth
(`Authorization: Basic ...`).

#### `GET /api/v1/users/username?username=john.doe`

Vérifier la disponibilité d'un nom d'utilisateur. Retourne `200` s'il est disponible,
`422` s'il est pris ou invalide.

Format : 2 à 32 caractères, alphanumériques, tirets et points.

---

#### `POST /api/v1/users` — Inscription

```json
{
  "username": "john.doe",
  "email": "john.doe@example.com",
  "password": "s3cr3tPass!",
  "first_name": "Jean",
  "last_name": "Dupont"
}
```

Après la création, `active` vaut `false` jusqu'à la vérification de l'e-mail.
Mot de passe : 6 à 32 caractères.

---

#### `POST /api/v1/users/verify/email` — Vérifier l'e-mail

```json
{ "token": "abc123..." }
```

Promeut `temporary_email` → `email` et passe `active` à `true`. TTL du token : **1 heure**.

---

#### `POST /api/v1/users/verify/email/resend` — Renvoyer le lien de vérification

```json
{ "email": "john.doe@example.com" }
```

Génère un nouveau token et renvoie l'e-mail de confirmation à l'adresse `temporary_email`.

---

#### `POST /api/v1/users/password/reset` — Demander la réinitialisation du mot de passe

```json
{ "email": "john.doe@example.com" }
```

Envoie un e-mail de récupération. TTL du token : **1 heure**.

---

#### `PUT /api/v1/users/password/reset` — Finaliser la réinitialisation du mot de passe

```json
{ "token": "abc123...", "password": "nouveauMotDePasse!" }
```

---

#### `GET /api/v1/users/current` — Consulter son profil

Nécessite un token Bearer. Retourne le profil de l'utilisateur connecté ainsi qu'un `secure_code`
Intercom lorsque l'intégration est activée.

---

#### `PUT /api/v1/users/current` — Mettre à jour son profil

Nécessite un token Bearer. Tous les champs sont optionnels. La modification du `password`
nécessite de fournir `current_password`.

```json
{
  "username": "john.doe2",
  "email": "nouvelle@example.com",
  "password": "nouveauPass!",
  "current_password": "ancienPass!"
}
```

La modification de l'`email` déclenche un nouveau flux de vérification — la nouvelle adresse
est stockée dans `temporary_email` en attendant la confirmation.

---

#### `GET /api/v1/users?name=jean` — Rechercher des utilisateurs

Nécessite un token Bearer. Si `name` contient `@`, la recherche porte sur l'e-mail ;
sinon sur le nom d'utilisateur. L'utilisateur qui effectue la requête est exclu des résultats.
Résultats paginés.

---

#### `POST /api/v1/invite` — Inviter un utilisateur

Nécessite un token Bearer + `X-Application`.

```json
{
  "email": "collegue@example.com",
  "role": "manager",
  "scope": ["tables_read", "decisions_read"]
}
```

> La `scope` demandée doit être un **sous-ensemble** de la scope de l'utilisateur invitant.
> Toute tentative d'escalade de privilèges retourne HTTP 422.

Si l'invité possède déjà un compte, il est ajouté immédiatement à l'application.
Sinon, il est ajouté automatiquement lors de son inscription avec la même adresse e-mail.

---

### Projets

Tous les endpoints de projet nécessitent un token Bearer + `X-Application`.

#### `DELETE /api/v1/projects`

Supprime définitivement l'application et **toutes** ses tables de décision. **Irréversible.**

---

#### `GET /api/v1/projects/export`

Lance un `mongoexport` et retourne une URL de téléchargement vers une archive `.tar.gz`
contenant les tables, décisions et journaux de modifications.

```json
{ "data": { "url": "https://api.example.com/dump/export_....tar.gz" } }
```

---

### Tables de décision

Tous les endpoints de table nécessitent un token Bearer + `X-Application`.

#### `GET /api/v1/admin/tables` — Lister les tables

Paramètres de requête disponibles :

| Paramètre | Description |
|-----------|-------------|
| `title` | Filtre par préfixe (insensible à la casse) |
| `description` | Filtre par préfixe (insensible à la casse) |
| `matching_type` | `first`, `scoring_sum`, `scoring_max`, `scoring_min`, `scoring_count` |
| `size` | Nombre de résultats par page (défaut : 20) |

---

#### `POST /api/v1/admin/tables` — Créer une table

Exemple complet :

```json
{
  "title": "Scoring Crédit",
  "description": "Approuve ou refuse les demandes de crédit.",
  "matching_type": "first",
  "decision_type": "string",
  "variants_probability": "first",
  "fields": [
    {
      "key": "credit_score",
      "title": "Score de crédit",
      "type": "numeric",
      "source": "request",
      "preset": {}
    }
  ],
  "variants": [
    {
      "title": "Standard",
      "default_decision": "refuse",
      "default_title": "Demande refusée",
      "default_description": "Ne remplit pas les conditions minimales.",
      "rules": [
        {
          "than": "approuve",
          "title": "Bon crédit",
          "description": "Score supérieur ou égal à 700.",
          "conditions": [
            { "field_key": "credit_score", "condition": "$gte", "value": 700 }
          ]
        }
      ]
    }
  ]
}
```

---

#### `GET /api/v1/admin/tables/{id}` — Consulter une table

Retourne la table complète avec tous ses champs, variantes et règles.

---

#### `PUT /api/v1/admin/tables/{id}` — Mettre à jour une table

Remplacement complet. Fournissez les `_id` des sous-ressources existantes (champs, variantes,
règles, conditions) pour préserver leurs identifiants dans l'historique du changelog.

---

#### `DELETE /api/v1/admin/tables/{id}` — Supprimer une table

---

#### `GET /api/v1/admin/tables/{id}/{variant_id}/analytics` — Analytiques

Retourne les statistiques de déclenchement par règle et par condition, calculées à partir des
décisions historiques depuis la dernière modification de la table.

Le champ `probability` est un flottant `0.0–1.0` représentant la fraction des décisions où
la règle ou la condition s'est déclenchée.

```json
{
  "data": {
    "variants": [{
      "title": "Standard",
      "rules": [{
        "title": "Bon crédit",
        "probability": 0.42,
        "conditions": [{
          "field_key": "credit_score",
          "condition": "$gte",
          "value": 700,
          "probability": 0.55
        }]
      }]
    }]
  }
}
```

---

### Décisions (Admin)

Tous les endpoints admin de décision nécessitent un token Bearer + `X-Application`.

#### `GET /api/v1/admin/decisions` — Lister les décisions

Paramètres de requête : `size`, `table_id`, `variant_id`.

---

#### `GET /api/v1/admin/decisions/{id}` — Consulter une décision

Enregistrement d'audit complet avec les règles, conditions et leur état `matched`.

---

#### `PUT /api/v1/admin/decisions/{id}/meta` — Ajouter des métadonnées

Associez des métadonnées arbitraires à une décision après coup :

```json
{
  "meta": {
    "order_id": "CMD-12345",
    "ref_client": "CLI-789"
  }
}
```

Limites : maximum 24 clés, clés de 100 caractères max (alphanumérique + tirets),
valeurs de 500 caractères max.

---

### Décisions (Consommateur)

Les endpoints consommateur acceptent des credentials consommateur (`Authorization: Basic ...`)
ou un token Bearer.

#### `POST /api/v1/tables/{id}/decisions` — Évaluer une décision

Soumettez les valeurs de tous les champs définis dans la table. Tous les champs doivent être
présents (utilisez `null` pour les valeurs absentes).

```json
{
  "credit_score": 750,
  "revenu_annuel": 60000,
  "variant_id": "507f1f77bcf86cd799439016"
}
```

Le paramètre optionnel `variant_id` force l'évaluation d'une variante spécifique (utile pour les tests).

**Prérequis :**
- L'application doit posséder au moins un utilisateur admin actif (e-mail vérifié).
  Dans le cas contraire, HTTP 403 est retourné.

**Réponse :**
```json
{
  "data": {
    "_id": "507f1f77bcf86cd799439018",
    "table": {
      "_id": "507f1f77bcf86cd799439017",
      "title": "Scoring Crédit",
      "matching_type": "first",
      "variant": { "_id": "...", "title": "Standard" }
    },
    "title": "Bon crédit",
    "description": "Score supérieur ou égal à 700.",
    "final_decision": "approuve",
    "request": { "credit_score": 750, "revenu_annuel": 60000 },
    "created_at": "2024-01-15T10:30:00+0000",
    "updated_at": "2024-01-15T10:30:00+0000"
  }
}
```

Le tableau `rules` n'est inclus que lorsque le paramètre `show_meta` de l'application est activé.

---

#### `GET /api/v1/decisions/{id}` — Consulter une décision (vue consommateur)

Retourne la représentation allégée d'une décision, sans les champs internes admin.

---

### Journal des modifications

Chaque sauvegarde de table crée automatiquement un instantané dans le journal.
Toutes les requêtes sont isolées à l'application courante.

#### `GET /api/v1/admin/{collection}/changelog`

Liste toutes les entrées du journal pour une collection (ex. : `tables`).

#### `GET /api/v1/admin/{collection}/{model_id}/changelog`

Historique du journal pour une ressource spécifique.

#### `GET /api/v1/admin/{collection}/{model_id}/diff?compare_with={changelog_id}`

Différence structurée entre deux instantanés. Retourne les objets `added` (ajouté),
`removed` (supprimé) et `changed` (modifié).

#### `PUT /api/v1/admin/{collection}/{model_id}/{changelog_id}/rollback`

Restaure la ressource à l'état d'un instantané précédent. Une nouvelle entrée de journal
est créée pour tracer l'opération de restauration.

---

## Fonctionnement du moteur de décision

### Types de correspondance

| Type | Comportement |
|------|--------------|
| `first` | S'arrête à la première règle dont toutes les conditions sont vérifiées. La valeur `than` de cette règle devient la décision finale. |
| `scoring_sum` | Évalue toutes les règles. Additionne les valeurs `than` des règles déclenchées. |
| `scoring_max` | Évalue toutes les règles. Retourne la valeur `than` la plus haute parmi les règles déclenchées. |
| `scoring_min` | Évalue toutes les règles. Retourne la valeur `than` la plus basse parmi les règles déclenchées. |
| `scoring_count` | Évalue toutes les règles. Retourne le nombre de règles déclenchées. |

**Valeur de repli :** si aucune règle ne correspond (ou si le score est nul), le
`default_decision` de la variante est utilisé.

Pour le type `first`, le `title` et la `description` de la décision proviennent de la règle
déclenchée. Pour les types scoring et la valeur de repli, ils proviennent du `default_title`
et `default_description` de la variante.

---

### Opérateurs de condition

| Opérateur | Description | Format de `value` |
|-----------|-------------|-------------------|
| `$is_set` | Toujours `true` (champ présent) | — (ignoré) |
| `$is_null` | La valeur du champ est `null` | — (ignoré) |
| `$any` | Toujours `true`, y compris si la valeur est `null` | — (ignoré) |
| `$eq` | Égal à | n'importe quelle valeur scalaire |
| `$ne` | Différent de | n'importe quelle valeur scalaire |
| `$gt` | Strictement supérieur à | numérique |
| `$gte` | Supérieur ou égal à | numérique |
| `$lt` | Strictement inférieur à | numérique |
| `$lte` | Inférieur ou égal à | numérique |
| `$between` | `min ≤ x ≤ max` (bornes incluses) | `"300;700"` |
| `$between_excl` | `min < x < max` (bornes exclues) | `"300;700"` |
| `$between_lexcl` | `min < x ≤ max` (borne gauche exclue) | `"300;700"` |
| `$between_rexcl` | `min ≤ x < max` (borne droite exclue) | `"300;700"` |
| `$in` | Valeur présente dans la liste | `"visa,mastercard"` |
| `$nin` | Valeur absente de la liste | `"visa,mastercard"` |

> **Valeurs nulles :** Seuls `$is_null` et `$any` correspondent à une valeur de champ `null`.
> Tous les autres opérateurs retournent `false` sans lever d'erreur.

**`$in` / `$nin` avec des valeurs contenant des virgules :**

Utilisez des guillemets simples pour délimiter les tokens contenant des virgules ou des espaces :
```
"'visa, mastercard', amex"   →   ["visa, mastercard", "amex"]
```

---

### Présets

Un préset transforme la valeur d'un champ *avant* l'évaluation des règles. Cela permet de
normaliser ou convertir des valeurs.

Exemple : convertir toute valeur non nulle en booléen `true`/`false` :

```json
{
  "key": "has_income",
  "type": "boolean",
  "preset": { "condition": "$is_set", "value": null }
}
```

Si le consommateur soumet `"has_income": 60000`, le préset le convertit en `true` avant
que les conditions des règles l'évaluent.

Les résultats de préset sont mis en cache par champ pour chaque évaluation : la transformation
est calculée au maximum une fois même si plusieurs conditions référencent le même champ.

---

### Sélection de variante

| Stratégie | Comportement |
|-----------|--------------|
| `first` | Utilise toujours la première variante. Déterministe, idéal pour les tests. |
| `random` | Sélection aléatoire uniforme parmi toutes les variantes. |
| `percent` | Sélection aléatoire pondérée. Chaque variante possède un champ `probability` (1–100). La somme de toutes les probabilités doit être égale à 100. |

Vous pouvez forcer une variante spécifique par requête en incluant `variant_id` dans le
corps de la requête de décision.

---

## Types de champs

| Type | Valeurs acceptées |
|------|-------------------|
| `numeric` | `700`, `3.14`, `-1` |
| `boolean` | `true`, `false` |
| `string` | N'importe quel texte |

Les clés de champs sont normalisées automatiquement : converties en minuscules, espaces remplacés
par des underscores. La clé `variant_id` est réservée et ne peut pas être utilisée.

---

## Types de décision

Le `decision_type` d'une table valide les valeurs `than` et `default_decision` :

| Type | Description |
|------|-------------|
| `alpha_num` | Chaîne alphanumérique |
| `numeric` | Nombre (obligatoire pour les tables de scoring) |
| `string` | N'importe quelle chaîne de caractères |
| `json` | Chaîne encodée en JSON |

---

## Guide de démarrage rapide

### 1. Obtenir un token

```bash
curl -X POST https://api.example.com/oauth/token \
  -H "Authorization: Basic $(echo -n 'client_id:client_secret' | base64)" \
  -H "Content-Type: application/json" \
  -d '{"grant_type":"password","username":"admin","password":"admin"}'
```

### 2. Créer une table de décision

```bash
curl -X POST https://api.example.com/api/v1/admin/tables \
  -H "Authorization: Bearer <token>" \
  -H "X-Application: <app_id>" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Score de Fraude",
    "matching_type": "scoring_sum",
    "decision_type": "numeric",
    "fields": [
      {"key":"montant",  "title":"Montant",        "type":"numeric", "source":"request","preset":{}},
      {"key":"pays",     "title":"Code pays",       "type":"string",  "source":"request","preset":{}},
      {"key":"vpn",      "title":"VPN détecté",     "type":"boolean", "source":"request","preset":{}}
    ],
    "variants": [{
      "title": "v1",
      "default_decision": "0",
      "rules": [
        {"than":"10","title":"Montant élevé",       "conditions":[{"field_key":"montant","condition":"$gt",  "value":1000}]},
        {"than":"20","title":"Pays à risque",        "conditions":[{"field_key":"pays",   "condition":"$in",  "value":"XX,YY"}]},
        {"than":"30","title":"VPN détecté",          "conditions":[{"field_key":"vpn",    "condition":"$eq",  "value":true}]}
      ]
    }]
  }'
```

### 3. Évaluer une décision

```bash
curl -X POST https://api.example.com/api/v1/tables/<table_id>/decisions \
  -H "Authorization: Basic $(echo -n 'consumer_id:consumer_secret' | base64)" \
  -H "X-Application: <app_id>" \
  -H "Content-Type: application/json" \
  -d '{"montant":1500,"pays":"XX","vpn":false}'
```

Résultat : `final_decision = "30"` (10 pour montant élevé + 20 pour pays à risque).

### 4. Ajouter des métadonnées à une décision

```bash
curl -X PUT https://api.example.com/api/v1/admin/decisions/<decision_id>/meta \
  -H "Authorization: Bearer <token>" \
  -H "X-Application: <app_id>" \
  -H "Content-Type: application/json" \
  -d '{"meta":{"transaction_id":"TXN-99999"}}'
```

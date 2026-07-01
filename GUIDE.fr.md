# Gandalf API — Guide d'utilisation

Gandalf API est un moteur de règles métier accessible via une API REST. Il permet de créer des tables de décision, de définir des règles et conditions, puis d'évaluer des données en temps réel pour obtenir une décision automatique. Cas d'usage typiques : scoring de crédit, détection de fraude, qualification de leads, personnalisation de contenu.

---

## Table des matières

1. [Installation](#1-installation)
   - 1.1 [Avec Docker (recommandé)](#11-avec-docker-recommandé)
   - 1.2 [Depuis les sources (développeurs)](#12-depuis-les-sources-développeurs)
2. [Principes et fonctionnalités](#2-principes-et-fonctionnalités)
   - 2.1 [Comptes utilisateurs et rôles](#21-comptes-utilisateurs-et-rôles)
   - 2.2 [Tables, règles et conditions](#22-tables-règles-et-conditions)
   - 2.3 [Variantes et A/B testing](#23-variantes-et-ab-testing)
3. [Scénario d'utilisation](#3-scénario-dutilisation)
   - 3.1 [Authentification](#31-authentification)
   - 3.2 [Création d'une table](#32-création-dune-table)
   - 3.3 [Modification de la table](#33-modification-de-la-table)
   - 3.4 [Exécution de la table](#34-exécution-de-la-table)
   - 3.5 [Exécution d'une variante spécifique](#35-exécution-dune-variante-spécifique)
   - 3.6 [Gestion des utilisateurs et comptes](#36-gestion-des-utilisateurs-et-comptes)

---

## 1. Installation

### 1.1 Avec Docker (recommandé)

La méthode la plus simple pour démarrer Gandalf API en production ou en environnement de test est d'utiliser Docker Compose. L'environnement comprend trois services : **Nginx** (reverse proxy), **PHP-FPM 8.2** (application) et **MongoDB** (base de données).

**Prérequis**
- Docker >= 20.x
- Docker Compose >= 2.x

**Étapes**

```bash
# 1. Cloner le dépôt
git clone https://github.com/your-org/gandalf.api.git
cd gandalf.api

# 2. Copier et configurer les variables d'environnement
cp .env.example .env
```

Ouvrir `.env` et ajuster au minimum les paramètres suivants :

```dotenv
APP_ENV=local
APP_KEY=           # Clé aléatoire (32 caractères)

# Base de données MongoDB (défauts compatibles avec docker-compose.yml)
DB_HOST=db
DB_PORT=27017
DB_DATABASE=gandalf

# Identifiants OAuth (à personnaliser)
TOKEN_ADMIN_PW=admin
TOKEN_CONSUMER_PW=consumer

# Pour le développement local, activer les utilisateurs sans vérification email
ACTIVATE_ALL_USERS=true
EMAIL_ENABLED=false
```

```bash
# 3. Démarrer les conteneurs
docker compose up -d

# 4. Vérifier que l'API répond
curl http://localhost:8080/
# Réponse attendue : ok
```

L'API est disponible sur **http://localhost:8080**.

**Ports exposés par défaut**

| Service | Port hôte | Port conteneur |
|---------|-----------|----------------|
| Nginx   | 8080      | 80             |
| MongoDB | 27017     | 27017          |

---

### 1.2 Depuis les sources (développeurs)

Pour développer ou contribuer au projet, il est possible de faire tourner l'application directement sur la machine hôte sans Docker.

**Prérequis**
- PHP >= 8.2 avec les extensions : `mongodb`, `bcmath`, `intl`, `mbstring`, `zip`
- Composer >= 2.x
- MongoDB >= 6.x (instance locale ou distante)
- Nginx ou le serveur de développement PHP

**Étapes**

```bash
# 1. Cloner le dépôt
git clone https://github.com/your-org/gandalf.api.git
cd gandalf.api

# 2. Installer les dépendances PHP
composer install

# 3. Configurer l'environnement
cp .env.example .env
# Éditer .env avec les paramètres de votre MongoDB local
# DB_HOST=127.0.0.1, DB_PORT=27017, DB_DATABASE=gandalf

# 4. Démarrer le serveur de développement intégré à PHP
php -S 0.0.0.0:8080 -t public/
```

**Configuration Nginx (optionnel)**

Si vous préférez Nginx, utilisez la configuration fournie dans `config/nginx/docker.conf` comme base et ajustez `root` vers le répertoire `public/` du projet.

**Variables d'environnement utiles en développement**

```dotenv
APP_ENV=local
APP_DEBUG=true
ACTIVATE_ALL_USERS=true   # Pas besoin de vérifier les emails
EMAIL_ENABLED=false        # Désactive les envois de mails
BUGSNAG_ENABLED=false
INTERCOM_ENABLED=false
MIXPANEL_ENABLED=false
```

**Lancer les tests**

```bash
# Installer Codeception si nécessaire
composer require --dev codeception/codeception

# Exécuter la suite de tests API
./vendor/bin/codecept run api
```

---

## 2. Principes et fonctionnalités

### 2.1 Comptes utilisateurs et rôles

#### Inscription et activation

Un utilisateur s'inscrit via `POST /api/v1/users`. À la création, son compte est **inactif** : l'email fourni est stocké dans un champ temporaire en attendant vérification. Un token de confirmation est envoyé par email (ou renvoyé dans la réponse si `ACTIVATE_ALL_USERS=true` ou `EMAIL_ENABLED=false`).

La vérification de l'email (`POST /api/v1/users/verify/email`) active le compte et déplace l'adresse dans le champ permanent.

#### Authentification OAuth 2.0

Gandalf API s'appuie sur OAuth 2.0 avec deux types de flux :

| Flux                   | Usage                               | Endpoint                                                 |
|------------------------|-------------------------------------|----------------------------------------------------------|
| **Password Grant**     | Connexion d'un utilisateur humain   | `POST /oauth/token` avec `grant_type=password`           |
| **Client Credentials** | Appels machine-à-machine (consumer) | `POST /oauth/token` avec `grant_type=client_credentials` |
| **Refresh Token**      | Renouveler un token expiré          | `POST /oauth/token` avec `grant_type=refresh_token`      |

Les tokens obtenus sont passés dans l'en-tête `Authorization: Bearer <token>` pour les appels authentifiés.

#### Multi-tenant : Applications

Gandalf est **multi-tenant**. Chaque ensemble de tables appartient à une **Application** (projet). L'identifiant de l'application (MongoDB ObjectID) doit être fourni dans l'en-tête `X-Application` sur la majorité des endpoints.

Un utilisateur peut appartenir à plusieurs applications avec des rôles différents.

#### Rôles et permissions

| Rôle         | Droits                                                               |
|--------------|----------------------------------------------------------------------|
| **Admin**    | Gestion complète : tables, variantes, règles, utilisateurs du projet |
| **Manager**  | Gestion des tables et règles, lecture des décisions                  |
| **Consumer** | Exécution des tables (appel au moteur de décision) uniquement        |

Les rôles sont attribués lors de l'invitation d'un utilisateur à une application.

---

### 2.2 Tables, règles et conditions

#### La Table

Une **Table** est le conteneur principal du moteur de décision. Elle définit :

- **Les champs** (`fields`) : les variables d'entrée attendues lors de l'évaluation (ex. `credit_score`, `age`, `country`). Chaque champ a un type (`numeric`, `string`, `boolean`) et peut avoir un **preset** (pré-condition appliquée à la valeur avant évaluation).
- **Le type de correspondance** (`matching_type`) : détermine comment le résultat final est calculé à partir des règles qui correspondent.
- **Le type de décision** (`decision_type`) : type de la valeur retournée (`string`, `numeric`, `alpha_num`, `json`).

**Types de correspondance disponibles**

| Valeur          | Comportement                                                                                                   |
|-----------------|----------------------------------------------------------------------------------------------------------------|
| `first`         | S'arrête à la **première règle** qui correspond et retourne sa valeur. Logique de table de décision classique. |
| `scoring_sum`   | **Additionne** les valeurs de toutes les règles qui correspondent. Idéal pour le scoring.                      |
| `scoring_max`   | Retourne la valeur **maximale** parmi les règles correspondantes.                                              |
| `scoring_min`   | Retourne la valeur **minimale** parmi les règles correspondantes.                                              |
| `scoring_count` | Retourne le **nombre** de règles qui ont correspondu.                                                          |

#### Les Variantes

Chaque table contient au moins une **Variante**. Les règles sont définies au niveau de la variante. Une table peut avoir plusieurs variantes pour faire de l'A/B testing (voir section 2.3).

#### Les Règles

Une **Règle** (`rule`) appartient à une variante et contient :
- `than` : la valeur retournée si la règle correspond (ex. `"Approuvé"`, `10`, `"high_risk"`).
- `conditions` : la liste des conditions **toutes devant être vraies** (logique ET) pour que la règle corresponde.
- `title`, `description` (optionnels) : documentation de la règle.

#### Les Conditions

Une **Condition** évalue un champ d'entrée avec un opérateur :

| Opérateur        | Description                                                  | Exemple                        |
|------------------|--------------------------------------------------------------|--------------------------------|
| `$eq`            | Égal à                                                       | `credit_score $eq 750`         |
| `$ne`            | Différent de                                                 | `status $ne "blocked"`         |
| `$gt`            | Strictement supérieur                                        | `amount $gt 1000`              |
| `$gte`           | Supérieur ou égal                                            | `age $gte 18`                  |
| `$lt`            | Strictement inférieur                                        | `risk_score $lt 30`            |
| `$lte`           | Inférieur ou égal                                            | `amount $lte 5000`             |
| `$in`            | Appartient à une liste                                       | `country $in "FR,DE,ES"`       |
| `$nin`           | N'appartient pas à une liste                                 | `country $nin "XX,YY"`         |
| `$between`       | Entre deux valeurs (inclus des deux côtés : min ≤ x ≤ max)   | `age $between "18;65"`         |
| `$between_excl`  | Entre deux valeurs (exclusif des deux côtés : min < x < max) | `score $between_excl "0;100"`  |
| `$between_lexcl` | Exclusif à gauche, inclus à droite (min < x ≤ max)           | `score $between_lexcl "0;100"` |
| `$between_rexcl` | Inclus à gauche, exclusif à droite (min ≤ x < max)           | `score $between_rexcl "0;100"` |
| `$is_set`        | La valeur est présente (non nulle)                           | —                              |
| `$is_null`       | La valeur est absente ou nulle                               | —                              |
| `$any`           | Toujours vrai (passe-partout)                                | —                              |

#### Flux d'évaluation

Lors d'un appel au moteur de décision (`POST /api/v1/tables/{id}/decisions`) :

1. Les champs d'entrée sont validés contre la définition de la table.
2. Une variante est sélectionnée (voir section 2.3).
3. Les règles de la variante sont évaluées dans l'ordre.
4. Pour chaque règle, toutes ses conditions sont testées :
   - Si elles sont toutes vraies → la règle **correspond**.
   - Sinon → on passe à la règle suivante.
5. Le résultat est calculé selon le `matching_type`.
6. Si aucune règle ne correspond, la valeur `default_decision` de la variante est retournée.
7. Une **Décision** immuable est enregistrée avec un snapshot complet (données d'entrée, règles évaluées, résultat).

---

### 2.3 Variantes et A/B testing

Les **Variantes** permettent de tester différentes versions de règles sur un même flux de données. Chaque table peut avoir plusieurs variantes, chacune avec son propre jeu de règles.

#### Sélection de la variante

Le comportement de sélection est contrôlé par `variants_probability` au niveau de la table :

| Valeur | Comportement |
|--------|--------------|
| `first` | Toujours utiliser la première variante (ou celle marquée `is_default = true`). |
| `random` | Sélection aléatoire uniforme entre toutes les variantes. |
| `percent` | Sélection pondérée. Chaque variante a un champ `probability` (1–100) ; la somme doit faire 100. |

Il est aussi possible de **forcer une variante** en passant son `_id` dans le corps de la requête d'évaluation (champ `variant_id`).

#### Marqueur de variante par défaut

Chaque table doit avoir exactement une variante avec `is_default: true`. Cette variante est utilisée comme référence lors de la sélection `first`.

---

## 3. Scénario d'utilisation

Ce scénario illustre la création et l'utilisation d'une table de **scoring de risque crédit** qui évalue les demandes de prêt et retourne une décision : `Approuvé`, `Révision manuelle` ou `Refusé`.

> **Convention** : remplacer `{APP_ID}` par l'ObjectID MongoDB de votre application, et les tokens par ceux obtenus lors de l'authentification.

---

### 3.1 Authentification

Avant tout appel admin, obtenez un token OAuth avec le flux Password Grant.

**Requête**

```http
POST /oauth/token
Authorization: Basic <base64(client_id:client_secret)>
Content-Type: application/json

{
  "grant_type": "password",
  "username": "admin@example.com",
  "password": "monMotDePasse"
}
```

**Réponse**

```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "refresh_token": "def502..."
}
```

Utiliser `access_token` dans tous les appels suivants.

---

### 3.2 Création d'une table

**Requête**

```http
POST /api/v1/admin/tables
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...
X-Application: {APP_ID}
Content-Type: application/json

{
  "title": "Scoring Crédit",
  "description": "Évalue les demandes de prêt et retourne une décision.",
  "matching_type": "first",
  "decision_type": "string",
  "variants_probability": "first",
  "fields": [
    {
      "key": "credit_score",
      "title": "Score de crédit",
      "type": "numeric",
      "source": "request"
    },
    {
      "key": "revenu_annuel",
      "title": "Revenu annuel (€)",
      "type": "numeric",
      "source": "request"
    },
    {
      "key": "pays",
      "title": "Pays de résidence",
      "type": "string",
      "source": "request"
    }
  ],
  "variants": [
    {
      "title": "Règles standard",
      "description": "Variante de référence",
      "is_default": true,
      "default_decision": "Refusé",
      "default_title": "Aucune règle applicable",
      "default_description": "La demande ne remplit aucun critère d'approbation.",
      "rules": [
        {
          "title": "Excellent profil",
          "description": "Score >= 750 et revenu >= 40 000 €",
          "than": "Approuvé",
          "conditions": [
            { "field_key": "credit_score", "condition": "$gte", "value": 750 },
            { "field_key": "revenu_annuel", "condition": "$gte", "value": 40000 }
          ]
        },
        {
          "title": "Profil moyen",
          "description": "Score entre 600 et 749",
          "than": "Révision manuelle",
          "conditions": [
            { "field_key": "credit_score", "condition": "$gte", "value": 600 },
            { "field_key": "credit_score", "condition": "$lt",  "value": 750 }
          ]
        }
      ]
    }
  ]
}
```

**Réponse** (`201 Created`)

```json
{
  "meta": { "code": 201 },
  "data": {
    "_id": "65f1a2b3c4d5e6f7a8b9c0d1",
    "title": "Scoring Crédit",
    "matching_type": "first",
    "decision_type": "string",
    "variants_probability": "first",
    "fields": [
      { "_id": "65f1a2b3c4d5e6f7a8b9c0d2", "key": "credit_score", "title": "Score de crédit", "type": "numeric" },
      { "_id": "65f1a2b3c4d5e6f7a8b9c0d3", "key": "revenu_annuel", "title": "Revenu annuel (€)", "type": "numeric" },
      { "_id": "65f1a2b3c4d5e6f7a8b9c0d4", "key": "pays", "title": "Pays de résidence", "type": "string" }
    ],
    "variants": [
      {
        "_id": "65f1a2b3c4d5e6f7a8b9c0d5",
        "title": "Règles standard",
        "is_default": true,
        "default_decision": "Refusé",
        "probability": 0,
        "rules": [
          {
            "_id": "65f1a2b3c4d5e6f7a8b9c0d6",
            "title": "Excellent profil",
            "than": "Approuvé",
            "conditions": [
              { "_id": "65f1a2b3c4d5e6f7a8b9c0d7", "field_key": "credit_score", "condition": "$gte", "value": 750 },
              { "_id": "65f1a2b3c4d5e6f7a8b9c0d8", "field_key": "revenu_annuel", "condition": "$gte", "value": 40000 }
            ]
          },
          {
            "_id": "65f1a2b3c4d5e6f7a8b9c0d9",
            "title": "Profil moyen",
            "than": "Révision manuelle",
            "conditions": [
              { "_id": "65f1a2b3c4d5e6f7a8b9c0da", "field_key": "credit_score", "condition": "$gte", "value": 600 },
              { "_id": "65f1a2b3c4d5e6f7a8b9c0db", "field_key": "credit_score", "condition": "$lt",  "value": 750 }
            ]
          }
        ]
      }
    ]
  }
}
```

Conserver l'`_id` de la table (`65f1a2b3c4d5e6f7a8b9c0d1`) et celui de la variante (`65f1a2b3c4d5e6f7a8b9c0d5`) pour les étapes suivantes.

---

### 3.3 Modification de la table

La mise à jour d'une table se fait via `PUT /api/v1/admin/tables/{id}`. 

Le corps de la requête **remplace intégralement** les champs envoyés. Pour modifier les règles et conditions, il faut renvoyer la structure complète des variantes avec les modifications souhaitées.

> **Important** : conserver les `_id` existants des variantes, règles et conditions pour éviter leur recréation avec de nouveaux identifiants. 

> Omettre un `_id` signifie créer un nouvel élément.

#### 3.3.1 Ajout d'une condition à une règle existante

Ici on ajoute la condition "le pays doit être dans la zone UE" à la règle "Excellent profil".

**Requête**

```http
PUT /api/v1/admin/tables/65f1a2b3c4d5e6f7a8b9c0d1
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...
X-Application: {APP_ID}
Content-Type: application/json

{
  "title": "Scoring Crédit",
  "description": "Évalue les demandes de prêt et retourne une décision.",
  "matching_type": "first",
  "decision_type": "string",
  "variants_probability": "first",
  "fields": [
    { "_id": "65f1a2b3c4d5e6f7a8b9c0d2", "key": "credit_score", "title": "Score de crédit", "type": "numeric", "source": "request" },
    { "_id": "65f1a2b3c4d5e6f7a8b9c0d3", "key": "revenu_annuel", "title": "Revenu annuel (€)", "type": "numeric", "source": "request" },
    { "_id": "65f1a2b3c4d5e6f7a8b9c0d4", "key": "pays", "title": "Pays de résidence", "type": "string", "source": "request" }
  ],
  "variants": [
    {
      "_id": "65f1a2b3c4d5e6f7a8b9c0d5",
      "title": "Règles standard",
      "is_default": true,
      "default_decision": "Refusé",
      "rules": [
        {
          "_id": "65f1a2b3c4d5e6f7a8b9c0d6",
          "title": "Excellent profil",
          "than": "Approuvé",
          "conditions": [
            { "_id": "65f1a2b3c4d5e6f7a8b9c0d7", "field_key": "credit_score",  "condition": "$gte", "value": 750 },
            { "_id": "65f1a2b3c4d5e6f7a8b9c0d8", "field_key": "revenu_annuel", "condition": "$gte", "value": 40000 },
            { "field_key": "pays", "condition": "$in", "value": "FR,DE,ES,IT,BE,NL,PT,AT,IE" }
          ]
        },
        {
          "_id": "65f1a2b3c4d5e6f7a8b9c0d9",
          "title": "Profil moyen",
          "than": "Révision manuelle",
          "conditions": [
            { "_id": "65f1a2b3c4d5e6f7a8b9c0da", "field_key": "credit_score", "condition": "$gte", "value": 600 },
            { "_id": "65f1a2b3c4d5e6f7a8b9c0db", "field_key": "credit_score", "condition": "$lt",  "value": 750 }
          ]
        }
      ]
    }
  ]
}
```

**Réponse** (`200 OK`) : la table mise à jour avec la nouvelle condition ayant reçu un `_id`.

#### 3.3.2 Suppression d'une condition

Pour supprimer une condition, il suffit de **ne pas l'inclure** dans le tableau `conditions` lors du `PUT`. Par exemple, pour retirer la condition sur le pays de la règle "Excellent profil", on renvoie la règle sans cette condition.

#### 3.3.3 Ajout d'une nouvelle règle

Pour ajouter une règle, inclure un nouvel objet **sans `_id`** dans le tableau `rules` de la variante concernée.

**Extrait de la requête (ajout d'une règle "Très faible revenu")**

```json
{
  "rules": [
    { "_id": "65f1a2b3c4d5e6f7a8b9c0d6", "title": "Excellent profil", "than": "Approuvé", "conditions": [...] },
    { "_id": "65f1a2b3c4d5e6f7a8b9c0d9", "title": "Profil moyen",     "than": "Révision manuelle", "conditions": [...] },
    {
      "title": "Revenu insuffisant",
      "description": "Revenus trop faibles quelle que soit la note",
      "than": "Refusé",
      "conditions": [
        { "field_key": "revenu_annuel", "condition": "$lt", "value": 15000 }
      ]
    }
  ]
}
```

#### 3.3.4 Suppression d'une règle

Pour supprimer une règle, **ne pas l'inclure** dans le tableau `rules` lors du `PUT`. Toutes les règles non présentes dans la requête sont supprimées.

---

### 3.4 Exécution de la table

L'évaluation d'une table se fait avec des **identifiants consumer** (client credentials) ou un token utilisateur avec le rôle approprié.

**Requête**

```http
POST /api/v1/tables/65f1a2b3c4d5e6f7a8b9c0d1/decisions
Authorization: Basic <base64(consumer_id:consumer_secret)>
X-Application: {APP_ID}
Content-Type: application/json

{
  "credit_score": 780,
  "revenu_annuel": 52000,
  "pays": "FR"
}
```

**Réponse** (`200 OK`)

```json
{
  "meta": { "code": 200 },
  "data": {
    "_id": "65f1a2b3c4d5e6f7a8b9c0e1",
    "table": {
      "_id": "65f1a2b3c4d5e6f7a8b9c0d1",
      "title": "Scoring Crédit",
      "matching_type": "first",
      "variant": {
        "_id": "65f1a2b3c4d5e6f7a8b9c0d5",
        "title": "Règles standard"
      }
    },
    "title": "Excellent profil",
    "description": "Score >= 750 et revenu >= 40 000 €",
    "final_decision": "Approuvé",
    "default_decision": "Refusé",
    "request": {
      "credit_score": 780,
      "revenu_annuel": 52000,
      "pays": "FR"
    },
    "rules": [
      {
        "_id": "65f1a2b3c4d5e6f7a8b9c0d6",
        "title": "Excellent profil",
        "decision": "Approuvé",
        "conditions": [
          { "field_key": "credit_score",  "condition": "$gte", "value": 750,   "matched": true },
          { "field_key": "revenu_annuel", "condition": "$gte", "value": 40000, "matched": true },
          { "field_key": "pays",          "condition": "$in",  "value": "FR,DE,ES,IT,BE,NL,PT,AT,IE", "matched": true }
        ]
      }
    ],
    "created_at": "2026-03-21T14:22:10.000Z"
  }
}
```

La réponse inclut :
- `final_decision` : la décision retournée.
- `rules` : le détail des règles évaluées avec, pour chaque condition, le champ `matched` indiquant si elle a été satisfaite.
- La décision est **persistée** et consultable ultérieurement via `GET /api/v1/admin/decisions/{id}`.

**Exemple avec un profil qui déclenche la décision par défaut** (aucune règle ne correspond)

```http
POST /api/v1/tables/65f1a2b3c4d5e6f7a8b9c0d1/decisions
...

{
  "credit_score": 400,
  "revenu_annuel": 12000,
  "pays": "FR"
}
```

```json
{
  "data": {
    "final_decision": "Refusé",
    "title": "Aucune règle applicable",
    "description": "La demande ne remplit aucun critère d'approbation."
  }
}
```

---

### 3.5 Exécution d'une variante spécifique

Lorsque plusieurs variantes existent sur une table (A/B testing), il est possible de **forcer l'utilisation d'une variante particulière** en passant son identifiant dans le corps de la requête.

**Exemple : ajout d'une seconde variante à la table**

```http
PUT /api/v1/admin/tables/65f1a2b3c4d5e6f7a8b9c0d1
Authorization: Bearer ...
X-Application: {APP_ID}
Content-Type: application/json

{
  "title": "Scoring Crédit",
  "matching_type": "first",
  "decision_type": "string",
  "variants_probability": "percent",
  "fields": [...],
  "variants": [
    {
      "_id": "65f1a2b3c4d5e6f7a8b9c0d5",
      "title": "Règles standard",
      "is_default": true,
      "probability": 80,
      "default_decision": "Refusé",
      "rules": [...]
    },
    {
      "title": "Règles assouplie (test)",
      "description": "Seuil de score abaissé à 550",
      "is_default": false,
      "probability": 20,
      "default_decision": "Refusé",
      "rules": [
        {
          "title": "Profil acceptable (assoupli)",
          "than": "Approuvé",
          "conditions": [
            { "field_key": "credit_score", "condition": "$gte", "value": 550 },
            { "field_key": "revenu_annuel", "condition": "$gte", "value": 30000 }
          ]
        }
      ]
    }
  ]
}
```

Avec `variants_probability: "percent"`, 80% des requêtes utiliseront la variante standard et 20% la variante assouplie.

**Forçage d'une variante lors de l'exécution**

```http
POST /api/v1/tables/65f1a2b3c4d5e6f7a8b9c0d1/decisions
Authorization: Basic ...
X-Application: {APP_ID}
Content-Type: application/json

{
  "credit_score": 560,
  "revenu_annuel": 35000,
  "pays": "FR",
  "variant_id": "65f1a2b3c4d5e6f7a8b9c0f1"
}
```

Le champ `variant_id` force l'utilisation de la variante désignée, indépendamment de la stratégie de sélection configurée.

---

### 3.6 Gestion des utilisateurs et comptes

#### Inscription d'un nouvel utilisateur

```http
POST /api/v1/users
Authorization: Basic <base64(client_id:client_secret)>
Content-Type: application/json

{
  "username": "marie.dupont",
  "email": "marie.dupont@example.com",
  "password": "MotDePasse123!",
  "first_name": "Marie",
  "last_name": "Dupont"
}
```

**Réponse** (`201 Created`) — en mode développement (`ACTIVATE_ALL_USERS=true`) :

```json
{
  "meta": { "code": 201 },
  "data": {
    "_id": "65f1a2b3c4d5e6f7a8b9c0f2",
    "username": "marie.dupont",
    "email": "marie.dupont@example.com",
    "first_name": "Marie",
    "last_name": "Dupont",
    "active": true
  }
}
```

En production (`EMAIL_ENABLED=true`), `active` sera `false` jusqu'à la vérification de l'email.

#### Vérification de l'email

```http
POST /api/v1/users/verify/email
Authorization: Basic <base64(client_id:client_secret)>
Content-Type: application/json

{
  "token": "abc123def456..."
}
```

**Réponse** (`200 OK`) : l'utilisateur est activé.

#### Invitation d'un utilisateur à une application

```http
POST /api/v1/invite
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...
X-Application: {APP_ID}
Content-Type: application/json

{
  "email": "collaborateur@example.com",
  "role": "manager",
  "scope": ["tables_view", "tables_create", "tables_update"]
}
```

**Réponse** (`200 OK`) :

```json
{
  "meta": { "code": 200 },
  "data": {
    "email": "collaborateur@example.com",
    "role": "manager",
    "scope": ["tables_view", "tables_create", "tables_update"],
    "project": {
      "id": "{APP_ID}",
      "title": "Mon Projet"
    }
  }
}
```

Si l'utilisateur n'existe pas encore, une invitation par email lui est envoyée. À son inscription, il sera automatiquement lié à l'application avec les droits définis.

#### Réinitialisation du mot de passe

```http
# Étape 1 : demander un token de réinitialisation
POST /api/v1/users/password/reset
Authorization: Basic <base64(client_id:client_secret)>
Content-Type: application/json

{ "email": "marie.dupont@example.com" }
```

```http
# Étape 2 : appliquer le nouveau mot de passe
PUT /api/v1/users/password/reset
Authorization: Basic <base64(client_id:client_secret)>
Content-Type: application/json

{
  "token": "xyz789...",
  "password": "NouveauMotDePasse456!"
}
```

#### Affectation/retrait d'un utilisateur à une application

```http
# Affecter un utilisateur existant
POST /api/v1/projects/users
Authorization: Bearer ...
X-Application: {APP_ID}
Content-Type: application/json

{
  "user_id": "65f1a2b3c4d5e6f7a8b9c0f2",
  "role": "admin",
  "scope": ["tables_view", "tables_create", "tables_update", "tables_delete"]
}
```

```http
# Retirer un utilisateur
DELETE /api/v1/projects/users
Authorization: Bearer ...
X-Application: {APP_ID}
Content-Type: application/json

{ "user_id": "65f1a2b3c4d5e6f7a8b9c0f2" }
```

#### Consultation des décisions (audit)

```http
GET /api/v1/admin/decisions?table_id=65f1a2b3c4d5e6f7a8b9c0d1&page=1&per_page=20
Authorization: Bearer ...
X-Application: {APP_ID}
```

```json
{
  "meta": { "code": 200, "count": 42, "page": 1, "per_page": 20 },
  "data": [
    {
      "_id": "65f1a2b3c4d5e6f7a8b9c0e1",
      "final_decision": "Approuvé",
      "request": { "credit_score": 780, "revenu_annuel": 52000, "pays": "FR" },
      "created_at": "2026-03-21T14:22:10.000Z"
    }
  ]
}
```

---

## Annexe — Récapitulatif des endpoints principaux

La documentation de l'API existe au format [openAPI](./openapi.yaml).

| Méthode  | Endpoint                            | Description                               |
|----------|-------------------------------------|-------------------------------------------|
| `POST`   | `/oauth/token`                      | Obtenir un token OAuth                    |
| `POST`   | `/oauth/revoke`                     | Révoquer un token                         |
| `POST`   | `/api/v1/users`                     | Créer un utilisateur                      |
| `POST`   | `/api/v1/users/verify/email`        | Vérifier l'email                          |
| `POST`   | `/api/v1/users/password/reset`      | Demander une réinitialisation             |
| `PUT`    | `/api/v1/users/password/reset`      | Appliquer le nouveau mot de passe         |
| `GET`    | `/api/v1/users/current`             | Profil de l'utilisateur connecté          |
| `PUT`    | `/api/v1/users/current`             | Mettre à jour le profil                   |
| `POST`   | `/api/v1/invite`                    | Inviter un utilisateur à une application  |
| `POST`   | `/api/v1/projects/users`            | Affecter un utilisateur à une application |
| `DELETE` | `/api/v1/projects/users`            | Retirer un utilisateur d'une application  |
| `GET`    | `/api/v1/admin/tables`              | Lister les tables                         |
| `POST`   | `/api/v1/admin/tables`              | Créer une table                           |
| `GET`    | `/api/v1/admin/tables/{id}`         | Détail d'une table                        |
| `PUT`    | `/api/v1/admin/tables/{id}`         | Modifier une table                        |
| `DELETE` | `/api/v1/admin/tables/{id}`         | Supprimer une table                       |
| `POST`   | `/api/v1/tables/{id}/decisions`     | Évaluer une table (décision)              |
| `GET`    | `/api/v1/admin/decisions`           | Lister les décisions                      |
| `GET`    | `/api/v1/admin/decisions/{id}`      | Détail d'une décision                     |
| `PUT`    | `/api/v1/admin/decisions/{id}/meta` | Ajouter des métadonnées à une décision    |
| `GET`    | `/api/v1/admin/tables/{id}/export`  | Exporter une table                        |
| `POST`   | `/api/v1/admin/tables/import`       | Importer une table                        |

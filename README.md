# Tests de sécurité avec Symfony

> Une application pédagogique pour transformer le cours sur les tests de sécurité
> en scénarios concrets, observables et automatisés avec PHPUnit.

[![Symfony 8.1](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony)](https://symfony.com/)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![PHPUnit 13](https://img.shields.io/badge/PHPUnit-13-3c9cd7)](https://phpunit.de/)
[![Tests](https://img.shields.io/badge/tests-securite-19a974)](#exécuter-les-tests)

## Présentation

Ce projet est dérivé du dépôt
[`StephaneBouret/tests-fonctionnels`](https://github.com/StephaneBouret/tests-fonctionnels).
Il conserve son socle simple - authentification, inscription, fixtures et
`WebTestCase` - puis l'enrichit pour mettre en pratique les exemples du cours
sur les tests de sécurité.

L'application ne cherche pas à simuler un pentest complet. Elle permet aux
étudiants de comprendre une règle essentielle :

> Une fonctionnalité peut fonctionner parfaitement tout en étant mal sécurisée.

Chaque mécanisme est donc accompagné de cas autorisés, de cas refusés et de
tests de régression.

## Ce que l'application permet de tester

| Partie du cours | Scénario | Routes principales | Tests |
| --- | --- | --- | --- |
| Accès utilisateurs | Anonyme, utilisateur et administrateur | `/account`, `/dashboard`, `/admin` | `AccessControlTest.php` |
| Validation des entrées | Entité, formulaire, champ forgé et API JSON | `/register`, `/api/register` | `InputValidationTest.php` |
| Protection CSRF | FormType automatique et suppression manuelle | `/account/edit`, `/account/delete` | `CsrfProtectionTest.php` |
| Injection SQL | Charge utile envoyée au formulaire de connexion | `/login` | `VulnerabilityTest.php` |
| XSS stocké | Commentaire malveillant échappé par Twig | `/comments` | `VulnerabilityTest.php` |
| IDOR | Accès à la commande d'un autre utilisateur | `/orders/{id}` | `VulnerabilityTest.php` |
| Résistance | Honeypot, question CAPTCHA, RateLimiter et login throttling | `/contact`, `/login` | `AttackResistanceTest.php` |
| Régression | Protections critiques conservées dans le temps | Routes sensibles | `SecurityRegressionTest.php` |

## Choix pédagogiques

### Une application sécurisée, pas un musée des horreurs

Les charges utiles telles que :

```text
' OR 1=1 --
<script>alert("hack")</script>
```

sont envoyées aux fonctionnalités réelles de l'application, mais le code livré
reste sécurisé :

- Doctrine paramètre les requêtes utilisées pour l'authentification ;
- Twig échappe les commentaires ;
- un Voter contrôle le propriétaire d'une commande ;
- Symfony valide les données côté serveur ;
- les actions sensibles vérifient un token CSRF ;
- le composant RateLimiter limite les comportements excessifs.

### Deux comportements CSRF à connaître

Le cours présente souvent `403 Access Denied` comme résultat attendu. Dans une
application Symfony actuelle, il faut distinguer deux cas :

- un `FormType` avec un token invalide rend le formulaire invalide ; ce projet
  renvoie alors `422 Unprocessable Entity` ;
- une action manuelle qui appelle `isCsrfTokenValid()` peut lever explicitement
  une erreur `403 Access Denied`.

Les tests montrent les deux comportements. C'est plus précis et beaucoup plus
utile en entreprise.

### Login throttling et code HTTP 429

Le `login_throttling` natif de Symfony bloque les tentatives répétées puis
repasse par le mécanisme d'échec d'authentification, généralement avec une
redirection vers `/login`.

Le formulaire `/contact`, lui, utilise directement une `RateLimiterFactory` et
renvoie explicitement :

```text
429 Too Many Requests
Retry-After: 60
```

Les étudiants observent ainsi les deux stratégies.

La question « 3 + 4 » du formulaire de contact sert uniquement à tester un
challenge valide ou invalide sans clé API. Ce n'est pas un CAPTCHA de production.
Une application réelle utilisera par exemple Cloudflare Turnstile ou un service
équivalent, toujours avec une validation côté serveur.

## Prérequis

- PHP 8.4.1 ou supérieur ;
- Composer 2 ;
- Symfony CLI, facultatif mais conseillé ;
- MySQL 8.4 ou une version compatible ;
- Git.

Vérification :

```bash
php -v
composer -V
symfony -V
mysql --version
git --version
```

## Installation

### 1. Récupérer le projet

Depuis un futur dépôt Git :

```bash
git clone <URL_DU_DEPOT> tests-securite
cd tests-securite
```

Depuis l'archive fournie, décompressez-la puis ouvrez le dossier
`tests-securite`.

### 2. Installer les dépendances

```bash
composer install
```

Le projet utilise notamment :

- `symfony/security-bundle` ;
- `symfony/validator` ;
- `symfony/rate-limiter` ;
- `symfony/browser-kit` ;
- `phpunit/phpunit`.

### 3. Configurer la base locale

Créez un fichier `.env.local` :

```dotenv
APP_SECRET="une-cle-locale-longue-et-aleatoire"
DATABASE_URL="mysql://root:@127.0.0.1:3306/symfony_tests_securite?serverVersion=8.4.7&charset=utf8mb4"
```

Adaptez l'utilisateur, le mot de passe et `serverVersion` à votre installation.
Avec MySQL 9.1, utilisez par exemple `serverVersion=9.1.0`.

Ne placez jamais un secret de production dans `.env`.

### 4. Créer et préparer la base de développement

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:fixtures:load --no-interaction
```

### 5. Démarrer l'application

Avec Symfony CLI :

```bash
symfony serve
```

Puis ouvrez l'URL indiquée, généralement :

```text
https://127.0.0.1:8000
```

### Alternative Docker pour MySQL

Si aucun serveur MySQL local n'est lancé :

```bash
docker compose up -d database
```

Le conteneur expose MySQL sur le port `3306`. Ne le démarrez pas si WampServer
utilise déjà ce port.

## Comptes de démonstration

Le mot de passe est identique pour les trois comptes :

```text
Password123!
```

| Profil | Email | Rôle | Données associées |
| --- | --- | --- | --- |
| Bob | `user@test.fr` | `ROLE_USER` | Commande `SEC-001` |
| Alice | `alice@test.fr` | `ROLE_USER` | Commande `SEC-002` |
| Georges | `admin@test.fr` | `ROLE_ADMIN` | Accès à toutes les commandes |

## Préparer la base de test

Doctrine ajoute automatiquement le suffixe `_test`. La base utilisée par
PHPUnit se nomme donc :

```text
symfony_tests_securite_test
```

Préparation :

```bash
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/console doctrine:fixtures:load --env=test --no-interaction
```

Pour repartir d'une base parfaitement propre :

```bash
php bin/console doctrine:database:drop --env=test --force
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/console doctrine:fixtures:load --env=test --no-interaction
```

## Exécuter les tests

Tous les tests :

```bash
php bin/phpunit
```

Uniquement les tests de sécurité :

```bash
php bin/phpunit tests/Security
```

Un chapitre précis :

```bash
php bin/phpunit tests/Security/AccessControlTest.php
php bin/phpunit tests/Security/InputValidationTest.php
php bin/phpunit tests/Security/CsrfProtectionTest.php
php bin/phpunit tests/Security/VulnerabilityTest.php
php bin/phpunit tests/Security/AttackResistanceTest.php
php bin/phpunit tests/Security/SecurityRegressionTest.php
```

Un test précis :

```bash
php bin/phpunit --filter testUserCannotAccessAnotherUsersOrder
```

Affichage lisible pour une démonstration en cours :

```bash
php bin/phpunit --testdox tests/Security
```

## Travaux pratiques proposés

### TP 1 - Contrôler les accès

1. Ouvrir `/account` sans être connecté.
2. Se connecter avec Bob et ouvrir `/admin`.
3. Se connecter avec Georges et ouvrir `/admin`.
4. Exécuter `AccessControlTest.php`.
5. Commenter temporairement une règle de `security.yaml` et observer le test de
   régression échouer.

### TP 2 - Falsifier une donnée

1. Envoyer un email invalide à `/register`.
2. Envoyer du JSON invalide à `/api/register`.
3. Ajouter un champ `roles` forgé à la requête d'inscription avec Postman.
4. Vérifier qu'aucun compte administrateur n'est créé.

Exemple JSON :

```json
{
  "email": "abc",
  "password": "123",
  "firstname": ""
}
```

### TP 3 - Tester le CSRF

1. Inspecter le champ `_token` de `/account/edit`.
2. Le remplacer par `hack`.
3. Envoyer `POST /account/delete` sans token.
4. Comparer les réponses `422` et `403`.

### TP 4 - Tester SQLi, XSS et IDOR

1. Essayer `' OR 1=1 --` comme email de connexion.
2. Publier `<script>alert("hack")</script>` dans `/comments`.
3. Vérifier que le script est affiché comme du texte.
4. Se connecter avec Bob et ouvrir sa commande `SEC-001`.
5. Remplacer l'identifiant par celui de `SEC-002`.
6. Constater la réponse `403`.

### TP 5 - Tester la résistance

1. Remplir le champ caché `contact[website]` depuis les outils développeur.
2. Répondre volontairement faux à la question de sécurité.
3. Soumettre six fois `/contact` en moins d'une minute.
4. Observer la réponse `429` et l'en-tête `Retry-After`.
5. Échouer plusieurs connexions et observer le `login_throttling`.

## Arborescence utile

```text
tests-securite/
├── .github/workflows/tests.yaml
├── config/packages/
│   ├── csrf.yaml
│   ├── framework.yaml
│   └── security.yaml
├── migrations/
├── src/
│   ├── Controller/
│   │   ├── AccountController.php
│   │   ├── ApiRegistrationController.php
│   │   ├── CommentController.php
│   │   ├── ContactController.php
│   │   └── PurchaseOrderController.php
│   ├── Entity/
│   │   ├── Comment.php
│   │   ├── PurchaseOrder.php
│   │   └── User.php
│   ├── Form/
│   ├── Model/
│   └── Security/Voter/PurchaseOrderVoter.php
└── tests/
    ├── Controller/
    ├── Security/
    │   ├── AccessControlTest.php
    │   ├── AttackResistanceTest.php
    │   ├── CsrfProtectionTest.php
    │   ├── InputValidationTest.php
    │   ├── SecurityRegressionTest.php
    │   └── VulnerabilityTest.php
    └── Support/CreatesUsers.php
```

## Intégration continue

Le workflow `.github/workflows/tests.yaml` :

1. démarre MySQL 8.4 ;
2. installe PHP 8.4 et Composer ;
3. crée la base de test ;
4. exécute les migrations et les fixtures ;
5. vérifie le conteneur Symfony ;
6. lance PHPUnit.

Il s'exécute à chaque `push` et chaque Pull Request. Une protection supprimée
par mégarde bloque ainsi le pipeline avant le déploiement.

## Points d'attention

- Exécutez les charges utiles uniquement sur votre environnement pédagogique.
- Ne désactivez pas CSRF pour « faire passer » un test.
- Ne remplacez jamais une autorisation par la simple présence d'un identifiant
  dans l'URL.
- N'utilisez pas `|raw` sur un contenu saisi par un utilisateur.
- Chaque vulnérabilité corrigée doit produire un test de régression.
- Si un test de sécurité échoue, on corrige la protection ; on ne supprime pas
  le test. Oui, même s'il a choisi le vendredi à 17 h 58 pour se manifester.

## Licence et usage

Projet conçu comme support de formation. Adaptez librement les exercices à
votre progression pédagogique et à vos groupes.

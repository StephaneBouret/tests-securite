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
| IDOR et énumération | Accès à la commande d'un autre utilisateur, puis masquage de son existence | `/orders/{id}` | `VulnerabilityTest.php`, `SecurityRegressionTest.php` |
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

### IDOR : du refus en 403 au masquage en 404

Le parcours présente deux étapes successives. **Le code actuel correspond à
la seconde étape : masquage en `404`.** Le `403` décrit le comportement initial
étudié avant cette évolution, pas le résultat attendu de la version actuelle.

| Demande avec la session de Bob | Étape initiale | Étape actuelle |
| --- | --- | --- |
| Sa propre commande | `200`, accès autorisé | `200`, accès autorisé |
| La commande existante d'Alice | `403`, accès refusé | `404`, page générique |
| Une commande inexistante | `404`, ressource absente | `404`, même page générique |

À l'étape initiale, le Voter protège le contenu de la commande d'Alice, mais
la différence entre `403` et `404` permet d'en déduire l'existence. Une petite
boucle de requêtes sur des identifiants numériques peut révéler cet indice
dans la plage explorée ; elle n'autorise pas la lecture des commandes.

À l'étape actuelle, le Voter conserve sa règle : propriétaire ou administrateur.
L'attribut `IsGranted` du contrôleur transforme le refus en `404` avec
`statusCode: Response::HTTP_NOT_FOUND`. Le template commun se trouve dans :

```text
templates/bundles/TwigBundle/Exception/error404.html.twig
```

Pour comparer les véritables pages publiques dans le navigateur, définir dans
`.env.local` :

```dotenv
APP_DEBUG=0
```

Une ligne commentée `# APP_DEBUG=0` dans `.env` ne désactive pas le debug.
Vérifier également les variables d'environnement du processus et redémarrer
le serveur local si nécessaire. Le mode debug peut afficher des détails
d'exception différents malgré deux statuts `404`. Réactiver le debug après
la démonstration si nécessaire pour poursuivre le développement.

La règle `access_control` suivante exige la connexion avant la recherche de
la commande :

```yaml
- { path: '^/orders(?:/|$)', roles: ROLE_USER }
```

Pour une URL `/orders/{id}` avec un identifiant numérique, un visiteur anonyme
est ainsi redirigé vers `/login`, que la commande existe ou non. Après la
connexion, le contrôle du Voter reste indispensable. Un administrateur peut
toujours consulter une commande existante.

Les réponses `403` de `/admin` pour un utilisateur standard et de la suppression
de compte avec un jeton CSRF invalide restent inchangées.

Le test `testForeignAndMissingOrdersReturnTheSamePublicPage()` désactive le
debug et compare le statut `404`, le type de contenu et le HTML des deux
réponses. Il vérifie aussi l'absence de la référence confidentielle `SEC-002`.
Cette comparaison couvre ces réponses HTML ; elle ne démontre pas l'absence
de tout indice temporel ou de toute divulgation par une autre route.

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

Depuis GitHub :

```bash
git clone https://github.com/StephaneBouret/tests-securite.git
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

Une classe de tests précise :

```bash
php bin/phpunit tests/Security/AccessControlTest.php
php bin/phpunit tests/Security/InputValidationTest.php
php bin/phpunit tests/Security/CsrfProtectionTest.php
php bin/phpunit tests/Security/VulnerabilityTest.php
php bin/phpunit tests/Security/AttackResistanceTest.php
php bin/phpunit tests/Security/SecurityRegressionTest.php
```

Uniquement le groupe des tests de régression :

```bash
php bin/phpunit --group security-regression
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
5. Repérer les deux protections complémentaires de `/admin` : la règle
   `access_control` dans `security.yaml` et l'attribut `#[IsGranted('ROLE_ADMIN')]`
   dans `AdminController.php`.

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
5. Relever l'identifiant réel de `SEC-002` dans une session Alice séparée,
   puis utiliser cette URL dans la session de Bob. Ne pas supposer que les
   identifiants des commandes sont toujours `1` et `2`.
6. Expliquer l'étape initiale : la commande d'Alice était refusée en `403`,
   tandis qu'une commande absente renvoyait `404`. Ces réponses permettaient
   de distinguer existence et absence lors d'une énumération bornée.
7. Observer la version actuelle : la commande d'Alice renvoie désormais
   `404`. Comparer avec un identifiant réellement absent, vérifié en base.
8. Avec `APP_DEBUG=0`, vérifier que les deux réponses utilisent la même
   page générique, sans données de la commande d'Alice. La prévisualisation
   `/_error/404` seule ne suffit pas : demander les deux véritables URL.
9. Si le script d'énumération initial est rejoué, interpréter maintenant `404`
   comme « ressource inexistante ou non accessible ».
10. Vérifier que Bob accède encore à sa commande et que Georges peut consulter
    celle d'Alice. Le masquage ne doit pas bloquer les accès autorisés.

Le TP 4 conserve sa numérotation dans ce README. Le support d'audit détaillé
développe l'IDOR et l'énumération dans son TP1, puis la XSS et la CSRF dans
ses TP2 et TP3.

### TP 5 - Tester la résistance

1. Remplir le champ caché `contact[website]` depuis les outils développeur.
2. Répondre volontairement faux à la question de sécurité.
3. Soumettre six fois `/contact` en moins d'une minute.
4. Observer la réponse `429` et l'en-tête `Retry-After`.
5. Échouer plusieurs connexions et observer le `login_throttling`.

### TP 6 - Tester les régressions de sécurité

Un test de non-régression garantit qu'une protection déjà validée reste active
après une correction, un refactoring ou une mise à jour. Techniquement, il
s'agit d'un test PHPUnit ordinaire conservé comme preuve d'un comportement de
sécurité attendu.

1. Exécuter les tests de régression avec un affichage détaillé :

   ```bash
   php bin/phpunit --testdox --group security-regression
   ```

2. Identifier le contrat de sécurité vérifié par chacun des sept tests :

   - l'utilisateur anonyme est redirigé lorsqu'il demande `/admin` ;
   - l'utilisateur standard reçoit une réponse `403` sur `/admin` ;
   - l'administrateur peut toujours accéder à `/admin` ;
   - le propriétaire peut consulter sa propre commande ;
   - un utilisateur reçoit désormais `404` pour la commande d'un autre utilisateur ;
   - une suppression sans token CSRF est refusée et le compte est conservé ;
   - une commande étrangère et une commande absente renvoient la même page
     publique `404`, avec le même type de contenu et sans référence confidentielle.

3. Dans `PurchaseOrderVoter.php`, remplacer temporairement :

   ```php
   return $subject->getOwner()?->getId() === $user->getId();
   ```

   par :

   ```php
   return $subject->getOwner()?->getId() !== $user->getId();
   ```

4. Relancer le groupe et observer l'échec des trois tests portant sur le
   propriétaire, l'IDOR et la comparaison des pages publiques.
5. Expliquer pourquoi l'inversion rend la commande du propriétaire inaccessible
   tout en autorisant celle d'un autre utilisateur.
6. Restaurer immédiatement la comparaison stricte avec `===`, sans commiter la
   modification volontairement vulnérable.
7. Relancer le groupe, puis l'ensemble de la suite :

   ```bash
   php bin/phpunit --group security-regression
   php bin/phpunit
   ```

8. Étudier enfin `testManualActionAcceptsValidCsrfToken()` dans
   `CsrfProtectionTest.php`. Ce test empêche le retour du bug où le compte était
   supprimé, mais où l'ancien utilisateur restait présent dans le token de
   sécurité pendant la redirection.

## Arborescence utile

```text
tests-securite/
├── .env.test
├── composer.json
├── phpunit.dist.xml
├── assets/
├── config/
│   ├── packages/
│   │   ├── csrf.yaml
│   │   ├── framework.yaml
│   │   ├── rate_limiter.yaml
│   │   └── security.yaml
│   └── routes/
├── migrations/
├── src/
│   ├── Controller/
│   │   ├── AccountController.php
│   │   ├── ApiRegistrationController.php
│   │   ├── CommentController.php
│   │   ├── ContactController.php
│   │   └── PurchaseOrderController.php
│   ├── DataFixtures/AppFixtures.php
│   ├── Entity/
│   │   ├── Comment.php
│   │   ├── PurchaseOrder.php
│   │   └── User.php
│   ├── Form/
│   ├── Model/
│   ├── Repository/
│   └── Security/Voter/PurchaseOrderVoter.php
├── templates/
│   └── bundles/TwigBundle/Exception/error404.html.twig
└── tests/
    ├── Controller/LogoutControllerTest.php
    ├── Security/
    │   ├── AccessControlTest.php
    │   ├── AttackResistanceTest.php
    │   ├── CsrfProtectionTest.php
    │   ├── InputValidationTest.php
    │   ├── SecurityRegressionTest.php
    │   ├── UserEntityTest.php
    │   └── VulnerabilityTest.php
    ├── Support/CreatesUsers.php
    └── bootstrap.php
```

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

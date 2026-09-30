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

Les exercices associent des cas autorisés, des cas refusés et des tests de
régression. La solidité de leurs preuves varie : les tests de connexion avec
charge SQL et des limiteurs restent notamment à renforcer.

Les manipulations concernent uniquement l'application locale et des comptes de
démonstration, dans un périmètre autorisé. Ce dépôt n'est ni un pentest exhaustif
ni une certification de sécurité.

## Ce que l'application permet de tester

| Partie du cours | Scénario | Routes principales | Tests |
| --- | --- | --- | --- |
| Accès utilisateurs | Anonyme, utilisateur et administrateur | `/account`, `/dashboard`, `/admin` | `AccessControlTest.php` |
| Validation des entrées | Entité, formulaire, champ forgé et API JSON | `/register`, `/api/register` | `InputValidationTest.php` |
| Protection CSRF | Origine étrangère, données inchangées après refus et suppression manuelle | `/account/edit`, `/account/delete` | `CsrfProtectionTest.php` |
| Déconnexion | POST, jeton CSRF, invalidation de session et parcours depuis la page de connexion | `/logout`, `/login` | `LogoutControllerTest.php` |
| Unicité de l’email | Inscription et modification du profil sans altérer un autre compte | `/register`, `/account/edit` | `EmailUniquenessTest.php` |
| Injection SQL | Charge utile envoyée au formulaire de connexion ; preuve à renforcer | `/login` | `VulnerabilityTest.php` |
| XSS stockée | Commentaire malveillant échappé par Twig | `/comments` | `VulnerabilityTest.php` |
| IDOR et énumération | Accès à la commande d'un autre utilisateur, puis masquage de son existence | `/orders/{id}` | `VulnerabilityTest.php`, `SecurityRegressionTest.php` |
| Résistance | Honeypot, question CAPTCHA, RateLimiter et login throttling | `/contact`, `/login` | `AttackResistanceTest.php` |
| Régression | Protections critiques conservées dans le temps | Routes sensibles | `SecurityRegressionTest.php` |

## Choix pédagogiques

### Des protections à observer et des limites explicites

Les charges utiles telles que :

```text
' OR 1=1 --
<script>alert("hack")</script>
```

sont envoyées aux fonctionnalités réelles de l'application, mais le code livré
met en œuvre les protections suivantes, dans les limites décrites plus bas :

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

### CSRF : distinguer les mécanismes et leurs effets

| Action | Contrôle dans cette application | Résultat d'une demande refusée |
| --- | --- | --- |
| Modification du profil | Formulaire avec CSRF sans état : origine et, selon le parcours, double soumission | `422`, erreur de formulaire |
| Suppression du compte | Jeton avec état lié à `delete-account-{id}` | `403` |
| Déconnexion | POST et jeton avec état lié à `logout` | GET : `405` ; POST sans jeton valable : `403` |

Dans `config/packages/csrf.yaml`, les identifiants `submit` et `authenticate`
restent sans état. `logout` a été retiré de cette liste pour utiliser un jeton
de session.

Le test de modification du profil transmet explicitement une origine étrangère,
tout en conservant la valeur CSRF du formulaire. Il vérifie le refus, puis
l'absence de modification de l'email et du prénom en base. Un cas autorisé
vérifie que les nouvelles valeurs sont effectivement enregistrées.

Les adresses `https://127.0.0.1:8000` et `https://127.0.0.1:9000` représentent
deux origines différentes. BrowserKit simule ces requêtes : aucun serveur
supplémentaire n'est nécessaire sur le port 9000 pour exécuter ces tests.

Le maintien de l'authentification après la modification de profil refusée
n'est pas vérifié par ce chantier ; voir les limites connues.

Pour la suppression, les tests couvrent l'absence de jeton, un jeton inventé,
un vrai jeton de déconnexion utilisé pour la mauvaise action et un jeton valide.
Ils vérifient les données et l'accès au compte après la requête.

### Déconnexion protégée

La route `/logout` accepte uniquement POST. La navigation fournit un formulaire
contenant `_token={{ csrf_token('logout') }}`. Symfony vérifie ce jeton et
invalide la session lors de la déconnexion.

Le lien GET résiduel de la page `/login` a été supprimé. Cette page invite
désormais à utiliser le bouton Déconnexion de la navigation.
`testLogoutFromLoginPageWorks()` couvre ce parcours.

Les tests vérifient également qu'une demande de déconnexion refusée conserve
l'authentification et qu'après une déconnexion valide, les anciens cookies ne
rétablissent pas l'accès dans l'environnement de test.

La suppression du compte conserve `$security->logout(false)` : elle a déjà
contrôlé son propre jeton CSRF. Cet appel interne n'affaiblit pas le contrôle
de la route publique `/logout`.

### Session et rejeu de cookie

Le TP de session montre qu'un cookie de session valide, copié volontairement
entre les clients du laboratoire, peut permettre de réutiliser l'identité
associée à cette session.

`HttpOnly`, `Secure` et `SameSite` réduisent certains risques de lecture ou
de transmission ; ils n'empêchent pas à eux seuls le rejeu d'un identifiant de
session déjà obtenu et encore valide. La déconnexion doit rendre l'ancienne
session inutilisable côté serveur.

### Login throttling et code HTTP 429

Le `login_throttling` natif de Symfony bloque les tentatives répétées puis
repasse par le mécanisme d'échec d'authentification, généralement avec une
redirection vers `/login`.

Le formulaire `/contact`, lui, utilise directement une `RateLimiterFactory` et
renvoie explicitement `429 Too Many Requests`, avec un en-tête `Retry-After`
calculé selon le temps restant avant une nouvelle tentative autorisée.

Les étudiants observent ainsi les deux stratégies.

La question « 3 + 4 » du formulaire de contact sert uniquement à tester un
challenge valide ou invalide sans clé API. Ce n'est pas un CAPTCHA de production.
Les protections contre les abus doivent être adaptées au contexte d'une application réelle.

## Les cinq chantiers issus de l'audit

| Chantier | Amélioration intégrée | Preuve recherchée |
| --- | --- | --- |
| 1. XSS stockée | Marqueur unique, relecture en base et consultation par Alice | Le commentaire publié est celui analysé ; son texte est conservé et sa charge n'est pas transformée en élément `script` |
| 2. Rôle forgé | Origine cohérente, cas normal et vérification exacte des erreurs | Le champ supplémentaire explique seul le refus ; aucun compte n'est créé |
| 3. Déconnexion | POST, CSRF avec état, invalidation et suppression du lien GET résiduel | Les demandes illégitimes échouent ; la déconnexion normale retire l'accès |
| 4. CSRF et données | Relecture après `clear()`, cas refusés et cas autorisés | Les refus conservent les données ; les demandes autorisées produisent l'effet attendu |
| 5. Email déjà utilisé | `UniqueEntity` sur `User`, contrainte SQL conservée | Erreur de formulaire compréhensible, comptes inchangés et conservation de son propre email autorisée |

L'inscription et la modification du profil restent liées à l'entité `User`.
Aucun DTO ni appel à `refresh()` n'est conservé dans le parcours de modification
du compte. Le trait `tests/Support/CreatesUsers.php` fournit les comptes créés
pour les tests concernés.

Le point central du parcours est de distinguer :
- la réponse HTTP ;
- la cause exacte d'un refus ;
- les données effectivement enregistrées ;
- l'état de l'authentification lorsque le test le contrôle.

Un statut d'erreur seul ne prouve pas qu'aucune donnée n'a changé.

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
symfony version
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
DATABASE_URL="mysql://root:@127.0.0.1:3306/symfony_tests_securite?serverVersion=8.4.7&charset=utf8mb4"
```

Adaptez l'utilisateur, le mot de passe et `serverVersion` à votre installation.
Le secret de développement fourni est réservé au laboratoire. Pour le remplacer,
utilisez `.env.dev.local`, chargé après `.env.dev` :

```dotenv
APP_SECRET="remplacer-par-une-valeur-aleatoire-locale"
```

Les fichiers `.env.local`, `.env.dev.local` et `.env.test.local` sont ignorés par
Git. Ne placez jamais de secret de production dans un fichier versionné.

### 4. Créer et préparer la base de développement

Ces commandes concernent uniquement la base locale du laboratoire.
**Le chargement des fixtures purge les données existantes de la base ciblée.**

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

### Docker : configuration à reprendre

Le fichier `compose.yaml` configure actuellement **PostgreSQL 16**, alors que
le parcours local documenté et la vérification finale utilisent **MySQL**.
Il ne constitue donc pas une alternative MySQL prête à l'emploi.

La cohérence entre Compose, la connexion Doctrine et les migrations doit être
traitée avant d'utiliser réellement cet environnement. Le parcours décrit ici
utilise le serveur MySQL local.

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

PHPUnit impose `APP_ENV=test`. Le fichier `.env.test` fournit la connexion de
test ; `.env.local` n'est pas chargé dans cet environnement.

Si vos identifiants MySQL diffèrent des valeurs du dépôt, créez `.env.test.local` :

```dotenv
DATABASE_URL="mysql://utilisateur:mot-de-passe@127.0.0.1:3306/symfony_tests_securite?serverVersion=8.4.7&charset=utf8mb4"
```

Adaptez les valeurs à votre installation et encodez les caractères réservés du
mot de passe dans l'URL. Les variables d'environnement du processus restent
prioritaires sur les fichiers dotenv.

Doctrine ajoute automatiquement le suffixe `_test` (complété par `TEST_TOKEN`
si défini). Avec la configuration fournie et sans `TEST_TOKEN`, la base se nomme :

```text
symfony_tests_securite_test
```

Avant toute préparation, vérifiez la base réellement ciblée. N’utilisez pas une
base contenant des données à conserver. Ne rajoutez pas `_test` au nom de base
dans l’URL si le suffixe Doctrine est conservé.

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

Les classes placées dans `tests/Security` (la déconnexion se trouve dans `tests/Controller`) :

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
php bin/phpunit tests/Security/EmailUniquenessTest.php
php bin/phpunit tests/Controller/LogoutControllerTest.php
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

Les six ateliers ci-dessous conservent la numérotation historique du README.
Le support d’audit détaillé utilise un autre découpage :

| TP du support d’audit | Sujet |
| --- | --- |
| TP1 | IDOR, énumération et masquage de l’existence des commandes |
| TP2 | Tentative de XSS stockée |
| TP3 | CSRF : jetons et requête d’une autre origine |
| TP4 | Réutilisation d’un cookie de session dans le laboratoire |
| TP5 | Manipulation des requêtes HTTP |

Les cinq chantiers de fiabilisation prolongent ces TP. Les exercices sur les
limiteurs restent disponibles, sans être considérés comme entièrement fiabilisés.

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

1. Inspecter le champ `account[_token]` de `/account/edit`.
2. Étudier le test d’origine étrangère, qui conserve la valeur du formulaire
   mais transmet des en-têtes `Origin` et `Referer` étrangers.
3. Vérifier le `422`, le message CSRF et les données inchangées en base.
4. Vérifier qu’une demande normale modifie effectivement le profil.
5. Envoyer `POST /account/delete` sans jeton, avec un jeton inventé, puis avec
   un vrai jeton destiné à la déconnexion : attendre `403` et un compte conservé.
6. Vérifier la suppression autorisée avec le jeton propre au compte.

Remplacer arbitrairement le jeton par `hack` ne suffit pas à expliquer les
contrôles CSRF sans état utilisés pour le formulaire de profil.

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

Un test de non-régression vérifie qu'une protection déjà validée reste active
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

8. Étudier enfin `testValidDeletionRemovesAccountAndLogsOut()` dans
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
│   │   ├── PurchaseOrderController.php
│   │   ├── RegistrationController.php
│   │   └── SecurityController.php
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
│   ├── base.html.twig
│   ├── security/login.html.twig
│   └── bundles/TwigBundle/Exception/error404.html.twig
└── tests/
    ├── Controller/LogoutControllerTest.php
    ├── Security/
    │   ├── AccessControlTest.php
    │   ├── AttackResistanceTest.php
    │   ├── CsrfProtectionTest.php
    │   ├── EmailUniquenessTest.php
    │   ├── InputValidationTest.php
    │   ├── SecurityRegressionTest.php
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
- Si un test échoue, identifier la cause avant de modifier le code ou les assertions.
  Une réduction volontaire du périmètre doit être documentée avec le problème
  connu ; elle ne constitue pas sa correction.

## Vérification finale et traçabilité

La vérification locale rapportée par Codex le **30 septembre 2026** concerne
le commit [`baa2daeeb72bfe286f8812c5cb5d05eb5d860d10`](https://github.com/StephaneBouret/tests-securite/commit/baa2daeeb72bfe286f8812c5cb5d05eb5d860d10).

Environnement rapporté : PHP 8.4.15, PHPUnit 13.2.6,
base `symfony_tests_securite_test` sur `127.0.0.1:3306`.
Le nom réel de la base et l'index SQL unique `UNIQ_IDENTIFIER_EMAIL`
ont été contrôlés avant l'exécution.

| Exécution sur ce commit | Résultat rapporté |
| --- | --- |
| Sélection des tests des chantiers et de non-régression | 28 tests, 198 assertions : succès |
| Suite complète | 37 tests, 246 assertions : succès |

Cette vérification a confirmé les preuves dans leur périmètre et identifié un
lien GET de déconnexion résiduel dans la page de connexion : le serveur le
refusait en `405`, sans déconnecter l'utilisateur.

Le commit [`5c5beda6ba01ebf645eff7cff724c568ebfd7a3f`](https://github.com/StephaneBouret/tests-securite/commit/5c5beda6ba01ebf645eff7cff724c568ebfd7a3f)
supprime ce lien et ajoute `testLogoutFromLoginPageWorks()`.

**Les chiffres ci-dessus décrivent l'exécution antérieure au correctif.**
Aucun nouveau résultat d'exécution après ce correctif n'est consigné ici.
Pour vérifier la version corrigée :

```bash
php bin/phpunit tests/Controller/LogoutControllerTest.php
php bin/phpunit
git diff --check
```

Cette traçabilité ne constitue pas une certification de sécurité. Elle distingue
le code corrigé et versionné des tests dont l'exécution est effectivement rapportée.

## Limites connues et travaux différés

| Sujet | État et suite envisagée |
| --- | --- |
| Profil invalide et authentification | Le formulaire peut modifier l'email de l'objet utilisateur en mémoire sans l'enregistrer. La différence avec la base peut entraîner une perte d'authentification à la requête suivante. Problème connu et différé ; le test CSRF de profil porte sur le refus et la conservation des données. |
| Email et demandes simultanées | `UniqueEntity` améliore le traitement des demandes séquentielles. La contrainte SQL reste indispensable ; une collision concurrente peut encore produire une exception non traitée. |
| Connexion avec charge SQL | Le refus observé doit être mieux étayé par une connexion normale, la vérification de sa cause et l'absence d'authentification après la tentative. |
| Limiteurs | Isoler leur état et vérifier précisément les seuils. Des exécutions rapprochées peuvent partager un état de limitation ; une suite verte ne clôture pas ce sujet. |
| Commentaires | Pagination et limitation des publications à traiter lors du travail sur les volumes et les abus. |
| Déploiement et Docker | Réconcilier la configuration PostgreSQL de Compose avec le parcours MySQL avant usage ; aucun déploiement de production validé par ce cours. |
| UUID | Réservés à la formation Symfony ; ils ne remplacent pas le contrôle d'autorisation. |

### Portée des tests automatisés

- BrowserKit et DomCrawler analysent les réponses sans exécuter le JavaScript
  d'un navigateur réel.
- Le test XSS vérifie la persistance et le HTML du commentaire ciblé ; il ne
  démontre pas à lui seul le comportement de toutes les charges dans un navigateur.
- Les en-têtes d'origine transmis par les tests vérifient la réaction du serveur,
  pas toutes les règles d'envoi des cookies d'un navigateur réel.
- Les contrôles de rejeu après déconnexion concernent les sessions de test
  Symfony, pas un stockage de session de production.
- Les tests utilisant `loginUser()` préparent une authentification ; ils ne
  reproduisent pas le parcours complet de connexion.
- Le masquage IDOR compare le statut, le type de contenu et le HTML public.
  Il ne démontre pas une indistinguabilité temporelle.

Les tests créent ou suppriment des données dans la base dédiée. Des comptes et
commentaires de test peuvent subsister entre les exécutions ; les adresses et
marqueurs uniques limitent les collisions, sans constituer un nettoyage automatique.

## Démarche pédagogique

Pour chaque scénario, préciser l'état initial, la requête, la règle attendue,
la cause du refus et l'état des données après la réponse. Vérifier également
qu'une demande autorisée reste possible.

L'IA aide à proposer des scénarios, analyser le code et rédiger des tests.
Ses conclusions doivent être confrontées aux preuves observées et aux limites
du périmètre. Le prochain travail porte sur les fondamentaux HTTP : méthodes,
en-têtes, statuts, redirections, cookies et sessions.

## Licence et usage

Projet conçu comme support de formation. `composer.json` déclare une licence
`proprietary` ; ce README n’accorde pas de licence supplémentaire.

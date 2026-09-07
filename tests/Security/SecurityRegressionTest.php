<?php

namespace App\Tests\Security;

use App\Repository\PurchaseOrderRepository;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('security-regression')]
class SecurityRegressionTest extends WebTestCase
{
    /**
     * Un visiteur anonyme ne doit jamais pouvoir accéder
     * à l'espace d'administration
     */
    public function testAdminAreaStillRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    /**
     * Un utilisateur authentifié sans ROLE_ADMIN
     * doit toujours recevoir une réponse 403.
     */
    public function testStandardUserStillCannotAccessAdminArea(): void
    {
        $client = static::createClient();

        $user = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => 'user@test.fr']);

        self::assertNotNull($user);

        $client->loginUser($user);
        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * La protection ne doit pas bloquer les utilisateurs
     * qui disposent réellement du rôle administrateur.
     */
    public function testAdministratorCanStillAccessAdminArea(): void
    {
        $client = static::createClient();

        $admin = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => 'admin@test.fr']);

        self::assertNotNull($admin);

        $client->loginUser($admin);
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Administration');
    }

    /**
     * Le propriétaire d'une commande doit toujours
     * pouvoir consulter sa propre ressource
     */
    public function testOrderOwnerCanStillAccessOwnOrder(): void
    {
        $client = static::createClient();

        $user = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => 'user@test.fr']);

        $order = static::getContainer()
            ->get(PurchaseOrderRepository::class)
            ->findOneBy(['reference' => 'SEC-001']);

        self::assertNotNull($user);
        self::assertNotNull($order);
        self::assertSame(
            $user->getId(),
            $order->getOwner()?->getId()
        );

        $client->loginUser($user);
        $client->request('GET', '/orders/' . $order->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'SEC-001');
    }

    /**
     * Un utilisateur ne doit jamais pouvoir consulter
     * la commande d'un autre utilisateur
     */
    public function testIdorProtectionIsStillApplied(): void
    {
        $client = static::createClient();

        $user = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => 'user@test.fr']);

        $foreignOrder = static::getContainer()
            ->get(PurchaseOrderRepository::class)
            ->findOneBy(['reference' => 'SEC-002']);

        self::assertNotNull($user);
        self::assertNotNull($foreignOrder);
        self::assertNotSame(
            $user->getId(),
            $foreignOrder->getOwner()?->getId()
        );

        $client->loginUser($user);
        $client->request(
            'GET',
            '/orders/' . $foreignOrder->getId()
        );

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Une tentative de suppression sans token CSRF
     * doit être refusée sans supprimer le compte
     */
    public function testRejectedAccountDeletionDoesNotDeleteUser(): void
    {
        $client = static::createClient();

        $email = 'user@test.fr';

        $user = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => $email]);

        self::assertNotNull($user);

        $client->loginUser($user);
        $client->request('POST', '/account/delete');

        self::assertResponseStatusCodeSame(403);

        $userAfterRequest = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => $email]);

        self::assertNotNull($userAfterRequest);
    }
}

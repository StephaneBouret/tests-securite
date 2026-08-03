<?php

namespace App\Tests\Security;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AccessControlTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedFromAccount(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account');

        self::assertResponseRedirects('/login');
    }

    public function testAuthenticatedUserCanAccessAccount(): void
    {
        $client = static::createClient();
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'user@test.fr']);

        self::assertNotNull($user);

        $client->loginUser($user);
        $client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mon compte');
    }

    public function testAdministratorCanAccessAdministration(): void
    {
        $client = static::createClient();
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'admin@test.fr']);

        self::assertNotNull($admin);

        $client->loginUser($admin);
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Administration');
    }

    public function testStandardUserCannotAccessAdministration(): void
    {
        $client = static::createClient();
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'user@test.fr']);

        self::assertNotNull($user);

        $client->loginUser($user);
        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }
}

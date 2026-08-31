<?php

namespace App\Tests\Security;

use App\Repository\UserRepository;
use App\Tests\Support\CreatesUsers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CsrfProtectionTest extends WebTestCase
{
    use CreatesUsers;

    // Cas n°1 : token valide
    public function testAccountFormContainsCsrfToken(): void
    {
        $client = static::createClient();
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'user@test.fr']);

        self::assertNotNull($user);

        $client->loginUser($user);
        $client->request('GET', '/account/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="account[_token]"]');
    }

    // Cas n°2 : token falsifié
    public function testAutomaticFormRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'user@test.fr']);

        self::assertNotNull($user);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/account/edit');
        $form = $crawler->selectButton('Enregistrer')->form([
            'account[email]' => 'user@test.fr',
            'account[firstname]' => 'Bob',
            'account[_token]' => 'hack',
        ]);

        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('body', 'CSRF');
    }

    // Cas n°3 : token absent
    public function testManualActionRejectsMissingCsrfToken(): void
    {
        $client = static::createClient();
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'user@test.fr']);

        self::assertNotNull($user);

        $client->loginUser($user);
        $client->request('POST', '/account/delete');

        self::assertResponseStatusCodeSame(403);
    }

    // Cas n°4 : token d'une autre action
    public function testManualActionRejectsForgedCsrfToken(): void
    {
        $client = static::createClient();
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'user@test.fr']);

        self::assertNotNull($user);

        $client->loginUser($user);
        $client->request('POST', '/account/delete', ['_token' => 'hack']);

        self::assertResponseStatusCodeSame(403);
    }

    // Cas n°5 : suppression d'un utilisateur (positif)
    public function testManualActionAcceptsValidCsrfToken(): void
    {
        $client = static::createClient();
        $email = sprintf(
            'delete-%s@test.fr',
            bin2hex(random_bytes(5))
        );

        $user = $this->createTestUser($email);

        $client->loginUser($user);

        $crawler = $client->request('GET', '/account');

        $token = $crawler
            ->filter(
                'form[action="/account/delete"] input[name="_token"]'
            )
            ->attr('value');

        $client->request('POST', '/account/delete', ['_token' => $token]);

        self::assertResponseRedirects('/');

        /**
         * Vérifie que la redirection ne provoque plus l'erreur
         * liée à l'utilisateur supprimé conservé dans le token
         */
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bienvenue');

        // Le compte doit avoir été supprimé de la base de données.
        $deletedUser = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => $email]);

        self::assertNull($deletedUser);

        // L'ancien utilisateur doit également être déconnecté
        $client->request('GET', '/account');

        self::assertResponseRedirects('/login');
    }
}

<?php

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LogoutControllerTest extends WebTestCase
{
    private const BASE_URL = 'https://127.0.0.1:8000';

    public function testGetLogoutDoesNotDisconnectUser(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', self::BASE_URL . '/logout');

        self::assertResponseStatusCodeSame(405);

        // Une méthode refusée ne doit pas déconnecter Bob.
        $client->request('GET', self::BASE_URL . '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'user@test.fr');
    }

    public function testRejectedLogoutKeepsUserAuthenticated(): void
    {
        $client = $this->createAuthenticatedClient();

        $attempts = [
            'jeton absent' => [],
            'jeton falsifié' => [
                '_token' => bin2hex(random_bytes(32)),
            ],
        ];

        foreach ($attempts as $label => $parameters) {
            $client->request(
                'POST',
                self::BASE_URL . '/logout',
                $parameters
            );

            self::assertResponseStatusCodeSame(403, $label);

            // Vérifier l'effet réel : Bob reste connecté.
            $client->request('GET', self::BASE_URL . '/account');

            self::assertResponseIsSuccessful($label);
            self::assertSelectorTextContains('body', 'user@test.fr');
        }
    }

    public function testValidLogoutInvalidatesSession(): void
    {
        $client = $this->createAuthenticatedClient();

        $form = $client->getCrawler()
            ->filter('#logout-form')
            ->form();

        // Conserver les cookies de la session authentifiée
        // avant que la réponse de déconnexion les remplace
        $oldCookies = [];

        foreach ($client->getCookieJar()->all() as $cookies) {
            $oldCookies[] = clone $cookies;
        }

        $client->submit($form);

        self::assertResponseRedirects('/');

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bienvenue');

        // Le client normalement déconnecté perd son accès.
        $client->request('GET', self::BASE_URL . '/account');

        self::assertResponseRedirects('/login');

        // Rejouer ensuite les anciens cookies
        $client->getCookieJar()->clear();

        foreach ($oldCookies as $cookie) {
            $client->getCookieJar()->set($cookie);
        }

        $client->request('GET', self::BASE_URL . '/account');

        self::assertResponseRedirects('/login');
    }

    private function createAuthenticatedClient(): KernelBrowser
    {
        $client = static::createClient();

        $bob = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => 'user@test.fr']);

        self::assertNotNull($bob);

        $client->loginUser($bob);

        $client->request('GET', self::BASE_URL . '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'user@test.fr');

        return $client;
    }
}

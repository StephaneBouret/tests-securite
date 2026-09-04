<?php

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AttackResistanceTest extends WebTestCase
{
    public function testHoneypotRejectsSpam(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '198.51.100.20']);
        $crawler = $client->request('GET', '/contact');
        $form = $crawler->selectButton('Envoyer')->form([
            'contact[name]' => 'Robot',
            'contact[email]' => 'robot@test.fr',
            'contact[message]' => 'Message automatisé indésirable.',
            'contact[humanAnswer]' => '7',
            'contact[website]' => 'https://spam.test',
        ]);

        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('body', 'Spam détecté');
    }

    public function testSecurityQuestionRejectsWrongAnswer(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '198.51.100.25']);
        $crawler = $client->request('GET', '/contact');

        $form = $crawler->selectButton('Envoyer')->form([
            'contact[name]' => 'Jean',
            'contact[email]' => 'jean@test.fr',
            'contact[message]' => 'Ceci est un message de test valide.',
            'contact[humanAnswer]' => '8',
            'contact[website]' => '',
        ]);

        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('body', 'Réponse incorrecte');
    }

    public function testContactFormReturns429AfterTooManySubmissions(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '198.51.100.30']);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $crawler = $client->request('GET', '/contact');
            $form = $crawler->selectButton('Envoyer')->form([
                'contact[name]' => 'Jean',
                'contact[email]' => 'jean@test.fr',
                'contact[message]' => 'Ceci est un message de test valide.',
                'contact[humanAnswer]' => '7',
                'contact[website]' => '',
            ]);

            $client->submit($form);

            if ($attempt <= 5) {
                self::assertResponseRedirects('/contact');
            }
        }

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('retry-after');
        self::assertGreaterThanOrEqual(
            1,
            (int) $client->getResponse()->headers->get('retry-after')
        );
    }

    public function testLoginThrottlingBlocksRepeatedFailures(): void
    {
        $client = static::createClient([], ['REMOTE_ADDR' => '198.51.100.40']);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $crawler = $client->request('GET', '/login');
            $client->submit($crawler->selectButton('Se connecter')->form([
                '_username' => 'user@test.fr',
                '_password' => 'bad-password',
            ]));

            self::assertResponseRedirects('/login');
        }

        $client->followRedirect();
        self::assertAnySelectorTextContains('body', 'Too many failed login attempts');
    }
}

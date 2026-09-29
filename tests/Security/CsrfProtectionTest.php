<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\CreatesUsers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CsrfProtectionTest extends WebTestCase
{
    use CreatesUsers;

    private const BASE_URL = 'https://127.0.0.1:8000';
    private const FOREIGN_ORIGIN = 'https://127.0.0.1:9000';

    public function testAccountFormContainsCsrfToken(): void
    {
        $client = static::createClient();

        $user = $this->createTestUser(
            'csrf-field-' . bin2hex(random_bytes(8)) . '@test.fr'
        );

        $client->loginUser($user);
        $client->request('GET', self::BASE_URL . '/account/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="account[_token]"]');
    }

    public function testForeignOriginCannotModifyAccount(): void
    {
        $client = static::createClient();

        $user = $this->createTestUser(
            'csrf-origin-' . bin2hex(random_bytes(8)) . '@test.fr'
        );

        $userId = $user->getId();
        $initialEmail = $user->getEmail();
        $initialFirstname = $user->getFirstname();

        self::assertNotNull($userId);

        $client->loginUser($user);

        $crawler = $client->request(
            'GET',
            self::BASE_URL . '/account/edit'
        );

        self::assertResponseIsSuccessful();

        // Préparer une vraie modification avec des valeurs valides.
        $form = $crawler->selectButton('Enregistrer')->form([
            'account[email]' =>
            'csrf-change-' . bin2hex(random_bytes(8)) . '@test.fr',
            'account[firstname]' => 'Modification refusee',
        ]);

        // Conserver les champs du formulaire, y compris son jeton.
        $parameters = $form->getPhpValues();

        $client->request(
            'POST',
            self::BASE_URL . '/account/edit',
            $parameters,
            server: [
                'HTTP_ORIGIN' => self::FOREIGN_ORIGIN,
                'HTTP_REFERER' => self::FOREIGN_ORIGIN . '/attaque',
            ]
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains(
            'form[name="account"]',
            'CSRF'
        );

        // Vérifier l'état enregistré, pas seulement le statut HTTP.
        $reloadedUser = $this->reloadUser($userId);

        self::assertNotNull($reloadedUser);
        self::assertSame($initialEmail, $reloadedUser->getEmail());
        self::assertSame(
            $initialFirstname,
            $reloadedUser->getFirstname()
        );
    }

    public function testValidRequestPersistsAccountChanges(): void
    {
        $client = static::createClient();

        $user = $this->createTestUser(
            'csrf-valid-' . bin2hex(random_bytes(8)) . '@test.fr'
        );

        $userId = $user->getId();

        self::assertNotNull($userId);

        $newEmail = 'csrf-updated-'
            . bin2hex(random_bytes(8))
            . '@test.fr';

        $newFirstname = 'Modification acceptee';

        $client->loginUser($user);

        $crawler = $client->request(
            'GET',
            self::BASE_URL . '/account/edit'
        );

        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form([
            'account[email]' => $newEmail,
            'account[firstname]' => $newFirstname,
        ]);

        $client->request(
            'POST',
            self::BASE_URL . '/account/edit',
            $form->getPhpValues(),
            server: [
                'HTTP_ORIGIN' => self::BASE_URL,
                'HTTP_REFERER' => self::BASE_URL . '/account/edit',
            ]
        );

        self::assertResponseRedirects('/account');

        // Rechercher par identifiant : l'email vient de changer.
        $reloadedUser = $this->reloadUser($userId);

        self::assertNotNull($reloadedUser);
        self::assertSame($newEmail, $reloadedUser->getEmail());
        self::assertSame(
            $newFirstname,
            $reloadedUser->getFirstname()
        );
    }

    public function testRejectedDeletionPreservesAccount(): void
    {
        $client = static::createClient();

        $user = $this->createTestUser(
            'csrf-delete-refused-'
                . bin2hex(random_bytes(8))
                . '@test.fr'
        );

        $userId = $user->getId();
        $initialEmail = $user->getEmail();
        $initialFirstname = $user->getFirstname();

        self::assertNotNull($userId);

        $client->loginUser($user);

        $crawler = $client->request(
            'GET',
            self::BASE_URL . '/account'
        );

        self::assertResponseIsSuccessful();

        // Ce jeton est réel, mais il protège la déconnexion.
        $logoutToken = $crawler
            ->filter('#logout-form input[name="_token"]')
            ->attr('value');

        self::assertNotEmpty($logoutToken);

        $attempts = [
            'jeton absent' => [],
            'jeton inventé' => [
                '_token' => bin2hex(random_bytes(32)),
            ],
            'jeton destiné à la déconnexion' => [
                '_token' => $logoutToken,
            ],
        ];

        foreach ($attempts as $label => $parameters) {
            $client->request(
                'POST',
                self::BASE_URL . '/account/delete',
                $parameters
            );

            self::assertResponseStatusCodeSame(403, $label);

            $reloadedUser = $this->reloadUser($userId);

            self::assertNotNull(
                $reloadedUser,
                'Le compte doit être conservé : ' . $label
            );

            self::assertSame(
                $initialEmail,
                $reloadedUser->getEmail()
            );

            self::assertSame(
                $initialFirstname,
                $reloadedUser->getFirstname()
            );

            $client->request(
                'GET',
                self::BASE_URL . '/account'
            );

            self::assertResponseIsSuccessful($label);
            self::assertSelectorTextContains('body', $initialEmail);
        }
    }

    public function testValidDeletionRemovesAccountAndLogsOut(): void
    {
        $client = static::createClient();

        $user = $this->createTestUser(
            'csrf-delete-valid-'
                . bin2hex(random_bytes(8))
                . '@test.fr'
        );

        $userId = $user->getId();

        self::assertNotNull($userId);

        $client->loginUser($user);

        $crawler = $client->request(
            'GET',
            self::BASE_URL . '/account'
        );

        self::assertResponseIsSuccessful();

        $token = $crawler
            ->filter(
                'form[action="/account/delete"] input[name="_token"]'
            )
            ->attr('value');

        self::assertNotEmpty($token);

        $client->request(
            'POST',
            self::BASE_URL . '/account/delete',
            ['_token' => $token]
        );

        self::assertResponseRedirects('/');

        // Le compte doit réellement avoir disparu de la base.
        self::assertNull($this->reloadUser($userId));

        // L'accueil doit rester accessible après la suppression.
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bienvenue');

        // La session ne doit plus donner accès au compte supprimé.
        $client->request('GET', self::BASE_URL . '/account');

        self::assertResponseRedirects('/login');
    }

    private function reloadUser(int $id): ?User
    {
        // Écarter les objets déjà conservés en mémoire par Doctrine.
        static::getContainer()
            ->get(EntityManagerInterface::class)
            ->clear();

        return static::getContainer()
            ->get(UserRepository::class)
            ->find($id);
    }
}

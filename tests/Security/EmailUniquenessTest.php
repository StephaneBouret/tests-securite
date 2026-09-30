<?php

namespace App\Tests\Security;

use App\Repository\UserRepository;
use App\Tests\Support\CreatesUsers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

class EmailUniquenessTest extends WebTestCase
{
    use CreatesUsers;

    private const BASE_URL = 'https://127.0.0.1:8000';

    private const DUPLICATE_EMAIL_MESSAGE =
    'Cette adresse email est déjà utilisée.';

    public function testRegistrationRejectsAnAlreadyUsedEmail(): void
    {
        $client = static::createClient();

        // 1. Un compte possède déjà cette adresse.
        $email = 'email-existing-'
            . bin2hex(random_bytes(8))
            . '@example.test';

        $alice = $this->createTestUser($email);
        $aliceId = $alice->getId();

        self::assertNotNull($aliceId);

        $aliceBefore = $this->readUserState($aliceId);

        $countBefore = static::getContainer()
            ->get(UserRepository::class)
            ->count([]);

        // 2. Préparer une inscription normale, avec son jeton.
        $url = self::BASE_URL . '/register';
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Créer un compte')->form([
            'registration_form[email]' => $email,
            'registration_form[firstname]' => 'Autre personne',
            'registration_form[plainPassword]' => 'DifferentPassword456!',
        ]);

        // Même origine : nous ne cherchons pas un refus CSRF.
        $client->request(
            'POST',
            $url,
            $form->getPhpValues(),
            [],
            [
                'HTTP_ORIGIN' => self::BASE_URL,
                'HTTP_REFERER' => $url,
            ]
        );

        // 3. Le refus doit être causé par l'email déjà utilisé.
        self::assertResponseStatusCodeSame(422);

        $this->assertOnlyDuplicateEmailError(
            $client->getCrawler(),
            'registration_form'
        );

        // 4. Relire le compte existant depuis la base.
        self::assertSame(
            $aliceBefore,
            $this->readUserState($aliceId)
        );

        // 5. Aucun compte supplémentaire ne doit avoir été créé.
        $repository = static::getContainer()
            ->get(UserRepository::class);

        self::assertSame($countBefore, $repository->count([]));
        self::assertSame(1, $repository->count(['email' => $email]));
    }

    public function testAccountCannotTakeAnotherAccountsEmail(): void
    {
        $client = static::createClient();

        $suffix = bin2hex(random_bytes(8));

        $alice = $this->createTestUser(
            'email-alice-' . $suffix . '@example.test'
        );

        $bob = $this->createTestUser(
            'email-bob-' . $suffix . '@example.test'
        );

        $aliceId = $alice->getId();
        $bobId = $bob->getId();

        self::assertNotNull($aliceId);
        self::assertNotNull($bobId);
        self::assertNotSame($aliceId, $bobId);

        $aliceBefore = $this->readUserState($aliceId);
        $bobBefore = $this->readUserState($bobId);

        // 1. Bob accède à son propre formulaire.
        $client->loginUser($bob);

        $url = self::BASE_URL . '/account/edit';
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();

        // 2. Il demande l'email d'Alice et change aussi son prénom.
        $form = $crawler->selectButton('Enregistrer')->form([
            'account[email]' => $aliceBefore['email'],
            'account[firstname]' => 'Modification refusee',
        ]);

        $client->request(
            'POST',
            $url,
            $form->getPhpValues(),
            [],
            [
                'HTTP_ORIGIN' => self::BASE_URL,
                'HTTP_REFERER' => $url,
            ]
        );

        // 3. Identifier précisément la cause du refus.
        self::assertResponseStatusCodeSame(422);

        $this->assertOnlyDuplicateEmailError(
            $client->getCrawler(),
            'account'
        );

        // 4. Aucun des deux comptes ne doit avoir changé en base.
        self::assertSame(
            $aliceBefore,
            $this->readUserState($aliceId)
        );

        self::assertSame(
            $bobBefore,
            $this->readUserState($bobId)
        );
    }

    public function testAccountCanKeepItsOwnEmail(): void
    {
        $client = static::createClient();

        $email = 'email-own-'
            . bin2hex(random_bytes(8))
            . '@example.test';

        $bob = $this->createTestUser($email);
        $bobId = $bob->getId();

        self::assertNotNull($bobId);

        $before = $this->readUserState($bobId);

        $client->loginUser($bob);

        $url = self::BASE_URL . '/account/edit';
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();

        // Même email, mais nouveau prénom.
        $form = $crawler->selectButton('Enregistrer')->form([
            'account[email]' => $email,
            'account[firstname]' => 'Nouveau prenom',
        ]);

        $client->request(
            'POST',
            $url,
            $form->getPhpValues(),
            [],
            [
                'HTTP_ORIGIN' => self::BASE_URL,
                'HTTP_REFERER' => $url,
            ]
        );

        self::assertResponseRedirects('/account');

        // Seul le prénom doit avoir changé.
        $expected = $before;
        $expected['firstname'] = 'Nouveau prenom';

        self::assertSame(
            $expected,
            $this->readUserState($bobId)
        );
    }

    private function assertOnlyDuplicateEmailError(
        Crawler $crawler,
        string $formName
    ): void {
        // Les templates actuels affichent les erreurs dans des listes.
        $errors = $crawler
            ->filter('form[name="' . $formName . '"] ul > li')
            ->each(
                static fn(Crawler $node): string => $node->text()
            );

        self::assertSame(
            [self::DUPLICATE_EMAIL_MESSAGE],
            $errors,
            'La seule erreur attendue concerne l’email déjà utilisé.'
        );
    }

    private function readUserState(int $id): array
    {
        // Éviter de vérifier uniquement un objet gardé en mémoire.
        static::getContainer()
            ->get(EntityManagerInterface::class)
            ->clear();

        $user = static::getContainer()
            ->get(UserRepository::class)
            ->find($id);

        self::assertNotNull($user);

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstname' => $user->getFirstname(),
            'password' => $user->getPassword(),
            'roles' => $user->getRoles(),
        ];
    }
}

<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class InputValidationTest extends WebTestCase
{
    public function testUserEntityRejectsInvalidInput(): void
    {
        self::bootKernel(); // Démarrage de Symfony

        $user = (new User())
            ->setEmail('not-an-email')
            ->setFirstname('')
            ->setPassword('not-used-by-validation');

        $violations = static::getContainer()
            ->get(ValidatorInterface::class) // Récupération du service
            ->validate($user); // Validation de l'entité

        $invalidProperties = [];
        foreach ($violations as $violation) {
            $invalidProperties[] = $violation->getPropertyPath();
        }

        self::assertContains('email', $invalidProperties);
        self::assertContains('firstname', $invalidProperties);
    }

    public function testApiReturns422ForInvalidJsonPayload(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/register', [
            'email' => 'abc',
            'password' => '123',
            'firstname' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('email', $data['errors']);
        self::assertArrayHasKey('password', $data['errors']);
        self::assertArrayHasKey('firstname', $data['errors']);
    }

    public function testMalformedJsonReturns400(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"email":'
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testRegistrationAcceptsValidData(): void
    {
        $client = static::createClient();

        $origin = 'https://127.0.0.1:8000';
        $url = $origin . '/register';

        // 1. Charger le véritable formulaire.
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();

        $token = $crawler
            ->filter('input[name="registration_form[_token]"]')
            ->attr('value');

        // Un email disponible pour cette exécution.
        $email = 'registration-valid-'
            . bin2hex(random_bytes(8))
            . '@test.fr';

        // 2. Envoyer uniquement les champs prévus par le formulaire.
        $client->request(
            'POST',
            $url,
            [
                'registration_form' => [
                    'email' => $email,
                    'plainPassword' => 'Password123!',
                    'firstname' => 'Mallory',
                    '_token' => $token,
                ],
            ],
            server: [
                'HTTP_ORIGIN' => $origin,
                'HTTP_REFERER' => $url,
            ]
        );

        // 3. Le parcours normal doit réussir.
        self::assertResponseRedirects('/login');

        // 4. Relire le compte depuis la base.
        static::getContainer()
            ->get(EntityManagerInterface::class)
            ->clear();

        $user = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => $email]);

        self::assertNotNull($user);
        self::assertSame('Mallory', $user->getFirstname());

        // 5. Le compte créé possède des droits ordinaires.
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testRegistrationCannotElevateRolesWithForgedField(): void
    {
        $client = static::createClient();

        $origin = 'https://127.0.0.1:8000';
        $url = $origin . '/register';

        // 1. Charger le formulaire et récupérer son jeton.
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();

        $token = $crawler
            ->filter('input[name="registration_form[_token]"]')
            ->attr('value');

        $email = 'registration-forged-'
            . bin2hex(random_bytes(8))
            . '@test.fr';

        // 2. Envoyer des données valides, avec un champ interdit en plus.
        $crawler = $client->request(
            'POST',
            $url,
            [
                'registration_form' => [
                    'email' => $email,
                    'plainPassword' => 'Password123!',
                    'firstname' => 'Mallory',
                    'roles' => ['ROLE_ADMIN'],
                    '_token' => $token,
                ],
            ],
            server: [
                'HTTP_ORIGIN' => $origin,
                'HTTP_REFERER' => $url,
            ]
        );

        // 3. La demande doit être refusée.
        self::assertResponseStatusCodeSame(422);

        // 4. Récupérer les messages d'erreur affichés dans le formulaire.
        $errors = $crawler
            ->filter('form[name="registration_form"] ul > li')
            ->each(
                static fn(Crawler $node): string => $node->text()
            );

        // Utiliser la traduction active du message standard de Symfony.
        $expectedError = static::getContainer()
            ->get('translator')
            ->trans(
                'This form should not contain extra fields.',
                [],
                'validators'
            );

        // Une seule erreur est attendue : le champ supplémentaire.
        // Une erreur CSRF supplémentaire ferait échouer cette assertion.
        self::assertSame(
            [$expectedError],
            $errors,
            'Le refus doit être expliqué uniquement par le champ supplémentaire.'
        );

        // 5. Vérifier l'absence réelle du compte en base.
        static::getContainer()
            ->get(EntityManagerInterface::class)
            ->clear();

        $user = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => $email]);

        self::assertNull(
            $user,
            'Aucun compte ne doit être créé à partir de cette demande refusée.'
        );
    }
}

<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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

    public function testRegistrationCannotElevateRolesWithForgedField(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');
        $token = $crawler->filter('input[name="registration_form[_token]"]')->attr('value');
        $email = sprintf('mallory-%s@test.fr', bin2hex(random_bytes(5)));

        $client->request(
            'POST',
            '/register',
            [
                'registration_form' => [
                    'email' => $email,
                    'plainPassword' => 'Password123!',
                    'firstname' => 'Mallory',
                    'roles' => ['ROLE_ADMIN'],
                    '_token' => $token,
                ],
            ],
            server: ['HTTP_REFERER' => 'https://127.0.0.1:8000/register']
        );

        self::assertResponseStatusCodeSame(422);

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => $email]);

        self::assertNull($user);
    }
}

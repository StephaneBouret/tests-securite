<?php

namespace App\Tests\Support;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

trait CreatesUsers
{
    abstract protected static function getContainer(): Container;
    
    /**
     * @param list<string> $roles
     */
    private function createTestUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstname('Utilisateur test')
            ->setRoles($roles);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}

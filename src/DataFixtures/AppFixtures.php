<?php

namespace App\DataFixtures;

use App\Entity\Comment;
use App\Entity\PurchaseOrder;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user->setEmail('user@test.fr');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, 'Password123!')
        );
        $user->setFirstname('Bob');

        $admin = new User();
        $admin->setEmail('admin@test.fr');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword(
            $this->passwordHasher->hashPassword($admin, 'Password123!')
        );
        $admin->setFirstname('Georges');

        $alice = new User();
        $alice->setEmail('alice@test.fr');
        $alice->setRoles(['ROLE_USER']);
        $alice->setPassword(
            $this->passwordHasher->hashPassword($alice, 'Password123!')
        );
        $alice->setFirstname('Alice');

        $bobOrder = (new PurchaseOrder())
            ->setReference('SEC-001')
            ->setDescription('Commande appartenant à Bob')
            ->setTotalCents(12900)
            ->setOwner($user);

        $aliceOrder = (new PurchaseOrder())
            ->setReference('SEC-002')
            ->setDescription('Commande confidentielle appartenant à Alice')
            ->setTotalCents(24900)
            ->setOwner($alice);

        $comment = (new Comment())
            ->setContent('Un commentaire pédagogique sans danger.')
            ->setAuthor($user);

        $manager->persist($user);
        $manager->persist($admin);
        $manager->persist($alice);
        $manager->persist($bobOrder);
        $manager->persist($aliceOrder);
        $manager->persist($comment);

        $manager->flush();
    }
}

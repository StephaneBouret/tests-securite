<?php

namespace App\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegistrationPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'L\'email est obligatoire.')]
        #[Assert\Email(message: 'L\'email doit être valide.')]
        public ?string $email,
        #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.')]
        #[Assert\Length(min: 12, max: 4096)]
        public ?string $password,
        #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
        #[Assert\Length(min: 2, max: 100)]
        public ?string $firstname,
    ) {
    }
}

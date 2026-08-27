<?php

namespace App\Controller;

use App\Model\RegistrationPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ApiRegistrationController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function __invoke(Request $request, ValidatorInterface $validator): JsonResponse
    {
        $data = $request->toArray();

        $payload = new RegistrationPayload(
            email: is_string($data['email'] ?? null) ? $data['email'] : null,
            password: is_string($data['password'] ?? null) ? $data['password'] : null,
            firstname: is_string($data['firstname'] ?? null) ? $data['firstname'] : null,
        );

        $violations = $validator->validate($payload);
        if (count($violations) > 0) {
            $errors = [];

            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()][] = $violation->getMessage();
            }

            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(
            ['message' => 'Données valides. Aucun compte n\'est créé par cette route pédagogique'],
            Response::HTTP_CREATED
        );
    }
}

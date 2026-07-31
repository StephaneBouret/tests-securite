<?php

namespace App\Controller;

use App\Form\ContactType;
use App\Model\ContactMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        #[Target('contact_form')] RateLimiterFactoryInterface $contactFormLimiter,
    ): Response
    {
        $message = new ContactMessage();
        $form = $this->createForm(ContactType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $limiter = $contactFormLimiter->create($request->getClientIp() ?? 'unknown');
            $limit = $limiter->consume();

            if (!$limit->isAccepted()) {
                $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

                return new Response(
                    'Trop de soumissions. Veuillez réessayer plus tard.',
                    Response::HTTP_TOO_MANY_REQUESTS,
                    ['Retry-After' => (string) $retryAfter]
                );
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->addFlash('success', 'Message accepté par la démonstration.');

            return $this->redirectToRoute('app_contact');
        }

        return $this->render('contact/index.html.twig', [
            'contactForm' => $form,
        ], new Response(
            status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK
        ));
    }
}

<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\User;
use App\Form\CommentType;
use App\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class CommentController extends AbstractController
{
    #[Route('/comments', name: 'app_comments', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        CommentRepository $commentRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $comment = new Comment();
        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw $this->createAccessDeniedException();
            }

            $comment->setAuthor($user);
            $entityManager->persist($comment);
            $entityManager->flush();

            return $this->redirectToRoute('app_comments');
        }

        return $this->render('comment/index.html.twig', [
            'commentForm' => $form,
            'comments' => $commentRepository->findBy([], ['createdAt' => 'DESC']),
        ], new Response(
            status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK
        ));
    }
}

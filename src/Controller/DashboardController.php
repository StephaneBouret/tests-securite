<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\PurchaseOrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(PurchaseOrderRepository $orderRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('dashboard/index.html.twig', [
            'orders' => $orderRepository->findBy(['owner' => $user]),
        ]);
    }
}

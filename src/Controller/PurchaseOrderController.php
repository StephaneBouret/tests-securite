<?php

namespace App\Controller;

use App\Entity\PurchaseOrder;
use App\Security\Voter\PurchaseOrderVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PurchaseOrderController extends AbstractController
{
    #[Route('/orders/{id}', name: 'app_order_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(PurchaseOrderVoter::VIEW, 'order')]
    public function show(PurchaseOrder $order): Response
    {
        return $this->render('order/show.html.twig', [
            'order' => $order,
        ]);
    }
}

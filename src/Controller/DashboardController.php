<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\FoundationStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(FoundationStatus $foundationStatus): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'foundation' => $foundationStatus->snapshot(),
        ]);
    }
}

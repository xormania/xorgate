<?php

namespace App\Controller;

use App\Service\FoundationStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function __invoke(FoundationStatus $status): JsonResponse
    {
        $snapshot = $status->snapshot();

        return new JsonResponse($snapshot, $snapshot['ready'] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}

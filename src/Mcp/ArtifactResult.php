<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Exception\IntakeCapacityException;
use App\Exception\RegistryConflictException;
use App\Exception\RegistryNotFoundException;

final class ArtifactResult
{
    public static function error(\Throwable $exception): array
    {
        if ($exception instanceof IntakeCapacityException) {
            return $exception->response();
        }
        $code = match (true) {
            $exception instanceof \InvalidArgumentException => 'invalid_input',
            $exception instanceof RegistryConflictException => 'conflict',
            $exception instanceof RegistryNotFoundException => 'not_found',
            default => 'unavailable',
        };

        return [
            'schema_version' => 1,
            'accepted' => false,
            'error' => [
                'code' => $code,
                'message' => 'unavailable' === $code ? 'Report intake is unavailable. Verify storage and database readiness.' : $exception->getMessage(),
            ],
        ];
    }
}

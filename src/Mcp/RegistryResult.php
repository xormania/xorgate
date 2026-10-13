<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Exception\RegistryCapacityException;
use App\Exception\RegistryConflictException;
use App\Exception\RegistryNotFoundException;

final class RegistryResult
{
    public static function error(\Throwable $exception): array
    {
        if ($exception instanceof RegistryCapacityException) {
            return $exception->response();
        }

        $code = match (true) {
            $exception instanceof \InvalidArgumentException => 'invalid_input',
            $exception instanceof RegistryNotFoundException => 'not_found',
            $exception instanceof RegistryConflictException => 'conflict',
            default => 'unavailable',
        };

        return [
            'schema_version' => 1,
            'accepted' => false,
            'error' => [
                'code' => $code,
                'message' => 'unavailable' === $code
                    ? 'Project registry is unavailable. Verify database readiness.'
                    : $exception->getMessage(),
            ],
        ];
    }
}

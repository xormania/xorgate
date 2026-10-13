<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Exception\ProbeCapacityException;
use App\Service\FoundationProbe;
use App\Service\FoundationStatus;
use Mcp\Capability\Attribute\McpTool;

final class FoundationTools
{
    public function __construct(
        private readonly FoundationStatus $status,
        private readonly FoundationProbe $probes,
    ) {
    }

    #[McpTool(name: 'xorgate_runtime_status', description: 'Inspect xorgate database, artifact and queue storage readiness. A completed foundation probe separately verifies worker execution.')]
    public function runtimeStatus(): array
    {
        return $this->status->snapshot();
    }

    #[McpTool(name: 'xorgate_probe_dispatch', description: 'Queue a persistent asynchronous readiness probe within the shared capacity/rate limits. Repeating an existing UUID stays idempotent at capacity. A limit rejection returns accepted=false with a reason and capacity data. This does not execute managed-project tests.')]
    public function dispatch(?string $probeId = null): array
    {
        try {
            return $this->probes->dispatch($probeId);
        } catch (ProbeCapacityException $exception) {
            return $exception->response();
        }
    }

    #[McpTool(name: 'xorgate_probe_get', description: 'Retrieve a persistent readiness probe by UUID, including completion and artifact integrity metadata.')]
    public function get(string $probeId): array
    {
        return $this->probes->get($probeId);
    }
}

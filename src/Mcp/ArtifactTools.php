<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Service\ReportIntake;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

final class ArtifactTools
{
    private const UUID = ['type' => 'string', 'maxLength' => 36];
    private const REVISION = ['type' => ['string', 'null'], 'maxLength' => 256];
    private const OUTPUT = ['type' => 'object'];
    private const INPUT = [
        'type' => 'object',
        'properties' => [
            'schema_version' => ['type' => 'integer', 'enum' => [1]],
            'request_id' => self::UUID + ['description' => 'Caller-supplied UUID; identity is scoped to project, execution and report_role.'],
            'report_role' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Intended report slot within this execution; default junit.'],
            'format' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Adapter selector; default junit. Installed formats: junit (JUnit XML), playwright-json (Playwright JSON reporter results), playwright-failed-attempts and playwright-durations (UXor playwright-summary schema 1), jev-assessment (one UXor Jev assessment.json, version 1; read only, Jev is never called) and text-log (any text log). Unsupported formats remain visible as parser outcomes.'],
            'filename' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'media_type' => ['type' => ['string', 'null'], 'maxLength' => 120],
            'source' => [
                'type' => 'object',
                'minProperties' => 1,
                'properties' => [
                    'repository_identity' => ['type' => ['string', 'null'], 'maxLength' => 160],
                    'repository_url' => ['type' => ['string', 'null'], 'maxLength' => 2048],
                    'tested_revision' => self::REVISION,
                    'workspace_fingerprint' => self::REVISION,
                    'pr_head_revision' => self::REVISION,
                    'pr_base_revision' => self::REVISION,
                    'pr_merge_revision' => self::REVISION,
                ],
                'additionalProperties' => false,
                'description' => 'Assertions against the immutable execution source. Supply at least one known non-null field; explicit null asserts unknown.',
            ],
        ],
        'required' => ['request_id', 'source'],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly ReportIntake $intake)
    {
    }

    #[McpTool(name: 'xorgate_report_submit', description: 'Preserve original report bytes and queue durable asynchronous normalization. Base64 content is limited to 64 KiB decoded; upload or CLI-stream larger reports and use their opaque handles. Supply request_id and known execution source assertions. Equal retries reuse the operation; changed bytes or metadata under the same scoped identity conflict. This does not execute tests.', outputSchema: self::OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function submit(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::UUID)] string $executionId, #[Schema(definition: self::INPUT)] array $input, #[Schema(maxLength: 87_384)] string $contentBase64): array
    {
        return $this->result(fn (): array => $this->intake->submitBase64($projectId, $executionId, $input, $contentBase64));
    }

    #[McpTool(name: 'xorgate_report_submit_artifact', description: 'Queue normalization from an existing verified artifact handle belonging to this project and execution. Supply scoped request_id and source assertions. No server paths, cross-execution attachments or remote URL fetching are accepted.', outputSchema: self::OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function submitArtifact(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::UUID)] string $executionId, #[Schema(definition: self::INPUT)] array $input, #[Schema(definition: self::UUID)] string $artifactId): array
    {
        return $this->result(fn (): array => $this->intake->submitArtifact($projectId, $executionId, $input, $artifactId));
    }

    #[McpTool(name: 'xorgate_operation_get', description: 'Retrieve durable report intake progress, parser outcome and original artifact availability within its project/execution. Parsing progress is separate from native test outcomes. Poll this bounded handle after submission or reconnect.', outputSchema: self::OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function operation(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::UUID)] string $executionId, #[Schema(definition: self::UUID)] string $operationId): array
    {
        return $this->result(fn (): array => $this->intake->getOperation($projectId, $executionId, $operationId));
    }

    #[McpTool(name: 'xorgate_evidence_list', description: 'List bounded report operation summaries for an execution, including queued and malformed imports. Use next_cursor for the next UUID page. This list does not hide parser failures or claim an execution verdict.', outputSchema: self::OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function evidence(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::UUID)] string $executionId, #[Schema(minimum: 1, maximum: 100)] int $limit = 25, #[Schema(maxLength: 36)] ?string $cursor = null): array
    {
        return $this->result(fn (): array => $this->intake->listEvidence($projectId, $executionId, $limit, $cursor));
    }

    #[McpTool(name: 'xorgate_observations_list', description: 'List bounded normalized case observations for one report operation. Each observation is traceable to the original bytes and execution context. Native failure/error/skipped outcomes and parser limitations remain separate from execution status.', outputSchema: self::OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function observations(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::UUID)] string $executionId, #[Schema(definition: self::UUID)] string $operationId, #[Schema(minimum: 1, maximum: 100)] int $limit = 25, #[Schema(maxLength: 36)] ?string $cursor = null): array
    {
        return $this->result(fn (): array => $this->intake->listObservations($projectId, $executionId, $operationId, $limit, $cursor));
    }

    #[McpTool(name: 'xorgate_attempts_list', description: 'List bounded native test attempts (Playwright results, including retries) for one native report operation, in report order. Each attempt keeps its retry index, native status, whether it matched the expected status, bounded error and output excerpts, and references to derived annotations linked to it. Linked annotations never add attempts.', outputSchema: self::OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function attempts(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::UUID)] string $executionId, #[Schema(definition: self::UUID)] string $operationId, #[Schema(minimum: 1, maximum: 100)] int $limit = 25, #[Schema(maxLength: 36)] ?string $cursor = null): array
    {
        return $this->result(fn (): array => $this->intake->listAttempts($projectId, $executionId, $operationId, $limit, $cursor));
    }

    #[McpTool(name: 'xorgate_annotations_list', description: 'List bounded derived annotations (failed-attempt records, durations, existing Jev assessments, producer status and errors outside tests) produced by one import or linked to one native import. Each carries its exact target, bounded data and explicit match status: linked, unmatched, ambiguous or not_applicable with a reason. Advisory assessments never rewrite native outcomes; Jev assessments are read from their retained files and Jev is never called.', outputSchema: self::OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function annotations(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::UUID)] string $executionId, #[Schema(definition: self::UUID)] string $operationId, #[Schema(minimum: 1, maximum: 100)] int $limit = 25, #[Schema(maxLength: 36)] ?string $cursor = null): array
    {
        return $this->result(fn (): array => $this->intake->listAnnotations($projectId, $executionId, $operationId, $limit, $cursor));
    }

    #[McpTool(name: 'xorgate_log_read', description: 'Read a verified bounded text window of an original artifact, such as a retained log. Offset is a byte offset and limit is at most 32768 bytes. The result reports next/previous offsets, eof, whether the window starts or ends mid-line and whether invalid UTF-8 was replaced. Use next_offset to page; whole logs are never returned at once.', outputSchema: self::OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function log(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::UUID)] string $executionId, #[Schema(definition: self::UUID)] string $artifactId, #[Schema(minimum: 0, maximum: 2_147_483_647)] int $offset = 0, #[Schema(minimum: 1, maximum: 32_768)] int $limit = 32_768): array
    {
        return $this->result(fn (): array => $this->intake->readLogWindow($projectId, $executionId, $artifactId, $offset, $limit));
    }

    #[McpTool(name: 'xorgate_artifact_get', description: 'Inspect immutable original artifact digest, size and availability within its owning project/execution. Missing, corrupt or expired bytes remain distinguishable after normalization. No raw storage path is exposed.', outputSchema: self::OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function artifact(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::UUID)] string $executionId, #[Schema(definition: self::UUID)] string $artifactId): array
    {
        return $this->result(fn (): array => $this->intake->getArtifact($projectId, $executionId, $artifactId));
    }

    #[McpTool(name: 'xorgate_artifact_read', description: 'Read a verified bounded original artifact chunk as base64. Offset is a byte offset and limit is at most 32768 bytes. Reassemble chunks using the returned cursor metadata; never interpret arbitrary binary report bytes as JSON text.', outputSchema: self::OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function read(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::UUID)] string $executionId, #[Schema(definition: self::UUID)] string $artifactId, #[Schema(minimum: 0, maximum: 2_147_483_647)] int $offset = 0, #[Schema(minimum: 1, maximum: 32_768)] int $limit = 32_768): array
    {
        return $this->result(fn (): array => $this->intake->readArtifact($projectId, $executionId, $artifactId, $offset, $limit));
    }

    #[McpTool(name: 'xorgate_intake_capacity', description: 'Inspect current installation-wide report admission, byte and retention limits. The shared bearer token does not identify distinct people. This read does not admit work or remove evidence.', outputSchema: self::OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function capacity(): array
    {
        return $this->result(fn (): array => $this->intake->capacity());
    }

    private function result(callable $operation): array
    {
        try {
            return $operation();
        } catch (\Throwable $exception) {
            return ArtifactResult::error($exception);
        }
    }
}

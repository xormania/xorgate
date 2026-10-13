<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Service\ProjectRegistry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

final class ProjectTools
{
    private const UUID = ['type' => 'string', 'maxLength' => 36, 'description' => 'Persistent UUID.'];
    private const OPTIONAL_REVISION = ['type' => ['string', 'null'], 'maxLength' => 256];
    private const OPTIONAL_IDENTITY = ['type' => ['string', 'null'], 'maxLength' => 160];
    private const OPTIONAL_NAME = ['type' => ['string', 'null'], 'maxLength' => 120];
    private const OPTIONAL_URL = ['type' => ['string', 'null'], 'maxLength' => 2048];
    private const RUNTIMES = ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'string', 'maxLength' => 80]];
    private const PROJECT_PROPERTIES = [
        'schema_version' => ['type' => 'integer', 'enum' => [1]],
        'request_id' => ['description' => 'Caller-supplied UUID idempotency key; reuse only for identical inputs.'] + self::UUID,
        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
        'repository_url' => self::OPTIONAL_URL,
        'repository_identity' => self::OPTIONAL_IDENTITY,
        'runtimes' => self::RUNTIMES,
    ];
    private const PROJECT_INPUT = [
        'type' => 'object',
        'properties' => self::PROJECT_PROPERTIES,
        'required' => ['request_id', 'name'],
        'additionalProperties' => false,
    ];
    private const PROJECT_UPDATE_INPUT = [
        'type' => 'object',
        'properties' => self::PROJECT_PROPERTIES + ['expected_version' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2_147_483_646]],
        'required' => ['request_id', 'name', 'expected_version'],
        'additionalProperties' => false,
    ];
    // MCP SDK 0.8.1 decodes JSON objects as associative arrays and loses the
    // distinction between nested {} and []. Permit only an empty array as the
    // equivalent unknown group; nonempty arrays remain invalid. Objects are preferred.
    private const EMPTY_GROUP = ['type' => 'array', 'maxItems' => 0];
    private const EXECUTION_INPUT = [
        'type' => 'object',
        'properties' => [
            'schema_version' => ['type' => 'integer', 'enum' => [1]],
            'request_id' => self::UUID,
            'source' => [
                'anyOf' => [
                    [
                        'type' => 'object',
                        'properties' => [
                            'repository_identity' => self::OPTIONAL_IDENTITY,
                            'repository_url' => self::OPTIONAL_URL,
                            'tested_revision' => self::OPTIONAL_REVISION,
                            'workspace_fingerprint' => self::OPTIONAL_REVISION,
                            'pr_head_revision' => self::OPTIONAL_REVISION,
                            'pr_base_revision' => self::OPTIONAL_REVISION,
                            'pr_merge_revision' => self::OPTIONAL_REVISION,
                        ],
                        'additionalProperties' => false,
                    ],
                    self::EMPTY_GROUP,
                ],
            ],
            'environment' => [
                'anyOf' => [
                    [
                        'type' => 'object',
                        'properties' => [
                            'name' => self::OPTIONAL_NAME,
                            'runtimes' => self::RUNTIMES,
                            'configuration_ref' => self::OPTIONAL_REVISION,
                        ],
                        'additionalProperties' => false,
                    ],
                    self::EMPTY_GROUP,
                ],
            ],
            'producer' => [
                'anyOf' => [
                    [
                        'type' => 'object',
                        'properties' => ['name' => self::OPTIONAL_NAME, 'version' => self::OPTIONAL_NAME],
                        'additionalProperties' => false,
                    ],
                    self::EMPTY_GROUP,
                ],
            ],
            'native_identity' => [
                'anyOf' => [
                    [
                        'type' => 'object',
                        'properties' => [
                            'provider' => ['type' => ['string', 'null'], 'maxLength' => 80],
                            'run_id' => self::OPTIONAL_IDENTITY,
                            'run_attempt' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 2_147_483_647],
                            'job_id' => self::OPTIONAL_IDENTITY,
                            'shard' => self::OPTIONAL_IDENTITY,
                        ],
                        'additionalProperties' => false,
                    ],
                    self::EMPTY_GROUP,
                ],
            ],
            'observed_at' => ['type' => ['string', 'null'], 'maxLength' => 64, 'description' => 'ISO 8601 observation timestamp with explicit timezone; null when unknown.'],
        ],
        'required' => ['request_id'],
        'additionalProperties' => false,
    ];
    private const ERROR_OUTPUT = [
        'type' => 'object',
        'properties' => [
            'schema_version' => ['const' => 1],
            'accepted' => ['const' => false],
            'error' => [
                'type' => 'object',
                'properties' => ['code' => ['type' => 'string'], 'message' => ['type' => 'string']],
                'required' => ['code', 'message'],
            ],
        ],
        'required' => ['schema_version', 'accepted', 'error'],
    ];
    private const PROJECT_OUTPUT = [
        'type' => 'object',
        'oneOf' => [
            [
                'type' => 'object',
                'properties' => [
                    'schema_version' => ['const' => 1],
                    'project_id' => self::UUID,
                    'name' => ['type' => 'string'],
                    'repository_url' => self::OPTIONAL_URL,
                    'repository_identity' => self::OPTIONAL_IDENTITY,
                    'runtimes' => self::RUNTIMES,
                    'version' => ['type' => 'integer'],
                    'created_at' => ['type' => 'string'],
                    'updated_at' => ['type' => 'string'],
                ],
                'required' => ['schema_version', 'project_id', 'name', 'repository_url', 'repository_identity', 'runtimes', 'version', 'created_at', 'updated_at'],
            ],
            self::ERROR_OUTPUT,
        ],
    ];
    private const EXECUTION_OUTPUT = [
        'type' => 'object',
        'oneOf' => [
            [
                'type' => 'object',
                'properties' => [
                    'schema_version' => ['const' => 1],
                    'execution_id' => self::UUID,
                    'project_id' => self::UUID,
                    'source' => ['type' => 'object'],
                    'environment' => ['type' => 'object'],
                    'producer' => ['type' => 'object'],
                    'native_identity' => ['type' => 'object'],
                    'observed_at' => ['type' => ['string', 'null']],
                    'created_at' => ['type' => 'string'],
                ],
                'required' => ['schema_version', 'execution_id', 'project_id', 'source', 'environment', 'producer', 'native_identity', 'observed_at', 'created_at'],
            ],
            self::ERROR_OUTPUT,
        ],
    ];
    private const PAGE_OUTPUT = [
        'type' => 'object',
        'oneOf' => [
            [
                'type' => 'object',
                'properties' => [
                    'schema_version' => ['const' => 1],
                    'items' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object']],
                    'next_cursor' => ['type' => ['string', 'null']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ],
                'required' => ['schema_version', 'items', 'next_cursor', 'limit'],
            ],
            self::ERROR_OUTPUT,
        ],
    ];

    public function __construct(private readonly ProjectRegistry $registry)
    {
    }

    #[McpTool(name: 'xorgate_project_register', description: 'Register a language-neutral managed project. Supply input.request_id UUID for idempotency. Repeating identical inputs returns the original response; changing inputs under that key returns conflict. Repository metadata does not determine project identity.', outputSchema: self::PROJECT_OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function register(#[Schema(definition: self::PROJECT_INPUT)] array $input): array
    {
        return $this->result(fn (): array => $this->registry->register($input));
    }

    #[McpTool(name: 'xorgate_project_update', description: 'Replace project metadata while preserving its UUID and prior execution snapshots. Supply input.request_id and input.expected_version; omitted optional metadata is cleared. A stale version or changed replay returns conflict.', outputSchema: self::PROJECT_OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function update(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::PROJECT_UPDATE_INPUT)] array $input): array
    {
        return $this->result(fn (): array => $this->registry->update($projectId, $input));
    }

    #[McpTool(name: 'xorgate_project_get', description: 'Retrieve the current project record by its stable project UUID.', outputSchema: self::PROJECT_OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function get(#[Schema(definition: self::UUID)] string $projectId): array
    {
        return $this->result(fn (): array => $this->registry->get($projectId));
    }

    #[McpTool(name: 'xorgate_project_list', description: 'Retrieve a bounded UUID cursor page of stored projects. Pass next_cursor as cursor for the next page; there are no invented counts.', outputSchema: self::PAGE_OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function list(#[Schema(minimum: 1, maximum: 100)] int $limit = 25, #[Schema(maxLength: 36)] ?string $cursor = null): array
    {
        return $this->result(fn (): array => $this->registry->list($limit, $cursor));
    }

    #[McpTool(name: 'xorgate_execution_record', description: 'Record immutable execution provenance for a project, without launching tests. Supply input.request_id UUID. Tested revision, workspace fingerprint and PR head/base/merge revisions remain separate. Provider run ID, run attempt, job and shard remain separate; case retry belongs to later evidence ingestion. Unknown metadata remains null. Omitted source repository fields snapshot current project metadata, explicit null stays unknown.', outputSchema: self::EXECUTION_OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function recordExecution(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::EXECUTION_INPUT)] array $input): array
    {
        return $this->result(fn (): array => $this->registry->recordExecution($projectId, $input));
    }

    #[McpTool(name: 'xorgate_execution_get', description: 'Retrieve immutable execution provenance by UUID within its project. A UUID from another project cannot be attached or read under this project.', outputSchema: self::EXECUTION_OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function getExecution(#[Schema(definition: self::UUID)] string $projectId, #[Schema(definition: self::UUID)] string $executionId): array
    {
        return $this->result(fn (): array => $this->registry->getExecution($projectId, $executionId));
    }

    #[McpTool(name: 'xorgate_execution_list', description: 'Retrieve a bounded UUID cursor page of immutable execution records for one project. Reuse only a cursor from the same project.', outputSchema: self::PAGE_OUTPUT)]
    #[Schema(additionalProperties: false)]
    public function listExecutions(#[Schema(definition: self::UUID)] string $projectId, #[Schema(minimum: 1, maximum: 100)] int $limit = 25, #[Schema(maxLength: 36)] ?string $cursor = null): array
    {
        return $this->result(fn (): array => $this->registry->listExecutions($projectId, $limit, $cursor));
    }

    private function result(callable $operation): array
    {
        try {
            return $operation();
        } catch (\Throwable $exception) {
            return RegistryResult::error($exception);
        }
    }
}

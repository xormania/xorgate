<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\RegistryCapacityException;
use App\Exception\RegistryConflictException;
use App\Exception\RegistryNotFoundException;
use App\Service\ProjectRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[Route('/projects')]
final class ProjectController extends AbstractController
{
    private const UUID_ROUTE = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly ProjectRegistry $registry,
        private readonly FormFactoryInterface $forms,
    ) {
    }

    #[Route('', name: 'app_projects', methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            [$limit, $cursor] = $this->pagination($request);
            $projects = $this->registry->list($limit, $cursor);
        } catch (\InvalidArgumentException|RegistryNotFoundException|RegistryConflictException|RegistryCapacityException $error) {
            return $this->registryError($error);
        }

        return $this->render('projects/index.html.twig', [
            'projects' => $projects,
            'cursor' => $cursor,
        ]);
    }

    #[Route('/new', name: 'app_project_new', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $form = $this->projectForm();
        $form->handleRequest($request);
        $status = $this->formStatus($form, $request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $project = $this->registry->register($this->projectInput($form));
                $this->addFlash('success', 'Project registered.');

                return $this->redirectToRoute('app_project_show', ['projectId' => $project['project_id']], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException|RegistryNotFoundException|RegistryConflictException|RegistryCapacityException $error) {
                $form->addError(new FormError($error->getMessage()));
                $status = $this->formErrorStatus($error);
            }
        }

        return $this->render('projects/project_form.html.twig', [
            'form' => $form->createView(),
            'project' => null,
        ], new Response(null, $status));
    }

    #[Route('/{projectId}', name: 'app_project_show', requirements: ['projectId' => self::UUID_ROUTE], methods: ['GET'])]
    public function show(string $projectId, Request $request): Response
    {
        try {
            $project = $this->registry->get($projectId);
            [$limit, $cursor] = $this->pagination($request);
            $executions = $this->registry->listExecutions($projectId, $limit, $cursor);
        } catch (\InvalidArgumentException|RegistryNotFoundException|RegistryConflictException|RegistryCapacityException $error) {
            return $this->registryError($error);
        }

        return $this->render('projects/show.html.twig', [
            'project' => $project,
            'executions' => $executions,
            'cursor' => $cursor,
        ]);
    }

    #[Route('/{projectId}/edit', name: 'app_project_edit', requirements: ['projectId' => self::UUID_ROUTE], methods: ['GET', 'POST'])]
    public function edit(string $projectId, Request $request): Response
    {
        try {
            $project = $this->registry->get($projectId);
        } catch (\InvalidArgumentException|RegistryNotFoundException|RegistryConflictException|RegistryCapacityException $error) {
            return $this->registryError($error);
        }

        $form = $this->projectForm($project);
        $form->handleRequest($request);
        $status = $this->formStatus($form, $request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $input = $this->projectInput($form);
                $version = filter_var($form->get('expected_version')->getData(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (false === $version) {
                    throw new \InvalidArgumentException('The project version must be a positive integer.');
                }
                $input['expected_version'] = $version;
                $this->registry->update($projectId, $input);
                $this->addFlash('success', 'Project metadata updated.');

                return $this->redirectToRoute('app_project_show', ['projectId' => $projectId], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException|RegistryNotFoundException|RegistryConflictException|RegistryCapacityException $error) {
                $form->addError(new FormError($error->getMessage()));
                $status = $this->formErrorStatus($error);
            }
        }

        return $this->render('projects/project_form.html.twig', [
            'form' => $form->createView(),
            'project' => $project,
        ], new Response(null, $status));
    }

    #[Route('/{projectId}/executions/new', name: 'app_execution_new', requirements: ['projectId' => self::UUID_ROUTE], methods: ['GET', 'POST'])]
    public function recordExecution(string $projectId, Request $request): Response
    {
        try {
            $project = $this->registry->get($projectId);
        } catch (\InvalidArgumentException|RegistryNotFoundException|RegistryConflictException|RegistryCapacityException $error) {
            return $this->registryError($error);
        }

        $form = $this->executionForm($project);
        $form->handleRequest($request);
        $status = $this->formStatus($form, $request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $input = $form->getData();
                $input['schema_version'] = 1;
                foreach (['source', 'environment', 'producer', 'native_identity'] as $group) {
                    foreach ($input[$group] as $key => $value) {
                        $input[$group][$key] = is_string($value) ? $this->nullableText($value) : $value;
                    }
                }
                $runtimes = $this->runtimeDeclarations($form->get('environment')->get('runtimes')->getData());
                $input['environment']['runtimes'] = $runtimes;
                $attempt = $input['native_identity']['run_attempt'];
                if (null !== $attempt) {
                    $attempt = filter_var($attempt, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
                    if (false === $attempt) {
                        throw new \InvalidArgumentException('Run attempt must be a whole number between 1 and 2147483647.');
                    }
                }
                $input['native_identity']['run_attempt'] = $attempt;
                $input['observed_at'] = $this->nullableText($input['observed_at']);
                $execution = $this->registry->recordExecution($projectId, $input);
                $this->addFlash('success', 'Execution context recorded.');

                return $this->redirectToRoute('app_execution_show', [
                    'projectId' => $projectId,
                    'executionId' => $execution['execution_id'],
                ], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException|RegistryNotFoundException|RegistryConflictException|RegistryCapacityException $error) {
                $form->addError(new FormError($error->getMessage()));
                $status = $this->formErrorStatus($error);
            }
        }

        return $this->render('projects/execution_form.html.twig', [
            'form' => $form->createView(),
            'project' => $project,
        ], new Response(null, $status));
    }

    #[Route('/{projectId}/executions/{executionId}', name: 'app_execution_show', requirements: ['projectId' => self::UUID_ROUTE, 'executionId' => self::UUID_ROUTE], methods: ['GET'])]
    public function execution(string $projectId, string $executionId): Response
    {
        try {
            $project = $this->registry->get($projectId);
            $execution = $this->registry->getExecution($projectId, $executionId);
        } catch (\InvalidArgumentException|RegistryNotFoundException|RegistryConflictException|RegistryCapacityException $error) {
            return $this->registryError($error);
        }

        return $this->render('projects/execution.html.twig', [
            'project' => $project,
            'execution' => $execution,
        ]);
    }

    private function projectForm(?array $project = null): FormInterface
    {
        $data = [
            'request_id' => Uuid::v7()->toRfc4122(),
            'name' => $project['name'] ?? '',
            'repository_url' => $project['repository_url'] ?? null,
            'repository_identity' => $project['repository_identity'] ?? null,
            'runtimes' => implode("\n", $project['runtimes'] ?? []),
        ];
        if (null !== $project) {
            $data['expected_version'] = (string) $project['version'];
        }

        $builder = $this->forms->createNamedBuilder('project', FormType::class, $data, [
            'method' => 'POST',
            'action' => null === $project ? $this->generateUrl('app_project_new') : $this->generateUrl('app_project_edit', ['projectId' => $project['project_id']]),
            'csrf_protection' => true,
            'csrf_token_id' => null === $project ? 'project_register' : 'project_update_'.$project['project_id'],
        ]);
        $this->requestIdField($builder);
        $builder->add('name', TextType::class, [
            'label' => 'Project name',
            'attr' => ['maxlength' => 120, 'autocomplete' => 'off'],
            'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)],
        ]);
        $this->textField($builder, 'repository_url', 'Repository URL', 2048, 'Optional metadata. Xorgate does not fetch the repository.');
        $this->textField($builder, 'repository_identity', 'Repository identity', 160, 'Optional stable identity used to correlate source evidence.');
        $this->runtimeField($builder, 'runtimes', 'Project runtimes');
        if (null !== $project) {
            $builder->add('expected_version', HiddenType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Regex('/^[1-9][0-9]*$/')],
            ]);
        }

        return $builder->getForm();
    }

    private function formStatus(FormInterface $form, Request $request): int
    {
        if (!$request->isMethod('POST')) {
            return Response::HTTP_OK;
        }
        if (!$form->isSubmitted()) {
            $form->addError(new FormError('Submit the displayed form fields.'));
        }

        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    private function executionForm(array $project): FormInterface
    {
        $builder = $this->forms->createNamedBuilder('execution', FormType::class, [
            'request_id' => Uuid::v7()->toRfc4122(),
            'source' => [
                'repository_identity' => $project['repository_identity'],
                'repository_url' => $project['repository_url'],
            ],
            'environment' => ['runtimes' => ''],
        ], [
            'method' => 'POST',
            'action' => $this->generateUrl('app_execution_new', ['projectId' => $project['project_id']]),
            'csrf_protection' => true,
            'csrf_token_id' => 'execution_record_'.$project['project_id'],
        ]);
        $this->requestIdField($builder);
        $source = $builder->create('source', FormType::class, ['label' => 'Source context', 'required' => false]);
        $this->textField($source, 'repository_identity', 'Repository identity', 160, 'Must match the project identity when known.');
        $this->textField($source, 'repository_url', 'Repository URL', 2048);
        $this->textField($source, 'tested_revision', 'Actual tested revision', 256, 'The reported revision actually tested, including a synthetic PR merge revision when applicable.');
        $this->textField($source, 'workspace_fingerprint', 'Workspace fingerprint', 256, 'A reported identity for a dirty or otherwise non-reproducible workspace.');
        $this->textField($source, 'pr_head_revision', 'PR head revision', 256);
        $this->textField($source, 'pr_base_revision', 'PR base revision', 256);
        $this->textField($source, 'pr_merge_revision', 'PR merge revision', 256);
        $builder->add($source);

        $environment = $builder->create('environment', FormType::class, ['label' => 'Environment', 'required' => false]);
        $this->textField($environment, 'name', 'Environment name', 120);
        $this->runtimeField($environment, 'runtimes', 'Observed runtimes');
        $this->textField($environment, 'configuration_ref', 'Configuration reference', 256);
        $builder->add($environment);

        $producer = $builder->create('producer', FormType::class, ['label' => 'Producer', 'required' => false]);
        $this->textField($producer, 'name', 'Producer name', 120);
        $this->textField($producer, 'version', 'Producer version', 120);
        $builder->add($producer);

        $native = $builder->create('native_identity', FormType::class, [
            'label' => 'Native execution identity',
            'help' => 'Provider identifiers are observational metadata. Run attempts remain distinct.',
            'required' => false,
        ]);
        $this->textField($native, 'provider', 'Provider', 80);
        $this->textField($native, 'run_id', 'Run ID', 160);
        // IntegerType rounds fractional input by default; provenance must preserve an exact integer.
        $native->add('run_attempt', TextType::class, [
            'label' => 'Run attempt',
            'required' => false,
            'help' => 'A whole number between 1 and 2147483647, or blank when unknown.',
            'attr' => ['inputmode' => 'numeric', 'maxlength' => 10],
            'constraints' => [
                new Assert\Length(max: 10),
                new Assert\Regex(pattern: '/^[1-9][0-9]*$/D', message: 'Use a whole number greater than zero.'),
            ],
        ]);
        $this->textField($native, 'job_id', 'Job ID', 160);
        $this->textField($native, 'shard', 'Shard', 160);
        $builder->add($native);
        $this->textField($builder, 'observed_at', 'Observed at', 64, 'Optional ISO 8601 timestamp with timezone, for example 2026-10-13T12:00:00Z. Leave unknown values blank.');

        return $builder->getForm();
    }

    private function requestIdField(FormBuilderInterface $builder): void
    {
        $builder->add('request_id', HiddenType::class, [
            'constraints' => [new Assert\NotBlank(), new Assert\Uuid()],
        ]);
    }

    private function textField(FormBuilderInterface $builder, string $name, string $label, ?int $max = null, ?string $help = null): void
    {
        $builder->add($name, TextType::class, [
            'label' => $label,
            'required' => false,
            'help' => $help,
            'attr' => null === $max ? [] : ['maxlength' => $max],
            'constraints' => null === $max ? [] : [new Assert\Length(max: $max)],
        ]);
    }

    private function runtimeField(FormBuilderInterface $builder, string $name, string $label): void
    {
        $builder->add($name, TextareaType::class, [
            'label' => $label,
            'required' => false,
            'help' => 'One runtime per line, up to eight entries of 80 characters each. Any language or mixed runtime is supported. Blank means unknown.',
            'attr' => ['rows' => 3],
        ]);
    }

    private function projectInput(FormInterface $form): array
    {
        $data = $form->getData();

        return [
            'schema_version' => 1,
            'request_id' => $data['request_id'],
            'name' => $data['name'],
            'repository_url' => $this->nullableText($data['repository_url']),
            'repository_identity' => $this->nullableText($data['repository_identity']),
            'runtimes' => $this->runtimeDeclarations($data['runtimes']),
        ];
    }

    private function nullableText(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }

    private function runtimeDeclarations(?string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value ?? '') ?: []), static fn (string $runtime): bool => '' !== $runtime));
    }

    private function pagination(Request $request): array
    {
        $query = $request->query->all();
        $limit = $query['limit'] ?? '25';
        $cursor = $query['cursor'] ?? null;
        if (!is_string($limit) || !preg_match('/^[1-9][0-9]{0,2}$/', $limit) || (int) $limit > 100) {
            throw new \InvalidArgumentException('The page limit must be an integer between 1 and 100.');
        }
        if (null !== $cursor && (!is_string($cursor) || !Uuid::isValid($cursor))) {
            throw new \InvalidArgumentException('The page cursor must be a UUID.');
        }

        return [(int) $limit, $cursor];
    }

    private function registryError(\InvalidArgumentException|RegistryNotFoundException|RegistryConflictException|RegistryCapacityException $error): Response
    {
        return $this->render('projects/error.html.twig', [
            'message' => $error->getMessage(),
            'status_code' => $this->errorStatus($error),
        ], new Response(null, $this->errorStatus($error)));
    }

    private function errorStatus(\InvalidArgumentException|RegistryNotFoundException|RegistryConflictException|RegistryCapacityException $error): int
    {
        return match (true) {
            $error instanceof RegistryNotFoundException => Response::HTTP_NOT_FOUND,
            $error instanceof RegistryConflictException => Response::HTTP_CONFLICT,
            $error instanceof RegistryCapacityException => Response::HTTP_TOO_MANY_REQUESTS,
            default => Response::HTTP_BAD_REQUEST,
        };
    }

    private function formErrorStatus(\InvalidArgumentException|RegistryNotFoundException|RegistryConflictException|RegistryCapacityException $error): int
    {
        return $error instanceof \InvalidArgumentException ? Response::HTTP_UNPROCESSABLE_ENTITY : $this->errorStatus($error);
    }
}

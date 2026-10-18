<?php

declare(strict_types=1);

namespace App\Controller\Artifact;

use App\Exception\IntakeCapacityException;
use App\Exception\RegistryConflictException;
use App\Exception\RegistryNotFoundException;
use App\Form\ReportUploadType;
use App\Service\ProjectRegistry;
use App\Service\ReportIntake;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/projects/{projectId}/executions/{executionId}', requirements: ['projectId' => self::UUID_ROUTE, 'executionId' => self::UUID_ROUTE])]
final class ReportIntakeController extends AbstractController
{
    private const UUID_ROUTE = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(private readonly ProjectRegistry $registry, private readonly ReportIntake $intake)
    {
    }

    #[Route('/reports/new', name: 'app_report_upload', methods: ['GET', 'POST'])]
    public function upload(string $projectId, string $executionId, Request $request): Response
    {
        try {
            $context = $this->context($projectId, $executionId);
            $capacity = $this->intake->capacity();
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return $this->errorResponse($error);
        }

        $maxBytes = $capacity['limits']['upload_bytes'];
        $form = $this->createForm(ReportUploadType::class, [
            'request_id' => Uuid::v7()->toRfc4122(),
            'report_role' => 'junit',
            'format' => 'junit',
            'source' => $context['execution']['source'],
        ], [
            'method' => 'POST',
            'action' => $this->generateUrl('app_report_upload', ['projectId' => $projectId, 'executionId' => $executionId]),
            'csrf_token_id' => 'report_upload_'.$executionId,
            'max_bytes' => $maxBytes,
            'formats' => array_keys($this->intake->formats()),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        if ($request->isMethod('POST') && !$form->isSubmitted()) {
            $form->addError(new FormError('Submit the displayed form and select a report file.'));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $stream = null;
            try {
                $file = $form->get('report')->getData();
                if (!$file instanceof UploadedFile) {
                    throw new \InvalidArgumentException('Select a report file.');
                }
                $metadata = $form->getData();
                $metadata['schema_version'] = 1;
                foreach ($metadata['source'] as $field => $value) {
                    $metadata['source'][$field] = null === $value || '' === trim($value) ? null : trim($value);
                }
                $metadata['filename'] = $file->getClientOriginalName();
                $metadata['media_type'] = $file->getClientMimeType();
                $stream = @fopen($file->getPathname(), 'rb');
                if (false === $stream) {
                    throw new \RuntimeException('The uploaded report could not be opened.');
                }
                $operation = $this->intake->submit($projectId, $executionId, $metadata, $stream);
                $this->addFlash('info', 'Report accepted for asynchronous intake.');

                return $this->redirectToRoute('app_intake_operation', [
                    'projectId' => $projectId,
                    'executionId' => $executionId,
                    'operationId' => $operation['operation_id'],
                ], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException|\RuntimeException $error) {
                if ($error instanceof IntakeCapacityException) {
                    $capacity = $error->capacity;
                }
                $form->addError(new FormError($this->safeMessage($error)));
                $status = $error instanceof \InvalidArgumentException ? Response::HTTP_UNPROCESSABLE_ENTITY : $this->errorStatus($error);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return $this->render('reports/upload.html.twig', $context + [
            'form' => $form->createView(),
            'max_bytes' => $maxBytes,
            'capacity' => $capacity,
        ], new Response(null, $status));
    }

    #[Route('/evidence', name: 'app_execution_evidence', methods: ['GET'])]
    public function evidence(string $projectId, string $executionId, Request $request): Response
    {
        try {
            $context = $this->context($projectId, $executionId);
            [$limit, $cursor] = $this->pagination($request);
            $evidence = $this->intake->listEvidence($projectId, $executionId, $limit, $cursor);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return $this->errorResponse($error);
        }

        return $this->render('reports/evidence.html.twig', $context + ['evidence' => $evidence, 'cursor' => $cursor]);
    }

    #[Route('/intake/{operationId}', name: 'app_intake_operation', requirements: ['operationId' => self::UUID_ROUTE], methods: ['GET'])]
    public function operation(string $projectId, string $executionId, string $operationId, Request $request): Response
    {
        try {
            $context = $this->context($projectId, $executionId);
            $operation = $this->intake->getOperation($projectId, $executionId, $operationId);
            [$limit, $cursor] = $this->pagination($request);
            $observations = $this->intake->listObservations($projectId, $executionId, $operationId, $limit, $cursor);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return $this->errorResponse($error);
        }

        return $this->render('reports/operation.html.twig', $context + [
            'operation' => $operation,
            'observations' => $observations,
            'cursor' => $cursor,
        ]);
    }

    #[Route('/intake/{operationId}/attempts', name: 'app_intake_attempts', requirements: ['operationId' => self::UUID_ROUTE], methods: ['GET'])]
    public function attempts(string $projectId, string $executionId, string $operationId, Request $request): Response
    {
        try {
            $context = $this->context($projectId, $executionId);
            $operation = $this->intake->getOperation($projectId, $executionId, $operationId);
            [$limit, $cursor] = $this->pagination($request);
            $attempts = $this->intake->listAttempts($projectId, $executionId, $operationId, $limit, $cursor);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return $this->errorResponse($error);
        }

        return $this->render('reports/attempts.html.twig', $context + ['operation' => $operation, 'attempts' => $attempts, 'cursor' => $cursor]);
    }

    #[Route('/intake/{operationId}/annotations', name: 'app_intake_annotations', requirements: ['operationId' => self::UUID_ROUTE], methods: ['GET'])]
    public function annotations(string $projectId, string $executionId, string $operationId, Request $request): Response
    {
        try {
            $context = $this->context($projectId, $executionId);
            $operation = $this->intake->getOperation($projectId, $executionId, $operationId);
            [$limit, $cursor] = $this->pagination($request);
            $annotations = $this->intake->listAnnotations($projectId, $executionId, $operationId, $limit, $cursor);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return $this->errorResponse($error);
        }

        return $this->render('reports/annotations.html.twig', $context + ['operation' => $operation, 'annotations' => $annotations, 'cursor' => $cursor]);
    }

    #[Route('/artifacts/{artifactId}/log', name: 'app_report_log', requirements: ['artifactId' => self::UUID_ROUTE], methods: ['GET'])]
    public function log(string $projectId, string $executionId, string $artifactId, Request $request): Response
    {
        try {
            $context = $this->context($projectId, $executionId);
            $offset = $this->byteQuery($request, 'offset', '0', 0, 2147483647);
            $limit = $this->byteQuery($request, 'limit', (string) ReportIntake::LOG_WINDOW_MAXIMUM, 1, ReportIntake::LOG_WINDOW_MAXIMUM);
            $window = $this->intake->readLogWindow($projectId, $executionId, $artifactId, $offset, $limit);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return $this->errorResponse($error);
        }

        // Twig escapes markup. C0 controls such as terminal escapes are shown as control pictures; C1 controls and
        // bidirectional controls (ALM, LRM/RLM, embeddings, overrides, isolates), which could reorder the displayed
        // text, are shown as their code points.
        $display = preg_replace_callback('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', static function (array $match): string {
            $code = mb_ord($match[0], 'UTF-8');

            return match (true) {
                0x7F === $code => "\u{2421}",
                $code < 0x20 => mb_chr(0x2400 + $code, 'UTF-8'),
                default => sprintf('<U+%04X>', $code),
            };
        }, $window['content_text']);
        if (null === $display) {
            return $this->errorResponse(new \RuntimeException('The log window could not be prepared for display.'));
        }

        return $this->render('reports/log.html.twig', $context + ['window' => $window, 'limit' => $limit, 'display_text' => $display]);
    }

    #[Route('/artifacts/{artifactId}', name: 'app_report_artifact', requirements: ['artifactId' => self::UUID_ROUTE], methods: ['GET'])]
    public function artifact(string $projectId, string $executionId, string $artifactId): Response
    {
        try {
            $context = $this->context($projectId, $executionId);
            $artifact = $this->intake->getArtifact($projectId, $executionId, $artifactId);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return $this->errorResponse($error);
        }

        return $this->render('reports/artifact.html.twig', $context + ['artifact' => $artifact]);
    }

    #[Route('/artifacts/{artifactId}/download', name: 'app_report_download', requirements: ['artifactId' => self::UUID_ROUTE], methods: ['GET'])]
    public function download(string $projectId, string $executionId, string $artifactId): Response
    {
        try {
            $artifact = $this->intake->getArtifact($projectId, $executionId, $artifactId);
            $stream = $this->intake->openArtifact($projectId, $executionId, $artifactId);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return $this->errorResponse($error);
        }

        $response = new StreamedResponse(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        });
        $response->headers->set('Content-Type', 'application/octet-stream');
        // The client filename is display metadata; only a short alphanumeric extension is reused.
        $extension = null !== $artifact['filename'] && preg_match('/\.([A-Za-z0-9]{1,8})\z/', $artifact['filename'], $match) ? strtolower($match[1]) : 'bin';
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $artifact['artifact_id'].'.'.$extension));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function context(string $projectId, string $executionId): array
    {
        return ['project' => $this->registry->get($projectId), 'execution' => $this->registry->getExecution($projectId, $executionId)];
    }

    private function pagination(Request $request): array
    {
        $query = $request->query->all();
        $limit = $query['limit'] ?? '25';
        $cursor = $query['cursor'] ?? null;
        if (!is_string($limit) || !preg_match('/^[1-9][0-9]{0,2}$/D', $limit) || (int) $limit > 100) {
            throw new \InvalidArgumentException('The page limit must be between 1 and 100.');
        }
        if (null !== $cursor && (!is_string($cursor) || !Uuid::isValid($cursor))) {
            throw new \InvalidArgumentException('The page cursor must be a UUID.');
        }

        return [(int) $limit, $cursor];
    }

    private function byteQuery(Request $request, string $name, string $default, int $minimum, int $maximum): int
    {
        $value = $request->query->all()[$name] ?? $default;
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $value) || (int) $value < $minimum || (int) $value > $maximum) {
            throw new \InvalidArgumentException('The '.$name.' must be an integer from '.$minimum.' to '.$maximum.'.');
        }

        return (int) $value;
    }

    private function errorResponse(\InvalidArgumentException|\RuntimeException $error): Response
    {
        return $this->render('reports/error.html.twig', [
            'message' => $this->safeMessage($error),
            'status_code' => $this->errorStatus($error),
        ], new Response(null, $this->errorStatus($error)));
    }

    private function safeMessage(\InvalidArgumentException|\RuntimeException $error): string
    {
        return $error instanceof \InvalidArgumentException || $error instanceof RegistryConflictException || $error instanceof RegistryNotFoundException || $error instanceof IntakeCapacityException
            ? $error->getMessage()
            : 'Report intake is unavailable. Check application readiness and retry.';
    }

    private function errorStatus(\InvalidArgumentException|\RuntimeException $error): int
    {
        return match (true) {
            $error instanceof \InvalidArgumentException => Response::HTTP_BAD_REQUEST,
            $error instanceof RegistryNotFoundException => Response::HTTP_NOT_FOUND,
            $error instanceof RegistryConflictException => Response::HTTP_CONFLICT,
            $error instanceof IntakeCapacityException => Response::HTTP_TOO_MANY_REQUESTS,
            default => Response::HTTP_SERVICE_UNAVAILABLE,
        };
    }
}

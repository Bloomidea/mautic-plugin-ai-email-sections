<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Controller\AjaxController;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Model\AbstractCommonModel;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use MauticPlugin\AiEmailSectionsBundle\Entity\Generation;
use MauticPlugin\AiEmailSectionsBundle\Entity\GenerationRepository;
use MauticPlugin\AiEmailSectionsBundle\Exception\GenerationFailedException;
use MauticPlugin\AiEmailSectionsBundle\Exception\LlmUnavailableException;
use MauticPlugin\AiEmailSectionsBundle\Integration\Config;
use MauticPlugin\AiEmailSectionsBundle\Service\GeneratorFactory;
use MauticPlugin\AiEmailSectionsBundle\Service\PromptBuilder;
use MauticPlugin\AiEmailSectionsBundle\Service\ThemeCatalog;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class GenerateController extends AjaxController
{
    private const MAX_PROMPT_LENGTH = 2000;

    /**
     * @param ModelFactory<AbstractCommonModel<object>> $modelFactory
     */
    public function __construct(
        ManagerRegistry $doctrine,
        ModelFactory $modelFactory,
        UserHelper $userHelper,
        CoreParametersHelper $coreParametersHelper,
        EventDispatcherInterface $dispatcher,
        Translator $translator,
        FlashBag $flashBag,
        RequestStack $requestStack,
        CorePermissions $security,
        private LoggerInterface $logger,
    ) {
        parent::__construct($doctrine, $modelFactory, $userHelper, $coreParametersHelper, $dispatcher, $translator, $flashBag, $requestStack, $security);
    }

    public function generateAction(
        Request $request,
        Config $config,
        GeneratorFactory $generatorFactory,
        CorePermissions $permissions,
        UserHelper $userHelper,
        CsrfTokenManagerInterface $csrfTokenManager,
        EntityManagerInterface $entityManager,
        ThemeCatalog $themeCatalog,
    ): JsonResponse {
        // Mautic's global CSRF guard only runs when the request carries the
        // X-Requested-With header, and returns 200 on failure. Here it is explicit
        // and comes before everything else.
        $token = (string) $request->headers->get('X-CSRF-Token', '');

        if (!$csrfTokenManager->isTokenValid(new CsrfToken('mautic_ajax_post', $token))) {
            return $this->error('csrf', 'mautic.aiemailsections.error.session_expired', Response::HTTP_FORBIDDEN);
        }

        if (!$config->isPublished()) {
            return $this->error('disabled', 'mautic.aiemailsections.error.disabled', Response::HTTP_FORBIDDEN);
        }

        if (!$permissions->isAdmin() && !$permissions->isGranted('aiemailsections:generations:create')) {
            return $this->error('forbidden', 'mautic.aiemailsections.error.forbidden', Response::HTTP_FORBIDDEN);
        }

        // No guard against a malformed body here: FOSRestBundle's BodyListener
        // decodes the JSON on kernel.request and answers a 400 of its own long
        // before this runs, so toArray() cannot fail on it. A try/catch around
        // it looks prudent and is unreachable.
        $payload = $request->toArray();

        $mode   = PromptBuilder::MODE_EDIT === ($payload['mode'] ?? '') ? PromptBuilder::MODE_EDIT : PromptBuilder::MODE_CREATE;
        $prompt = trim((string) ($payload['prompt'] ?? ''));
        $source = isset($payload['source']) ? trim((string) $payload['source']) : null;

        if ('' === $prompt) {
            return $this->error('bad_request', 'mautic.aiemailsections.error.empty', Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
            return $this->error('bad_request', 'mautic.aiemailsections.error.prompt_too_long', Response::HTTP_BAD_REQUEST);
        }

        if (PromptBuilder::MODE_EDIT === $mode) {
            if (null === $source || '' === $source) {
                return $this->error('bad_request', 'mautic.aiemailsections.error.no_source', Response::HTTP_BAD_REQUEST);
            }

            if (strlen($source) > $config->getMaxSourceBytes()) {
                return $this->error(
                    'source_too_large',
                    'mautic.aiemailsections.error.source_too_large',
                    Response::HTTP_BAD_REQUEST
                );
            }
        } else {
            $source = null;
        }

        $user   = $userHelper->getUser();
        $userId = $user?->getId();

        /** @var GenerationRepository $repository */
        $repository = $entityManager->getRepository(Generation::class);

        if (null !== $userId && $this->overRateLimit($repository, $userId, $config)) {
            return $this->error(
                'rate_limited',
                'mautic.aiemailsections.error.rate_limited',
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        // The builder panel offers a theme per generation, so a section can match
        // the email it is being inserted into. An unknown id falls back to the
        // configured one rather than failing: the payload is not trusted, and a
        // theme can be removed while a browser tab still offers it.
        $themeId = $themeCatalog->resolve(
            isset($payload['theme']) && is_string($payload['theme']) ? $payload['theme'] : null,
            $config->getThemeId()
        );
        $emailId = isset($payload['emailId']) && is_numeric($payload['emailId']) ? (int) $payload['emailId'] : null;

        $record = (new Generation())
            ->setCreatedBy($userId)
            ->setEmailId($emailId)
            ->setThemeId($themeId)
            ->setMode($mode)
            ->setPrompt($prompt)
            ->setSourceMjml($source)
            ->setModel($config->getModel());

        try {
            $outcome = $generatorFactory->create()->generate($mode, $prompt, $source, $themeId);
        } catch (GenerationFailedException $exception) {
            $this->persist($entityManager, $record
                ->setStatus(Generation::STATUS_FAILED)
                ->setAttempts($exception->getAttempts())
                ->setValidationErrors($exception->getValidationErrors()));

            return $this->error(
                'validation_failed',
                'mautic.aiemailsections.error.validation_failed',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (LlmUnavailableException $exception) {
            $this->persist($entityManager, $record
                ->setStatus(Generation::STATUS_FAILED)
                ->setValidationErrors([$exception->getMessage()]));

            return $this->error(
                'provider_unavailable',
                'mautic.aiemailsections.error.provider_unavailable',
                Response::HTTP_BAD_GATEWAY
            );
        }

        $this->persist($entityManager, $record
            ->setStatus(Generation::STATUS_OK)
            ->setAttempts($outcome->attempts)
            ->setRawResponse($outcome->lastRawResponse())
            ->setFinalMjml($outcome->mjml)
            ->setWarnings($outcome->warnings)
            ->setLatencyMs($outcome->latencyMs));

        return new JsonResponse([
            'mjml'     => $outcome->mjml,
            'attempts' => $outcome->attempts,
            'warnings' => $outcome->warnings,
        ]);
    }

    private function overRateLimit(GenerationRepository $repository, int $userId, Config $config): bool
    {
        $limit = $config->getRateLimitPerHour();

        if ($limit <= 0) {
            return false;
        }

        $since = new \DateTimeImmutable('-1 hour');

        return $repository->countSince($userId, $since) >= $limit;
    }

    private function persist(EntityManagerInterface $entityManager, Generation $record): void
    {
        try {
            $entityManager->persist($record);
            $entityManager->flush();
        } catch (\Throwable $e) {
            // Telemetry must never break a generation, but a hole in the audit
            // trail has to be visible somewhere.
            $this->logger->error('AiEmailSections: failed to persist the generation record.', ['exception' => $e]);
        }
    }

    /**
     * User-facing copy lives in the translation files, never in the code.
     */
    private function error(string $code, string $messageKey, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $code, 'message' => $this->translator->trans($messageKey)],
            $status
        );
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\AiEmailSectionsBundle\Integration\AiEmailSectionsIntegration;
use Symfony\Component\HttpFoundation\Response;

final class GenerateControllerTest extends MauticMysqlTestCase
{
    private const ENDPOINT = '/s/ai-email-sections/generate';

    public function testRejectsRequestWithoutCsrfToken(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['mode' => 'create', 'prompt' => 'grelha'], JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertSame('csrf', $this->decode($response->getContent())['error']);
    }

    public function testRejectsRequestWhenTheIntegrationIsNotPublished(): void
    {
        $this->post(['mode' => 'create', 'prompt' => 'grelha']);

        $response = $this->client->getResponse();

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertSame('disabled', $this->decode($response->getContent())['error']);
    }

    public function testRejectsEmptyPromptOnceEnabled(): void
    {
        $this->publishIntegration();

        $this->post(['mode' => 'create', 'prompt' => '   ']);

        $response = $this->client->getResponse();

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame('bad_request', $this->decode($response->getContent())['error']);
    }

    public function testRejectsEditModeWithoutSource(): void
    {
        $this->publishIntegration();

        $this->post(['mode' => 'edit', 'prompt' => 'make it two columns']);

        $response = $this->client->getResponse();

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame('bad_request', $this->decode($response->getContent())['error']);
    }

    public function testRejectsSourceLargerThanTheLimit(): void
    {
        $this->publishIntegration();

        $this->post([
            'mode'   => 'edit',
            'prompt' => 'muda o fundo',
            'source' => '<mj-section>'.str_repeat('a', 13 * 1024).'</mj-section>',
        ]);

        $response = $this->client->getResponse();

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame('source_too_large', $this->decode($response->getContent())['error']);
    }

    /**
     * The endpoint never sees a malformed body: FOSRestBundle's BodyListener
     * decodes the JSON on kernel.request and answers 400 first. This pins that
     * down, because the obvious reading of the controller is that it guards
     * against this itself, and any guard written there is unreachable.
     */
    public function testAMalformedJsonBodyIsRejectedUpstream(): void
    {
        $this->publishIntegration();

        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'HTTP_X-CSRF-Token'     => $this->getCsrfToken('mautic_ajax_post'),
        ], '{"mode": "create", "prompt": ');

        $response = $this->client->getResponse();

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testRejectsAnEmptyBody(): void
    {
        $this->publishIntegration();

        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'HTTP_X-CSRF-Token'     => $this->getCsrfToken('mautic_ajax_post'),
        ], '');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param mixed[] $payload
     */
    private function post(array $payload): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'HTTP_X-CSRF-Token'     => $this->getCsrfToken('mautic_ajax_post'),
        ], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function publishIntegration(): void
    {
        $integration = new Integration();
        $integration->setName(AiEmailSectionsIntegration::NAME);
        $integration->setIsPublished(true);

        $this->em->persist($integration);
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * @return mixed[]
     */
    private function decode(string|false $content): array
    {
        return json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
    }
}

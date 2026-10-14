<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\AiEmailSectionsBundle\Entity\Generation;
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
     * "Try again shortly" left people clicking every minute. With the limit at
     * two and generations 50 and 20 minutes old, the 50-minute one holds the
     * user at the limit, and it leaves the hour window in ten minutes.
     */
    public function testTheRateLimitSaysWhenItLifts(): void
    {
        $this->publishIntegration(['rate_limit' => 2]);
        $this->recordGeneration('-50 minutes');
        $this->recordGeneration('-20 minutes');

        $this->post(['mode' => 'create', 'prompt' => 'grelha']);

        $response   = $this->client->getResponse();
        $body       = $this->decode($response->getContent());
        $retryAfter = (int) $response->headers->get('Retry-After');

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $this->assertSame('rate_limited', $body['error']);
        $this->assertEqualsWithDelta(600, $retryAfter, 5);
        $this->assertSame($retryAfter, $body['retryAfter']);
        $this->assertEqualsWithDelta(time() + 600, (new \DateTimeImmutable($body['retryAt']))->getTimestamp(), 5);
    }

    /**
     * A limit lowered below what the user already spent leaves them above it.
     * The limit lifts when they are one under again, which is when the
     * limit-th most recent generation leaves the window, not the oldest.
     */
    public function testAboveALoweredLimitItWaitsForTheRightGeneration(): void
    {
        $this->publishIntegration(['rate_limit' => 2]);
        $this->recordGeneration('-55 minutes');
        $this->recordGeneration('-40 minutes');
        $this->recordGeneration('-10 minutes');

        $this->post(['mode' => 'create', 'prompt' => 'grelha']);

        $this->assertEqualsWithDelta(1200, (int) $this->client->getResponse()->headers->get('Retry-After'), 5);
    }

    public function testGenerationsOlderThanAnHourDoNotCount(): void
    {
        $this->publishIntegration(['rate_limit' => 2]);
        $this->recordGeneration('-70 minutes');
        $this->recordGeneration('-20 minutes');

        $this->post(['mode' => 'create', 'prompt' => 'grelha']);

        $this->assertNotSame(Response::HTTP_TOO_MANY_REQUESTS, $this->client->getResponse()->getStatusCode());
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

    /**
     * @param mixed[] $featureSettings
     */
    private function publishIntegration(array $featureSettings = []): void
    {
        $integration = new Integration();
        $integration->setName(AiEmailSectionsIntegration::NAME);
        $integration->setIsPublished(true);
        $integration->setFeatureSettings(['integration' => $featureSettings]);

        $this->em->persist($integration);
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * A generation by the signed-in user, made $ago. The entity stamps itself
     * with the current time, so the timestamp is moved afterwards.
     */
    private function recordGeneration(string $ago): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        \assert($user instanceof User);

        $generation = (new Generation())->setCreatedBy($user->getId())->setPrompt('earlier');

        $this->em->persist($generation);
        $this->em->flush();

        $this->connection->update(
            $this->em->getClassMetadata(Generation::class)->getTableName(),
            ['created_at' => (new \DateTimeImmutable($ago))->format('Y-m-d H:i:s')],
            ['id'         => $generation->getId()]
        );
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

<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Service;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailBuilderEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The personalisation tokens the email builder offers.
 *
 * Collected from EMAIL_ON_BUILD, which is the same event that fills the
 * builder's own token picker. Reading lead_fields would return contact fields
 * only and would miss everything other bundles and plugins register, going
 * stale whenever the install changes. Here, a plugin that adds a token is
 * picked up with no work on our side, and one that is removed disappears.
 *
 * The list is scoped to the user making the request: the contact field tokens go
 * through BuilderTokenHelper, which filters on lead:leads:viewown/viewother. A
 * user who cannot see contact data is not offered contact fields, which is the
 * same list they would see in the builder's own token picker.
 */
final class BuilderTokenProvider
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string[] $excluded tokens to leave out, with or without braces
     *
     * @return array<string, string> token => human label
     */
    public function all(array $excluded = []): array
    {
        // EmailBuilderEvent, not its BuilderEvent parent: the core subscribers
        // type-hint the subclass and a bare BuilderEvent throws a TypeError.
        $event = new EmailBuilderEvent($this->translator);
        $this->dispatcher->dispatch($event, EmailEvents::EMAIL_ON_BUILD);

        // false drops the legacy {leadfield=...} aliases, which are the same
        // contact fields under an older spelling. Keeping both would spend
        // input tokens twice and invite the model to use the deprecated form.
        $tokens = $event->getTokens(false);

        if ([] === $excluded) {
            return $tokens;
        }

        $normalised = array_map(
            static fn (string $token): string => '{'.trim(trim($token), '{}').'}',
            $excluded
        );

        return array_diff_key($tokens, array_flip($normalised));
    }
}

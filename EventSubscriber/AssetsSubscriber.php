<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\EventSubscriber;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomAssetsEvent;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\InstallBundle\Install\InstallService;
use MauticPlugin\AiEmailSectionsBundle\Integration\Config;
use MauticPlugin\AiEmailSectionsBundle\Service\ThemeCatalog;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Injects the GrapesJS plugin into the administration UI.
 *
 * The script registers itself in window.MauticGrapesJsPlugins, which the
 * GrapesJsBuilderBundle BuilderService reads when the builder boots. When the
 * Integration is unpublished nothing is injected and the builder is untouched.
 */
class AssetsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Config $config,
        private InstallService $installer,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private ThemeCatalog $themeCatalog,
        private EmailModel $emailModel,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_ASSETS => ['injectAssets', 0],
        ];
    }

    public function injectAssets(CustomAssetsEvent $assetsEvent): void
    {
        if (!$this->installer->checkIfInstalled() || !$this->isAdministrationPage()) {
            return;
        }

        if (!$this->config->isPublished()) {
            return;
        }

        // The settings the frontend needs. The API key never goes in here.
        $settings = json_encode([
            'endpoint'       => $this->urlGenerator->generate('ai_email_sections_generate'),
            'placeholderSrc' => $this->config->getPlaceholderImage(),
            'maxSourceBytes' => $this->config->getMaxSourceBytes(),
            'themes'         => $this->themeCatalog->all(),
            'theme'          => $this->themeCatalog->resolve($this->themeOfEmailBeingEdited(), $this->config->getThemeId()),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $assetsEvent->addScriptDeclaration('window.MauticAiEmailSectionsConfig = '.$settings.';', 'head');
        $assetsEvent->addScript('plugins/AiEmailSectionsBundle/Assets/dist/index.js');
        $assetsEvent->addStylesheet('plugins/AiEmailSectionsBundle/Assets/dist/index.css');
    }

    /**
     * The theme of the email open in the builder, when the names line up.
     *
     * Mautic stores the theme directory name in emails.template, so a theme file
     * named after it is the obvious thing to preselect. This is a name match and
     * nothing more: no reading of the email's MJML, no guessing. When there is no
     * such file, or no email, the configured default is used instead.
     */
    private function themeOfEmailBeingEdited(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || 1 !== preg_match('~^/s/emails/edit/(\d+)~', $request->getPathInfo(), $matches)) {
            return null;
        }

        $email = $this->emailModel->getEntity((int) $matches[1]);

        return $email?->getTemplate() ?: null;
    }

    private function isAdministrationPage(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return false;
        }

        return 1 === preg_match('~^/s/~', $request->getPathInfo());
    }
}

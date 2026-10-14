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
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        $assetsEvent->addScriptDeclaration('window.MauticAiEmailSectionsConfig = '.$settings.';', 'head');
        $assetsEvent->addScript($this->versioned('Assets/dist/index.js'));
        $assetsEvent->addStylesheet($this->versioned('Assets/dist/index.css'));
    }

    /**
     * Mautic versions asset URLs from its configuration, not from the file, so
     * an edited script keeps its URL, and the response carries no Cache-Control.
     * After the 14/10/2026 deploy Cloudflare kept serving the previous panel
     * from the edge, and a browser may keep its copy for hours on heuristic
     * freshness. A hash of the file in the URL makes every change a new URL.
     * Mautic appends its own version after it.
     */
    private function versioned(string $file): string
    {
        $url  = 'plugins/AiEmailSectionsBundle/'.$file;
        $hash = md5_file(__DIR__.'/../'.$file);

        return false === $hash ? $url : $url.'?h='.substr($hash, 0, 8);
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

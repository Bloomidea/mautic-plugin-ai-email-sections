<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\AiEmailSectionsBundle\Integration\Config;
use MauticPlugin\AiEmailSectionsBundle\Service\ThemeCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Feature settings tab. No client-specific value is hardcoded: a freshly
 * installed plugin works with these defaults and the default.yaml theme.
 */
class ConfigFeaturesType extends AbstractType
{
    public function __construct(private readonly ThemeCatalog $themeCatalog)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('provider', ChoiceType::class, [
            'label'      => 'mautic.aiemailsections.config.provider',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.aiemailsections.config.provider.tooltip',
            ],
            'choices' => [
                'mautic.aiemailsections.config.provider.openai'    => Config::PROVIDER_OPENAI,
                'mautic.aiemailsections.config.provider.anthropic' => Config::PROVIDER_ANTHROPIC,
            ],
            // No "data" default: it would win over the stored value on every
            // render. With no placeholder the first choice, which is the
            // default provider, is what an unconfigured install shows.
            'required'    => false,
            'placeholder' => false,
        ]);

        $builder->add('brand', TextareaType::class, [
            'label'      => 'mautic.aiemailsections.config.brand',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'       => 'form-control',
                'rows'        => 5,
                'tooltip'     => 'mautic.aiemailsections.config.brand.tooltip',
                'placeholder' => 'mautic.aiemailsections.config.brand.placeholder',
            ],
            'required'    => false,
            'constraints' => [new Length(max: Config::MAX_BRAND_BRIEF_CHARS)],
        ]);

        $builder->add('tokens_enabled', YesNoButtonGroupType::class, [
            'label'      => 'mautic.aiemailsections.config.tokens_enabled',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['tooltip' => 'mautic.aiemailsections.config.tokens_enabled.tooltip'],
            'data'       => true,
        ]);

        $builder->add('tokens_excluded', TextareaType::class, [
            'label'      => 'mautic.aiemailsections.config.tokens_excluded',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'       => 'form-control',
                'rows'        => 3,
                'tooltip'     => 'mautic.aiemailsections.config.tokens_excluded.tooltip',
                'placeholder' => 'mautic.aiemailsections.config.tokens_excluded.placeholder',
            ],
            'required' => false,
        ]);

        $builder->add('base_url', UrlType::class, [
            'label'      => 'mautic.aiemailsections.config.base_url',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'       => 'form-control',
                'tooltip'     => 'mautic.aiemailsections.config.base_url.tooltip',
                'placeholder' => 'mautic.aiemailsections.config.provider_default',
            ],
            'required'         => false,
            'default_protocol' => null,
        ]);

        $builder->add('model', TextType::class, [
            'label'      => 'mautic.aiemailsections.config.model',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'       => 'form-control',
                'tooltip'     => 'mautic.aiemailsections.config.model.tooltip',
                'placeholder' => 'mautic.aiemailsections.config.provider_default',
            ],
            'required' => false,
        ]);

        $builder->add('temperature', NumberType::class, [
            'label'      => 'mautic.aiemailsections.config.temperature',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'       => 'form-control',
                'tooltip'     => 'mautic.aiemailsections.config.temperature.tooltip',
                'placeholder' => (string) Config::DEFAULT_TEMPERATURE,
            ],
            'required' => false,
            'scale'    => 2,
        ]);

        $builder->add('max_tokens', IntegerType::class, [
            'label'      => 'mautic.aiemailsections.config.max_tokens',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'       => 'form-control',
                'tooltip'     => 'mautic.aiemailsections.config.max_tokens.tooltip',
                'placeholder' => (string) Config::DEFAULT_MAX_TOKENS,
            ],
            'required' => false,
        ]);

        $builder->add('placeholder_image', TextType::class, [
            'label'      => 'mautic.aiemailsections.config.placeholder_image',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'       => 'form-control',
                'tooltip'     => 'mautic.aiemailsections.config.placeholder_image.tooltip',
                'placeholder' => Config::DEFAULT_PLACEHOLDER,
            ],
            'required' => false,
        ]);

        $builder->add('rate_limit', IntegerType::class, [
            'label'      => 'mautic.aiemailsections.config.rate_limit',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control', 'placeholder' => (string) Config::DEFAULT_RATE_LIMIT],
            'required'   => false,
        ]);

        $builder->add('timeout', IntegerType::class, [
            'label'      => 'mautic.aiemailsections.config.timeout',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control', 'placeholder' => (string) Config::DEFAULT_TIMEOUT],
            'required'   => false,
        ]);

        $builder->add('theme', ChoiceType::class, [
            'label'       => 'mautic.aiemailsections.config.theme',
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => ['class' => 'form-control'],
            'choices'     => $this->availableThemes(),
            'required'    => false,
            'placeholder' => false,
        ]);
    }

    /**
     * IntegrationFeatureSettingsType adds this form with nothing but
     * ['label' => false], so "integration" must be optional here. Requiring it,
     * as the auth tab does, makes the configuration modal blow up with an alert
     * instead of opening.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefined(['integration']);
    }

    /**
     * @return array<string, string>
     */
    /**
     * @return array<string, string> label => id, which is the order ChoiceType wants
     */
    private function availableThemes(): array
    {
        return array_flip($this->themeCatalog->all());
    }
}

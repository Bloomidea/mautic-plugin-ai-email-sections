<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Auth tab. Everything entered here is encrypted by the IntegrationsBundle
 * before being stored, and is never serialised to the client.
 */
class ConfigAuthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('api_key', PasswordType::class, [
            'label'      => 'mautic.aiemailsections.config.api_key',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'        => 'form-control',
                'tooltip'      => 'mautic.aiemailsections.config.api_key.tooltip',
                'autocomplete' => 'off',
            ],
            'required'         => false,
            'always_empty'     => false,
        ]);
    }

    /**
     * The IntegrationsBundle always passes the Integration to the form type.
     * Without declaring it here the configuration modal blows up with an alert
     * instead of opening.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['integration']);
    }
}

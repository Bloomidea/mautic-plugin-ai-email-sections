<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Security\Permissions;

use Mautic\CoreBundle\Security\Permissions\AbstractPermissions;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Dedicated permission, so the assistant can be restricted per role.
 *
 * Without it anyone with builder access can burn model tokens.
 */
class AiEmailSectionsPermissions extends AbstractPermissions
{
    /**
     * @param mixed[] $params
     */
    public function __construct($params)
    {
        parent::__construct($params);
        $this->addStandardPermissions(['generations']);
    }

    public function getName(): string
    {
        return 'aiemailsections';
    }

    /**
     * @param mixed[] $options
     * @param mixed[] $data
     */
    public function buildForm(FormBuilderInterface &$builder, array $options, array $data): void
    {
        $this->addStandardFormFields('aiemailsections', 'generations', $builder, $data);
    }
}

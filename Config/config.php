<?php

declare(strict_types=1);

return [
    'name'        => 'AI Email Sections',
    'description' => 'Generates MJML sections in the email builder from a text description.',
    'version'     => '1.0.7',
    'author'      => 'Bloomidea',

    'routes' => [
        'main' => [
            'ai_email_sections_generate' => [
                'path'       => '/ai-email-sections/generate',
                'controller' => 'MauticPlugin\AiEmailSectionsBundle\Controller\GenerateController::generateAction',
                'methods'    => ['POST'],
            ],
        ],
        'public' => [],
        'api'    => [],
    ],

    'menu' => [],
];

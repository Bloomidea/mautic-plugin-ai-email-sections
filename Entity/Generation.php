<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Generation telemetry.
 *
 * It exists to answer the questions that decide whether the pilot continues:
 * which prompts the team writes, how many attempts the model needs, which tags
 * it invents most, and above all which of the two modes actually gets used.
 *
 * It stores no contact data. Only the prompt and the output.
 */
class Generation
{
    public const STATUS_OK     = 'ok';
    public const STATUS_FAILED = 'failed';

    private ?int $id = null;

    private ?int $createdBy = null;

    private \DateTimeInterface $createdAt;

    private ?int $emailId = null;

    private string $themeId = 'default';

    private string $mode = 'create';

    private string $prompt = '';

    private ?string $sourceMjml = null;

    private ?string $rawResponse = null;

    private ?string $finalMjml = null;

    private int $attempts = 0;

    /** @var mixed[]|null */
    private ?array $validationErrors = null;

    /** @var mixed[]|null */
    private ?array $warnings = null;

    private string $status = self::STATUS_OK;

    private int $latencyMs = 0;

    private string $model = '';

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('ai_email_sections_generation')
            ->setCustomRepositoryClass(GenerationRepository::class)
            ->addId()
            ->addNamedField('createdBy', Types::INTEGER, 'created_by', true)
            ->addNamedField('createdAt', Types::DATETIME_IMMUTABLE, 'created_at')
            ->addNamedField('emailId', Types::INTEGER, 'email_id', true)
            ->addNamedField('themeId', Types::STRING, 'theme_id')
            ->addNamedField('mode', Types::STRING, 'mode')
            ->addNamedField('prompt', Types::TEXT, 'prompt')
            ->addNamedField('sourceMjml', Types::TEXT, 'source_mjml', true)
            ->addNamedField('rawResponse', Types::TEXT, 'raw_response', true)
            ->addNamedField('finalMjml', Types::TEXT, 'final_mjml', true)
            ->addNamedField('attempts', Types::INTEGER, 'attempts')
            ->addNamedField('validationErrors', Types::JSON, 'validation_errors', true)
            ->addNamedField('warnings', Types::JSON, 'warnings', true)
            ->addNamedField('status', Types::STRING, 'status')
            ->addNamedField('latencyMs', Types::INTEGER, 'latency_ms')
            ->addNamedField('model', Types::STRING, 'model');

        $builder->addIndex(['created_by', 'created_at'], 'gjsa_created_by_at');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedBy(?int $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function setEmailId(?int $emailId): self
    {
        $this->emailId = $emailId;

        return $this;
    }

    public function setThemeId(string $themeId): self
    {
        $this->themeId = $themeId;

        return $this;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function setPrompt(string $prompt): self
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function setSourceMjml(?string $sourceMjml): self
    {
        $this->sourceMjml = $sourceMjml;

        return $this;
    }

    public function setRawResponse(?string $rawResponse): self
    {
        $this->rawResponse = $rawResponse;

        return $this;
    }

    public function setFinalMjml(?string $finalMjml): self
    {
        $this->finalMjml = $finalMjml;

        return $this;
    }

    public function setAttempts(int $attempts): self
    {
        $this->attempts = $attempts;

        return $this;
    }

    /**
     * @param mixed[]|null $validationErrors
     */
    public function setValidationErrors(?array $validationErrors): self
    {
        $this->validationErrors = $validationErrors;

        return $this;
    }

    /**
     * @param mixed[]|null $warnings
     */
    public function setWarnings(?array $warnings): self
    {
        $this->warnings = $warnings;

        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function setLatencyMs(int $latencyMs): self
    {
        $this->latencyMs = $latencyMs;

        return $this;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }
}

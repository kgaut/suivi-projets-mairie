<?php

declare(strict_types=1);

namespace App\Domain;

use App\Infrastructure\Repository\ExternalLinkRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Lien externe affiché dans le lanceur d'apps du header.
 *
 * Cf. `docs/specifications.md` §3.12 et `docs/modele-de-donnees.md` §3.12.
 *
 * Modèle minimaliste : `label`, `url`, `icon` (emoji ou lettre), `description`,
 * `position` (tri manuel), `enabled` (toggle on/off sans suppression).
 * Pas de restriction par rôle : tout utilisateur authentifié voit les liens actifs.
 */
#[ORM\Entity(repositoryClass: ExternalLinkRepository::class)]
#[ORM\Table(name: 'external_links')]
#[ORM\Index(name: 'idx_external_links_enabled_position', columns: ['enabled', 'position'])]
class ExternalLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $label;

    #[ORM\Column(type: Types::STRING, length: 1024)]
    private string $url;

    /** Emoji, lettre ou nom d'icône lib (à brancher plus tard). */
    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $icon = null;

    /** Tooltip / description courte affichée au hover. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $description = null;

    /** Tri manuel ascendant. Pas d'unicité — l'admin gère lui-même les égalités. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $position = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $label, string $url)
    {
        $this->id = Uuid::v7();
        $this->label = $label;
        $this->url = $url;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
        $this->touch();
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
        $this->touch();
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): void
    {
        $this->icon = $icon;
        $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
        $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        if (!$this->enabled) {
            $this->enabled = true;
            $this->touch();
        }
    }

    public function disable(): void
    {
        if ($this->enabled) {
            $this->enabled = false;
            $this->touch();
        }
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}

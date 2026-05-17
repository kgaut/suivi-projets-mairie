<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\ExternalLink;
use LogicException;

/**
 * DTO du formulaire `ExternalLinkType`. On évite de mapper directement sur
 * l'entité pour ne pas exposer les setters côté form et garder l'entité
 * propre (cf. specs §5.2 — controllers fins, services applicatifs au milieu).
 *
 * `fromEntity()` initialise depuis une entité existante (édition),
 * `applyTo()` recopie les valeurs validées dans une entité (création ou maj).
 */
final class ExternalLinkInput
{
    public ?string $label = null;

    public ?string $url = null;

    public ?string $icon = null;

    public ?string $description = null;

    public int $position = 0;

    public bool $enabled = true;

    public static function fromEntity(ExternalLink $link): self
    {
        $input = new self();
        $input->label = $link->getLabel();
        $input->url = $link->getUrl();
        $input->icon = $link->getIcon();
        $input->description = $link->getDescription();
        $input->position = $link->getPosition();
        $input->enabled = $link->isEnabled();

        return $input;
    }

    /**
     * Pré-condition : le formulaire a déjà été validé (label et url non null).
     *
     * @throws LogicException si le DTO n'a pas été validé avant appel
     */
    public function applyTo(ExternalLink $link): void
    {
        if ($this->label === null || $this->url === null) {
            throw new LogicException('ExternalLinkInput::applyTo() appelé sur un DTO non validé (label ou url null).');
        }

        $link->setLabel($this->label);
        $link->setUrl($this->url);
        $link->setIcon($this->normalizeNullable($this->icon));
        $link->setDescription($this->normalizeNullable($this->description));
        $link->setPosition($this->position);
        if ($this->enabled) {
            $link->enable();
        } else {
            $link->disable();
        }
    }

    public function toNewEntity(): ExternalLink
    {
        if ($this->label === null || $this->url === null) {
            throw new LogicException('ExternalLinkInput::toNewEntity() appelé sur un DTO non validé (label ou url null).');
        }

        $link = new ExternalLink($this->label, $this->url);
        $this->applyTo($link);

        return $link;
    }

    private function normalizeNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Service\Avatar;

/**
 * Sortie du `UserAvatarResolver` : soit une URL (image distante ou cache local),
 * soit un SVG inline (cas des initiales), avec un `alt` pour l'accessibilité.
 *
 * Le filtre Twig `user|avatar(size)` consomme ce DTO pour générer le HTML adapté.
 */
final readonly class AvatarRender
{
    private function __construct(
        public ?string $url,
        public ?string $svg,
        public string $alt,
        public int $size,
    ) {
    }

    public static function fromUrl(string $url, string $alt, int $size): self
    {
        return new self(url: $url, svg: null, alt: $alt, size: $size);
    }

    public static function fromSvg(string $svg, string $alt, int $size): self
    {
        return new self(url: null, svg: $svg, alt: $alt, size: $size);
    }

    public function isInline(): bool
    {
        return $this->svg !== null;
    }
}

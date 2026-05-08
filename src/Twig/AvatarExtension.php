<?php

declare(strict_types=1);

namespace App\Twig;

use App\Application\Service\Avatar\AvatarRender;
use App\Application\Service\Avatar\UserAvatarResolver;
use App\Domain\User;
use Twig\Attribute\AsTwigFilter;
use Twig\Markup;

/**
 * Filtre Twig `user|avatar(size=64)` qui rend un `<img>` ou un `<svg>` selon
 * la cascade définie en `UserAvatarResolver`.
 *
 * Usage :
 *
 *   {{ user|avatar(32) }}
 *   {{ user|avatar(size=128, class='ring-2 ring-white') }}
 *
 * Le HTML généré inclut une classe par défaut `rounded-full` (Tailwind) qu'on
 * peut compléter via le paramètre `class` (concaténé, pas remplacé).
 */
final readonly class AvatarExtension
{
    public function __construct(
        private UserAvatarResolver $resolver,
    ) {
    }

    #[AsTwigFilter(name: 'avatar', needsEnvironment: false, isSafe: ['html'])]
    public function renderAvatar(User $user, int $size = 64, string $class = ''): Markup
    {
        $render = $this->resolver->resolve($user, $size);
        $extraClass = trim($class);
        $cssClass = trim('rounded-full object-cover ' . $extraClass);

        $html = $render->isInline()
            ? $this->wrapSvg($render, $cssClass)
            : $this->wrapImg($render, $cssClass);

        return new Markup($html, 'UTF-8');
    }

    private function wrapImg(AvatarRender $render, string $cssClass): string
    {
        $url = htmlspecialchars($render->url ?? '', \ENT_QUOTES);
        $alt = htmlspecialchars($render->alt, \ENT_QUOTES);
        $size = $render->size;
        $class = htmlspecialchars($cssClass, \ENT_QUOTES);

        return \sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d" class="%s" loading="lazy" decoding="async">',
            $url,
            $alt,
            $size,
            $size,
            $class,
        );
    }

    private function wrapSvg(AvatarRender $render, string $cssClass): string
    {
        // Le SVG inline est déjà dimensionné par UserAvatarResolver. On
        // l'enveloppe dans un span pour appliquer la classe Tailwind sans
        // perturber le viewBox. role="img" + aria-label pour a11y.
        $svg = $render->svg ?? '';
        $alt = htmlspecialchars($render->alt, \ENT_QUOTES);
        $size = $render->size;
        $class = htmlspecialchars($cssClass, \ENT_QUOTES);

        return \sprintf(
            '<span role="img" aria-label="%s" class="inline-block overflow-hidden %s" style="width:%dpx;height:%dpx">%s</span>',
            $alt,
            $class,
            $size,
            $size,
            $svg,
        );
    }
}

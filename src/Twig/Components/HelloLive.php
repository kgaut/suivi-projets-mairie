<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Démo Live Component : un compteur incrémenté côté serveur à chaque clic.
 *
 * Sera supprimé / remplacé par les vrais composants applicatifs au fil
 * des prochaines issues.
 */
#[AsLiveComponent]
final class HelloLive
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public int $count = 0;

    #[LiveAction]
    public function increment(): void
    {
        ++$this->count;
    }

    #[LiveAction]
    public function reset(): void
    {
        $this->count = 0;
    }
}

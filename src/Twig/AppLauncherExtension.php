<?php

declare(strict_types=1);

namespace App\Twig;

use App\Application\ExternalLink\ExternalLinkRepositoryInterface;
use App\Domain\ExternalLink;
use Override;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose la liste des liens externes actifs au template du lanceur d'apps.
 *
 * Le Twig passe par l'interface applicative plutôt que d'appeler le
 * repository Doctrine, ce qui :
 * - permet de mocker la liste en test
 * - garde l'isolation des couches (cf. docs/qualite.md §3)
 */
final class AppLauncherExtension extends AbstractExtension
{
    public function __construct(private readonly ExternalLinkRepositoryInterface $repository)
    {
    }

    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_launcher_links', $this->activeLinks(...)),
        ];
    }

    /**
     * @return list<ExternalLink>
     */
    public function activeLinks(): array
    {
        return $this->repository->findActiveOrdered();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Application\ExternalLink\ExternalLinkRepositoryInterface;
use App\Domain\ExternalLink;
use App\Twig\AppLauncherExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

#[CoversClass(AppLauncherExtension::class)]
final class AppLauncherExtensionTest extends TestCase
{
    public function testActiveLinksDelegatesToRepository(): void
    {
        $mailpit = new ExternalLink('Mailpit', 'http://localhost:8025');
        $repository = new readonly class ($mailpit) implements ExternalLinkRepositoryInterface {
            public function __construct(private ExternalLink $link)
            {
            }

            public function findAllOrdered(): array
            {
                return [$this->link];
            }

            public function findActiveOrdered(): array
            {
                return [$this->link];
            }

            public function countAll(): int
            {
                return 1;
            }

            public function save(ExternalLink $link): void
            {
            }

            public function remove(ExternalLink $link): void
            {
            }
        };

        $extension = new AppLauncherExtension($repository);

        self::assertSame([$mailpit], $extension->activeLinks());
    }

    public function testExposesTwigFunction(): void
    {
        $repository = $this->createStub(ExternalLinkRepositoryInterface::class);
        $extension = new AppLauncherExtension($repository);

        $functions = array_map(static fn (TwigFunction $fn): string => $fn->getName(), $extension->getFunctions());

        self::assertContains('app_launcher_links', $functions);
    }
}

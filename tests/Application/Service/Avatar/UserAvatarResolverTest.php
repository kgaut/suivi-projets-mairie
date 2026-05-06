<?php

declare(strict_types=1);

namespace App\Tests\Application\Service\Avatar;

use App\Application\Service\Avatar\AvatarRender;
use App\Application\Service\Avatar\UserAvatarResolver;
use App\Domain\Enum\AvatarSource;
use App\Domain\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserAvatarResolver::class)]
#[CoversClass(AvatarRender::class)]
final class UserAvatarResolverTest extends TestCase
{
    private UserAvatarResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new UserAvatarResolver();
    }

    public function testAutoFallsBackToInitialsWhenNothingAvailable(): void
    {
        $user = new User('sub', 'jdoe', '', 'John Doe');
        $user->setGravatarAllowed(false);

        $render = $this->resolver->resolve($user, 64);

        $this->assertTrue($render->isInline());
        $this->assertNotNull($render->svg);
        $this->assertStringContainsString('JD', $render->svg, 'SVG must contain initials');
        $this->assertSame('John Doe', $render->alt);
    }

    public function testAutoUsesLocalUploadWhenPresent(): void
    {
        $user = new User('sub', 'jdoe', 'j@e.fr', 'John Doe');
        $user->setAvatarPath('users/abc-128.webp');

        $render = $this->resolver->resolve($user, 128);

        $this->assertFalse($render->isInline());
        $this->assertSame('/uploads/users/abc-128.webp', $render->url);
        $this->assertSame(128, $render->size);
    }

    public function testAutoUsesAuthentikCacheWhenLocalUploadAbsent(): void
    {
        $user = new User('sub', 'jdoe', 'j@e.fr', 'John Doe');
        $user->setAuthentikAvatar(
            'https://authentik.example.fr/media/users/x.jpg',
            'users/sub-512.webp',
            new \DateTimeImmutable(),
        );

        $render = $this->resolver->resolve($user, 64);

        $this->assertFalse($render->isInline());
        $this->assertSame('/uploads/users/sub-512.webp', $render->url);
    }

    public function testAutoUsesGravatarWhenLocalAndAuthentikAbsent(): void
    {
        $user = new User('sub', 'jdoe', 'jean.dupont@mairie.example.fr', 'Jean Dupont');

        $render = $this->resolver->resolve($user, 64);

        $this->assertFalse($render->isInline());
        $this->assertNotNull($render->url);
        $this->assertStringStartsWith('https://gravatar.com/avatar/', $render->url);
        $this->assertStringContainsString('?d=identicon&s=64', $render->url);
    }

    public function testGravatarSkippedWhenNotAllowed(): void
    {
        $user = new User('sub', 'jdoe', 'jean.dupont@mairie.example.fr', 'Jean Dupont');
        $user->setGravatarAllowed(false);

        $render = $this->resolver->resolve($user, 64);

        $this->assertTrue($render->isInline(), 'Sans Gravatar, doit tomber sur les initiales');
    }

    public function testForcedInitialsBypassesGravatar(): void
    {
        $user = new User('sub', 'jdoe', 'j@e.fr', 'John Doe');
        $user->setAvatarSource(AvatarSource::INITIALS);

        $render = $this->resolver->resolve($user, 64);

        $this->assertTrue($render->isInline());
    }

    public function testForcedLocalReturnsInitialsIfNoUpload(): void
    {
        // LOCAL forcé mais avatarPath null → fallback initiales
        $user = new User('sub', 'jdoe', 'j@e.fr', 'John Doe');
        $user->setAvatarSource(AvatarSource::LOCAL);

        $render = $this->resolver->resolve($user, 64);

        $this->assertTrue($render->isInline());
    }

    public function testGravatarUrlIsDeterministic(): void
    {
        $email = 'jean.dupont@mairie.example.fr';
        $url1 = UserAvatarResolver::gravatarUrl($email, 64);
        $url2 = UserAvatarResolver::gravatarUrl(' '.strtoupper($email).' ', 64);

        // Trim + lowercase appliqués, donc les deux URLs doivent être identiques.
        $this->assertSame($url1, $url2);
    }

    public function testInitialsColorIsStableForSameUser(): void
    {
        $user1 = new User('sub-stable', 'a', 'a@e.fr', 'Alice');
        $user2 = new User('sub-stable', 'b', 'b@e.fr', 'Bob');

        $svg1 = $this->resolver->resolve($user1, 64)->svg ?? '';
        $svg2 = $this->resolver->resolve($user2, 64)->svg ?? '';

        // Même authentikId → même couleur de fond (mais initiales différentes)
        preg_match('/fill="(#[0-9a-f]+)"/', $svg1, $m1);
        preg_match('/fill="(#[0-9a-f]+)"/', $svg2, $m2);

        $this->assertSame($m1[1] ?? null, $m2[1] ?? null, 'La couleur doit être déterministe par authentikId');
    }

    public function testInitialsHandleSingleWord(): void
    {
        $user = new User('sub', 'admin', 'a@e.fr', 'Admin');
        $user->setGravatarAllowed(false);

        $render = $this->resolver->resolve($user, 64);

        $this->assertStringContainsString('AD', $render->svg ?? '');
    }

    public function testInitialsHandleEmptyName(): void
    {
        // Edge case : displayName vide → '?'
        $user = new User('sub', '', 'a@e.fr', '   ');
        $user->setGravatarAllowed(false);

        $render = $this->resolver->resolve($user, 64);

        $this->assertStringContainsString('?', $render->svg ?? '');
    }
}

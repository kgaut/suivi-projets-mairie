<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Enum\AvatarSource;
use App\Domain\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    public function testNewUserHasIdAndDefaults(): void
    {
        $user = new User('authentik-sub-123', 'jdupont', 'jean.dupont@mairie.example.fr', 'Jean Dupont');

        // Uuid v7 → 36 caractères, version `7` au 15ᵉ char (RFC 9562 §5.7).
        $this->assertSame(36, \strlen($user->getId()->toRfc4122()));
        $this->assertSame('7', $user->getId()->toRfc4122()[14]);
        $this->assertSame('authentik-sub-123', $user->getAuthentikId());
        $this->assertSame('jdupont', $user->getUsername());
        $this->assertSame('jean.dupont@mairie.example.fr', $user->getEmail());
        $this->assertSame('Jean Dupont', $user->getDisplayName());

        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertSame([], $user->getGroupsSnapshot());
        $this->assertSame(AvatarSource::AUTO, $user->getAvatarSource());
        $this->assertTrue($user->isGravatarAllowed());

        $this->assertNull($user->getLastLoginAt());
        $this->assertNull($user->getDisabledAt());
        $this->assertNull($user->getAvatarPath());
        $this->assertNull($user->getAuthentikAvatarPath());
        $this->assertFalse($user->isDisabled());
    }

    public function testUserIdentifierIsAuthentikId(): void
    {
        $user = new User('sub-42', 'agatha', 'a@example.fr', 'Agatha');

        // Le sub Authentik est immuable, idéal pour la sécurité Symfony.
        $this->assertSame('sub-42', $user->getUserIdentifier());
    }

    public function testRolesAlwaysIncludeUser(): void
    {
        $user = new User('sub', 'u', 'u@example.fr', 'U');
        $user->setRoles(['ROLE_ADMIN']);

        $roles = $user->getRoles();
        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
    }

    public function testRolesAreDeduplicated(): void
    {
        $user = new User('sub', 'u', 'u@example.fr', 'U');
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_USER']);

        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
    }

    public function testRecordLoginUpdatesTimestamp(): void
    {
        $user = new User('sub', 'u', 'u@example.fr', 'U');
        $this->assertNull($user->getLastLoginAt());

        $loginAt = new DateTimeImmutable('2026-05-06 10:00:00');
        $user->recordLogin($loginAt);

        $this->assertEquals($loginAt, $user->getLastLoginAt());
    }

    public function testDisableSetsTimestamp(): void
    {
        $user = new User('sub', 'u', 'u@example.fr', 'U');
        $this->assertFalse($user->isDisabled());

        $user->disable();

        $this->assertTrue($user->isDisabled());
        $this->assertNotNull($user->getDisabledAt());
    }

    public function testSetAuthentikAvatarStoresAllFields(): void
    {
        $user = new User('sub', 'u', 'u@example.fr', 'U');
        $fetchedAt = new DateTimeImmutable();

        $user->setAuthentikAvatar(
            'https://authentik.example.fr/media/users/abc.jpg',
            'attachments/users/abc-512.webp',
            $fetchedAt,
        );

        $this->assertSame('https://authentik.example.fr/media/users/abc.jpg', $user->getAuthentikAvatarSourceUrl());
        $this->assertSame('attachments/users/abc-512.webp', $user->getAuthentikAvatarPath());
        $this->assertEquals($fetchedAt, $user->getAuthentikAvatarFetchedAt());
    }

    public function testClearAuthentikAvatarResetsAllFields(): void
    {
        $user = new User('sub', 'u', 'u@example.fr', 'U');
        $user->setAuthentikAvatar('url', 'path', new DateTimeImmutable());

        $user->clearAuthentikAvatar();

        $this->assertNull($user->getAuthentikAvatarSourceUrl());
        $this->assertNull($user->getAuthentikAvatarPath());
        $this->assertNull($user->getAuthentikAvatarFetchedAt());
    }

    public function testEraseCredentialsIsNoOp(): void
    {
        $user = new User('sub', 'u', 'u@example.fr', 'U');
        $user->eraseCredentials();

        // Pas de mot de passe stocké : appel idempotent.
        $this->expectNotToPerformAssertions();
    }
}

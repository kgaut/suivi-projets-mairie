<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Domain\User;
use App\Security\OidcAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

#[CoversClass(OidcAccessGuard::class)]
#[AllowMockObjectsWithoutExpectations]
final class OidcAccessGuardTest extends TestCase
{
    public function testEmptyConfigAllowsEveryone(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $guard = new OidcAccessGuard('', $em);
        $user = new User('sub', 'u', 'u@e.fr', 'U');

        $guard->ensureUserIsAllowed($user, []);

        $this->assertFalse($user->isDisabled());
        $this->assertSame([], $guard->getRequiredGroups());
    }

    public function testWhitespaceConfigAllowsEveryone(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $guard = new OidcAccessGuard('   ', $em);

        $this->assertSame([], $guard->getRequiredGroups());
    }

    public function testUserInRequiredGroupIsAllowed(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $guard = new OidcAccessGuard('spm_users,admin_spm', $em);
        $user = new User('sub', 'u', 'u@e.fr', 'U');

        $guard->ensureUserIsAllowed($user, ['commission-numerique', 'spm_users']);

        $this->assertFalse($user->isDisabled());
    }

    public function testUserNotInAnyRequiredGroupIsRejected(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $guard = new OidcAccessGuard('spm_users,admin_spm', $em);
        $user = new User('sub-rejected', 'u', 'u@e.fr', 'U');

        try {
            $guard->ensureUserIsAllowed($user, ['commission-numerique', 'autre-groupe']);
            $this->fail('Expected CustomUserMessageAuthenticationException');
        } catch (CustomUserMessageAuthenticationException $e) {
            $this->assertStringContainsString('Accès non autorisé', $e->getMessageKey());
        }

        $this->assertTrue($user->isDisabled(), 'User must be disabled when rejected');
    }

    public function testEmptyUserGroupsIsRejectedWhenRequiredGroupsConfigured(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $guard = new OidcAccessGuard('spm_users', $em);
        $user = new User('sub', 'u', 'u@e.fr', 'U');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $guard->ensureUserIsAllowed($user, []);
    }

    public function testRequiredGroupsParsingTrimsAndIgnoresEmpty(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $guard = new OidcAccessGuard('  spm_users  ,, admin_spm , ', $em);

        $this->assertSame(['spm_users', 'admin_spm'], $guard->getRequiredGroups());
    }
}

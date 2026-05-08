<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Application\Event\Security\AccessDenied;
use App\Application\Event\User\UserDisabled;
use App\Domain\User;
use App\Security\OidcAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

#[CoversClass(OidcAccessGuard::class)]
#[AllowMockObjectsWithoutExpectations]
final class OidcAccessGuardTest extends TestCase
{
    public function testEmptyConfigAllowsEveryone(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $guard = new OidcAccessGuard('', $em, new NullLogger(), new EventDispatcher());
        $user = new User('sub', 'u', 'u@e.fr', 'U');

        $guard->ensureUserIsAllowed($user, []);

        $this->assertFalse($user->isDisabled());
        $this->assertSame([], $guard->getRequiredGroups());
    }

    public function testWhitespaceConfigAllowsEveryone(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $guard = new OidcAccessGuard('   ', $em, new NullLogger(), new EventDispatcher());

        $this->assertSame([], $guard->getRequiredGroups());
    }

    public function testUserInRequiredGroupIsAllowed(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $guard = new OidcAccessGuard('spm_users,admin_spm', $em, new NullLogger(), new EventDispatcher());
        $user = new User('sub', 'u', 'u@e.fr', 'U');

        $guard->ensureUserIsAllowed($user, ['commission-numerique', 'spm_users']);

        $this->assertFalse($user->isDisabled());
    }

    public function testUserNotInAnyRequiredGroupIsRejected(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $guard = new OidcAccessGuard('spm_users,admin_spm', $em, new NullLogger(), new EventDispatcher());
        $user = new User('sub-rejected', 'u', 'u@e.fr', 'U');

        try {
            $guard->ensureUserIsAllowed($user, ['commission-numerique', 'autre-groupe']);
            $this->fail('Expected CustomUserMessageAuthenticationException');
        } catch (CustomUserMessageAuthenticationException $customUserMessageAuthenticationException) {
            $this->assertStringContainsString('Accès non autorisé', $customUserMessageAuthenticationException->getMessageKey());
        }

        $this->assertTrue($user->isDisabled(), 'User must be disabled when rejected');
    }

    public function testEmptyUserGroupsIsRejectedWhenRequiredGroupsConfigured(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $guard = new OidcAccessGuard('spm_users', $em, new NullLogger(), new EventDispatcher());
        $user = new User('sub', 'u', 'u@e.fr', 'U');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $guard->ensureUserIsAllowed($user, []);
    }

    public function testRequiredGroupsParsingTrimsAndIgnoresEmpty(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $guard = new OidcAccessGuard('  spm_users  ,, admin_spm , ', $em, new NullLogger(), new EventDispatcher());

        $this->assertSame(['spm_users', 'admin_spm'], $guard->getRequiredGroups());
    }

    public function testRejectionDispatchesAccessDeniedAndUserDisabledEvents(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $captured = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            AccessDenied::class,
            static function (AccessDenied $event) use (&$captured): void {
                $captured['access_denied'] = $event;
            },
        );
        $dispatcher->addListener(
            UserDisabled::class,
            static function (UserDisabled $event) use (&$captured): void {
                $captured['user_disabled'] = $event;
            },
        );

        $guard = new OidcAccessGuard('spm_users', $em, new NullLogger(), $dispatcher);
        $user = new User('sub-rejected', 'u', 'u@e.fr', 'U');

        try {
            $guard->ensureUserIsAllowed($user, ['autre-groupe']);
            $this->fail('Expected CustomUserMessageAuthenticationException');
        } catch (CustomUserMessageAuthenticationException) {
            // attendu
        }

        $this->assertArrayHasKey('access_denied', $captured);
        $this->assertInstanceOf(AccessDenied::class, $captured['access_denied']);
        $this->assertSame('sub-rejected', $captured['access_denied']->subjectAuthentikId());
        $this->assertSame('security', $captured['access_denied']->category());
        $this->assertSame('access.denied', $captured['access_denied']->action());
        $this->assertSame('oidc_required_groups', $captured['access_denied']->context()['reason']);

        $this->assertArrayHasKey('user_disabled', $captured);
        $this->assertInstanceOf(UserDisabled::class, $captured['user_disabled']);
        $this->assertSame('sub-rejected', $captured['user_disabled']->subjectAuthentikId());
        $this->assertSame('user', $captured['user_disabled']->category());
        $this->assertSame('disabled', $captured['user_disabled']->action());
    }

    public function testAllowedUserDoesNotDispatchEvents(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $dispatched = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            AccessDenied::class,
            static function () use (&$dispatched): void {
                ++$dispatched;
            },
        );
        $dispatcher->addListener(
            UserDisabled::class,
            static function () use (&$dispatched): void {
                ++$dispatched;
            },
        );

        $guard = new OidcAccessGuard('spm_users', $em, new NullLogger(), $dispatcher);
        $user = new User('sub-ok', 'u', 'u@e.fr', 'U');

        $guard->ensureUserIsAllowed($user, ['spm_users']);

        $this->assertSame(0, $dispatched);
    }
}

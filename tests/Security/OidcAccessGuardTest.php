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

        $dispatcher = new EventDispatcher();
        $guard = new OidcAccessGuard('', $em, new NullLogger(), $dispatcher);
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

        $dispatcher = new EventDispatcher();
        /** @var list<object> $dispatched */
        $dispatched = [];
        $dispatcher->addListener(AccessDenied::class, static function (AccessDenied $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });
        $dispatcher->addListener(UserDisabled::class, static function (UserDisabled $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $guard = new OidcAccessGuard('spm_users,admin_spm', $em, new NullLogger(), $dispatcher);
        $user = new User('sub-rejected', 'u', 'u@e.fr', 'U');

        try {
            $guard->ensureUserIsAllowed($user, ['commission-numerique', 'autre-groupe']);
            $this->fail('Expected CustomUserMessageAuthenticationException');
        } catch (CustomUserMessageAuthenticationException $customUserMessageAuthenticationException) {
            $this->assertStringContainsString('Accès non autorisé', $customUserMessageAuthenticationException->getMessageKey());
        }

        $this->assertTrue($user->isDisabled(), 'User must be disabled when rejected');
        $this->assertCount(2, $dispatched, 'AccessDenied + UserDisabled must be dispatched');
        $this->assertInstanceOf(AccessDenied::class, $dispatched[0]);
        $this->assertInstanceOf(UserDisabled::class, $dispatched[1]);
        $this->assertSame('sub-rejected', $dispatched[0]->subjectAuthentikId());
        $this->assertSame('required_groups_mismatch', $dispatched[0]->context()['reason']);
    }

    public function testAlreadyDisabledUserDoesNotReEmitUserDisabled(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $dispatcher = new EventDispatcher();
        $userDisabledCount = 0;
        $dispatcher->addListener(UserDisabled::class, static function () use (&$userDisabledCount): void {
            ++$userDisabledCount;
        });

        $guard = new OidcAccessGuard('spm_users', $em, new NullLogger(), $dispatcher);
        $user = new User('sub', 'u', 'u@e.fr', 'U');
        $user->disable();

        try {
            $guard->ensureUserIsAllowed($user, []);
            $this->fail('Expected CustomUserMessageAuthenticationException');
        } catch (CustomUserMessageAuthenticationException) {
        }

        $this->assertSame(0, $userDisabledCount, 'UserDisabled must not be dispatched if user was already disabled');
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
}

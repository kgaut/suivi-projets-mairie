<?php

declare(strict_types=1);

namespace App\Tests\Application\Event;

use App\Application\Event\AbstractAuditableEvent;
use App\Application\Event\Security\AccessDenied;
use App\Application\Event\Security\LoginFailed;
use App\Application\Event\Security\SessionExpired;
use App\Application\Event\Security\UserLoggedIn;
use App\Application\Event\Security\UserLoggedOut;
use App\Application\Event\User\UserDisabled;
use App\Application\Event\User\UserFirstSeen;
use App\Application\Event\User\UserProfileUpdated;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractAuditableEvent::class)]
#[CoversClass(UserLoggedIn::class)]
#[CoversClass(UserLoggedOut::class)]
#[CoversClass(LoginFailed::class)]
#[CoversClass(AccessDenied::class)]
#[CoversClass(SessionExpired::class)]
#[CoversClass(UserFirstSeen::class)]
#[CoversClass(UserProfileUpdated::class)]
#[CoversClass(UserDisabled::class)]
final class AuditableEventTest extends TestCase
{
    public function testSecurityEventsExposeExpectedCategoryAndAction(): void
    {
        $expected = [
            UserLoggedIn::class => ['security', 'login.success'],
            UserLoggedOut::class => ['security', 'logout'],
            LoginFailed::class => ['security', 'login.failed'],
            AccessDenied::class => ['security', 'access.denied'],
            SessionExpired::class => ['security', 'session.expired'],
            UserFirstSeen::class => ['user', 'first_seen'],
            UserProfileUpdated::class => ['user', 'profile.updated'],
            UserDisabled::class => ['user', 'disabled'],
        ];

        foreach ($expected as $class => [$category, $action]) {
            $event = new $class('user-123');
            $this->assertSame($category, $event->category(), sprintf('category mismatch for %s', $class));
            $this->assertSame($action, $event->action(), sprintf('action mismatch for %s', $class));
        }
    }

    public function testStoresSubjectAndContext(): void
    {
        $event = new AccessDenied('user-456', ['reason' => 'not_in_required_groups', 'groups' => ['agents']]);

        $this->assertSame('user-456', $event->subjectAuthentikId());
        $this->assertSame(['reason' => 'not_in_required_groups', 'groups' => ['agents']], $event->context());
    }

    public function testAcceptsNullSubjectForAnonymousEvents(): void
    {
        $event = new LoginFailed(null, ['reason' => 'invalid_token']);

        $this->assertNull($event->subjectAuthentikId());
        $this->assertSame(['reason' => 'invalid_token'], $event->context());
    }

    public function testOccurredAtDefaultsToNow(): void
    {
        $before = new DateTimeImmutable();
        $event = new UserLoggedIn('user-1');
        $after = new DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $event->occurredAt());
        $this->assertLessThanOrEqual($after, $event->occurredAt());
    }

    public function testOccurredAtCanBeOverridden(): void
    {
        $explicit = new DateTimeImmutable('2026-05-06 10:00:00');
        $event = new UserLoggedIn('user-1', [], $explicit);

        $this->assertSame($explicit, $event->occurredAt());
    }
}

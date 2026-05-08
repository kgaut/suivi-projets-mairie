<?php

declare(strict_types=1);

namespace App\Tests\Application\EventSubscriber;

use App\Application\Event\Security\UserLoggedOut;
use App\Application\EventSubscriber\SecurityLogoutSubscriber;
use App\Domain\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[CoversClass(SecurityLogoutSubscriber::class)]
final class SecurityLogoutSubscriberTest extends TestCase
{
    public function testDispatchesUserLoggedOutWhenTokenHasUser(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UserLoggedOut::class, static function (UserLoggedOut $event) use (&$captured): void {
            $captured = $event;
        });

        $subscriber = new SecurityLogoutSubscriber($dispatcher);
        $user = new User('sub-logout', 'u', 'u@e.fr', 'U');
        $token = new UsernamePasswordToken($user, 'main');

        $event = new LogoutEvent(new Request(), $token);
        $subscriber->onLogout($event);

        $this->assertInstanceOf(UserLoggedOut::class, $captured);
        $this->assertSame('sub-logout', $captured->subjectAuthentikId());
        $this->assertSame('security', $captured->category());
        $this->assertSame('logout', $captured->action());
    }

    public function testDoesNothingWhenTokenIsNull(): void
    {
        $dispatched = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UserLoggedOut::class, static function () use (&$dispatched): void {
            ++$dispatched;
        });

        $subscriber = new SecurityLogoutSubscriber($dispatcher);
        $event = new LogoutEvent(new Request(), null);

        $subscriber->onLogout($event);

        $this->assertSame(0, $dispatched);
    }

    public function testDoesNothingWhenTokenUserIsNotAppUser(): void
    {
        $dispatched = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UserLoggedOut::class, static function () use (&$dispatched): void {
            ++$dispatched;
        });

        $subscriber = new SecurityLogoutSubscriber($dispatcher);
        $event = new LogoutEvent(new Request(), new NullToken());

        $subscriber->onLogout($event);

        $this->assertSame(0, $dispatched);
    }

    public function testGetSubscribedEventsIncludesLogoutEvent(): void
    {
        $events = SecurityLogoutSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(LogoutEvent::class, $events);
        $this->assertSame('onLogout', $events[LogoutEvent::class]);
    }
}

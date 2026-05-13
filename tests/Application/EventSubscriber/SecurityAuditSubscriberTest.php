<?php

declare(strict_types=1);

namespace App\Tests\Application\EventSubscriber;

use App\Application\Event\Security\LoginFailed;
use App\Application\Event\Security\UserLoggedOut;
use App\Application\EventSubscriber\SecurityAuditSubscriber;
use App\Domain\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[CoversClass(SecurityAuditSubscriber::class)]
final class SecurityAuditSubscriberTest extends TestCase
{
    public function testLogoutDispatchesUserLoggedOut(): void
    {
        $dispatcher = new EventDispatcher();
        $captured = null;
        $dispatcher->addListener(UserLoggedOut::class, static function (UserLoggedOut $event) use (&$captured): void {
            $captured = $event;
        });

        $subscriber = new SecurityAuditSubscriber($dispatcher);

        $user = new User('sub-logout', 'u', 'u@e.fr', 'U');
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);

        $request = Request::create('/logout', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']);
        $subscriber->onLogout(new LogoutEvent($request, $token));

        self::assertInstanceOf(UserLoggedOut::class, $captured);
        self::assertSame('sub-logout', $captured->subjectAuthentikId());
        self::assertSame('10.0.0.1', $captured->context()['ip']);
    }

    public function testLogoutWithoutTokenStillDispatches(): void
    {
        $dispatcher = new EventDispatcher();
        $captured = null;
        $dispatcher->addListener(UserLoggedOut::class, static function (UserLoggedOut $event) use (&$captured): void {
            $captured = $event;
        });

        $subscriber = new SecurityAuditSubscriber($dispatcher);
        $subscriber->onLogout(new LogoutEvent(Request::create('/logout'), null));

        self::assertInstanceOf(UserLoggedOut::class, $captured);
        self::assertNull($captured->subjectAuthentikId());
    }

    public function testLoginFailureDispatchesLoginFailed(): void
    {
        $dispatcher = new EventDispatcher();
        $captured = null;
        $dispatcher->addListener(LoginFailed::class, static function (LoginFailed $event) use (&$captured): void {
            $captured = $event;
        });

        $subscriber = new SecurityAuditSubscriber($dispatcher);

        $exception = new AuthenticationException();
        $authenticator = $this->createStub(AuthenticatorInterface::class);
        $request = Request::create('/login_check', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);

        $subscriber->onLoginFailure(new LoginFailureEvent($exception, $authenticator, $request, null, 'main'));

        self::assertInstanceOf(LoginFailed::class, $captured);
        self::assertNull($captured->subjectAuthentikId());
        self::assertSame(AuthenticationException::class, $captured->context()['reason']);
        self::assertSame('203.0.113.7', $captured->context()['ip']);
        self::assertSame('main', $captured->context()['firewall']);
    }

    public function testSubscriberDeclaresExpectedEvents(): void
    {
        $events = SecurityAuditSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(LogoutEvent::class, $events);
        self::assertArrayHasKey(LoginFailureEvent::class, $events);
    }
}

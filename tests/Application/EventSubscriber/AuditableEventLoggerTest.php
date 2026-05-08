<?php

declare(strict_types=1);

namespace App\Tests\Application\EventSubscriber;

use App\Application\Event\Security\AccessDenied;
use App\Application\Event\Security\UserLoggedIn;
use App\Application\Event\User\UserFirstSeen;
use App\Application\EventSubscriber\AuditableEventLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stringable;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(AuditableEventLogger::class)]
final class AuditableEventLoggerTest extends TestCase
{
    public function testSubscribesToEveryAuditableEvent(): void
    {
        $events = AuditableEventLogger::getSubscribedEvents();

        $this->assertArrayHasKey(UserLoggedIn::class, $events);
        $this->assertArrayHasKey(AccessDenied::class, $events);
        $this->assertArrayHasKey(UserFirstSeen::class, $events);
    }

    public function testLogsDispatchedEventsThroughTheConfiguredLogger(): void
    {
        $logger = new InMemoryLogger();
        $dispatcher = new EventDispatcher();
        $subscriber = new AuditableEventLogger($logger);
        $dispatcher->addSubscriber($subscriber);

        $event = new AccessDenied('user-42', ['reason' => 'not_in_required_groups']);
        $dispatcher->dispatch($event, AccessDenied::class);

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertSame('[audit] security.access.denied', $record['message']);
        $this->assertSame('user-42', $record['context']['subject']);
        $this->assertSame(['reason' => 'not_in_required_groups'], $record['context']['context']);
        $this->assertNotEmpty($record['context']['occurred_at']);
    }
}

/**
 * Logger en mémoire minimaliste pour assertions de test.
 */
final class InMemoryLogger extends NullLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

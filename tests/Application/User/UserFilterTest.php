<?php

declare(strict_types=1);

namespace App\Tests\Application\User;

use App\Application\User\UserFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserFilter::class)]
final class UserFilterTest extends TestCase
{
    public function testFromQueryNormalizesAndDefaults(): void
    {
        $filter = UserFilter::fromQuery([]);

        self::assertNull($filter->search);
        self::assertNull($filter->role);
        self::assertNull($filter->group);
        self::assertSame(UserFilter::STATUS_ALL, $filter->status);
        self::assertTrue($filter->isEmpty());
    }

    public function testFromQueryTrimsAndKeepsValues(): void
    {
        $filter = UserFilter::fromQuery([
            'q' => '  Dupont ',
            'role' => 'ROLE_ADMIN',
            'group' => 'mairie-admins',
            'status' => 'active',
        ]);

        self::assertSame('Dupont', $filter->search);
        self::assertSame('ROLE_ADMIN', $filter->role);
        self::assertSame('mairie-admins', $filter->group);
        self::assertSame(UserFilter::STATUS_ACTIVE, $filter->status);
        self::assertFalse($filter->isEmpty());
    }

    public function testFromQueryRejectsUnknownStatus(): void
    {
        $filter = UserFilter::fromQuery(['status' => 'pwned']);

        self::assertSame(UserFilter::STATUS_ALL, $filter->status);
    }

    public function testFromQueryConvertsEmptyStringsToNull(): void
    {
        $filter = UserFilter::fromQuery([
            'q' => '   ',
            'role' => '',
            'group' => '',
        ]);

        self::assertNull($filter->search);
        self::assertNull($filter->role);
        self::assertNull($filter->group);
        self::assertTrue($filter->isEmpty());
    }

    public function testFromQueryIgnoresNonStringValues(): void
    {
        $filter = UserFilter::fromQuery([
            'q' => ['injection'],
            'role' => 42,
            'status' => null,
        ]);

        self::assertNull($filter->search);
        self::assertNull($filter->role);
        self::assertSame(UserFilter::STATUS_ALL, $filter->status);
    }
}

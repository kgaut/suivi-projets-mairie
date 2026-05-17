<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\ExternalLink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExternalLink::class)]
final class ExternalLinkTest extends TestCase
{
    public function testNewLinkHasDefaultsAndTimestamps(): void
    {
        $link = new ExternalLink('Mailpit', 'http://localhost:8025');

        self::assertSame('Mailpit', $link->getLabel());
        self::assertSame('http://localhost:8025', $link->getUrl());
        self::assertNull($link->getIcon());
        self::assertNull($link->getDescription());
        self::assertSame(0, $link->getPosition());
        self::assertTrue($link->isEnabled());
        self::assertSame($link->getCreatedAt()->getTimestamp(), $link->getUpdatedAt()->getTimestamp());
    }

    public function testIdIsUuidV7(): void
    {
        $link = new ExternalLink('X', 'https://x.test');

        // Uuid v7 : 36 caractères, version `7` au 15ᵉ caractère.
        $id = $link->getId()->toRfc4122();
        self::assertSame(36, \strlen($id));
        self::assertSame('7', $id[14]);
    }

    public function testSettersBumpUpdatedAt(): void
    {
        $link = new ExternalLink('Mailpit', 'http://localhost:8025');
        $createdAt = $link->getCreatedAt();
        // L'horloge interne a une résolution µs ; on attend > 1 µs pour garantir
        // un updatedAt strictement supérieur.
        usleep(2);

        $link->setLabel('Mailpit v2');

        self::assertSame('Mailpit v2', $link->getLabel());
        self::assertGreaterThan($createdAt, $link->getUpdatedAt());
    }

    public function testEnableDisableIdempotent(): void
    {
        $link = new ExternalLink('X', 'https://x.test');

        $link->enable();
        self::assertTrue($link->isEnabled());

        $link->disable();
        self::assertFalse($link->isEnabled());

        // Une seconde désactivation ne doit pas changer updatedAt (no-op).
        $afterFirstDisable = $link->getUpdatedAt();
        usleep(2);
        $link->disable();
        self::assertSame($afterFirstDisable, $link->getUpdatedAt());
    }

    public function testNullableFieldsAcceptNullExplicitly(): void
    {
        $link = new ExternalLink('X', 'https://x.test');

        $link->setIcon('🛠');
        $link->setDescription('Outil');
        self::assertSame('🛠', $link->getIcon());
        self::assertSame('Outil', $link->getDescription());

        $link->setIcon(null);
        $link->setDescription(null);
        self::assertNull($link->getIcon());
        self::assertNull($link->getDescription());
    }
}

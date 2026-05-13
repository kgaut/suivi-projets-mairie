<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Domain\ExternalLink;
use App\Form\ExternalLinkInput;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExternalLinkInput::class)]
final class ExternalLinkInputTest extends TestCase
{
    public function testFromEntityCopiesAllFields(): void
    {
        $link = new ExternalLink('Mailpit', 'http://localhost:8025');
        $link->setIcon('📧');
        $link->setDescription('Aperçu des mails');
        $link->setPosition(10);
        $link->disable();

        $input = ExternalLinkInput::fromEntity($link);

        self::assertSame('Mailpit', $input->label);
        self::assertSame('http://localhost:8025', $input->url);
        self::assertSame('📧', $input->icon);
        self::assertSame('Aperçu des mails', $input->description);
        self::assertSame(10, $input->position);
        self::assertFalse($input->enabled);
    }

    public function testApplyToWritesValuesAndNormalizesBlanks(): void
    {
        $link = new ExternalLink('Old', 'https://old.test');

        $input = new ExternalLinkInput();
        $input->label = 'Nouveau';
        $input->url = 'https://nouveau.test';
        $input->icon = '   ';
        $input->description = '';
        $input->position = 3;
        $input->enabled = false;

        $input->applyTo($link);

        self::assertSame('Nouveau', $link->getLabel());
        self::assertSame('https://nouveau.test', $link->getUrl());
        self::assertNull($link->getIcon(), 'Une chaîne uniquement blanche doit être stockée comme null.');
        self::assertNull($link->getDescription(), 'Une chaîne vide doit être stockée comme null.');
        self::assertSame(3, $link->getPosition());
        self::assertFalse($link->isEnabled());
    }

    public function testToNewEntityCreatesLink(): void
    {
        $input = new ExternalLinkInput();
        $input->label = 'GitLab';
        $input->url = 'https://gitlab.example.fr';
        $input->position = 1;
        $input->enabled = true;

        $link = $input->toNewEntity();

        self::assertSame('GitLab', $link->getLabel());
        self::assertSame('https://gitlab.example.fr', $link->getUrl());
        self::assertSame(1, $link->getPosition());
        self::assertTrue($link->isEnabled());
    }

    public function testApplyToFailsIfRequiredFieldsAreMissing(): void
    {
        $link = new ExternalLink('X', 'https://x.test');

        $input = new ExternalLinkInput();
        $input->label = null;
        $input->url = null;

        self::expectException(LogicException::class);
        $input->applyTo($link);
    }
}

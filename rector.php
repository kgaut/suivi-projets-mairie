<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Symfony\Symfony73\Rector\Class_\GetFunctionsToAsTwigFunctionAttributeRector;

/*
 * Configuration Rector pour Suivi Projets Mairie.
 *
 * Utilise `withComposerBased()` qui détecte les versions Symfony / Doctrine
 * / PHPUnit depuis composer.json et applique les sets correspondants.
 *
 * Les `withPreparedSets()` couvrent les améliorations PHP générales.
 */

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/src/Kernel.php',

        // Pas de promotion constructeur sur les entités Doctrine : les
        // attributs `#[ORM\Column(...)]` deviennent illisibles quand ils
        // doivent vivre dans la signature du constructeur, surtout sur
        // les entités qui ont déjà 10+ colonnes (cf. User, ExternalLink).
        ClassPropertyAssignToConstructorPromotionRector::class => [
            __DIR__ . '/src/Domain',
        ],

        // On garde le style historique `extends AbstractExtension` +
        // `getFunctions()` pour les Twig extensions. L'attribut
        // `#[AsTwigFunction]` (Twig 3.10+) marche aussi mais coexister
        // les deux styles dans le projet n'apporte rien.
        GetFunctionsToAsTwigFunctionAttributeRector::class,
    ])
    ->withPhpSets(php84: true)
    ->withComposerBased(
        symfony: true,
        doctrine: true,
        phpunit: true,
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        instanceOf: true,
    )
    ->withImportNames(removeUnusedImports: true)
    ->withCache(cacheDirectory: __DIR__ . '/var/cache/rector');

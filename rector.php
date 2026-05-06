<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

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

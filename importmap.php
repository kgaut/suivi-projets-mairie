<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * Note : les entrées avec `version` sont téléchargées depuis jsDelivr lors
 * d'un `php bin/console importmap:install`, et stockées dans assets/vendor/
 * (gitignored). Les entrées avec `path` pointent vers des fichiers locaux,
 * généralement exposés par les bundles Symfony UX dans `vendor/symfony/...`.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@hotwired/turbo' => [
        'version' => '8.0.13',
    ],
    '@symfony/ux-turbo/turbo_controller' => [
        'path' => './vendor/symfony/ux-turbo/assets/dist/turbo_controller.js',
    ],
    '@symfony/ux-turbo/turbo_stream_controller' => [
        'path' => './vendor/symfony/ux-turbo/assets/dist/turbo_stream_controller.js',
    ],
    '@symfony/ux-live-component' => [
        'path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js',
    ],
    'tslib' => [
        'version' => '2.8.1',
    ],
];

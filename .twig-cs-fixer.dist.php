<?php

declare(strict_types=1);

/*
 * Configuration Twig CS Fixer pour Suivi Projets Mairie.
 *
 * Documentation : https://github.com/VincentLanglet/Twig-CS-Fixer
 */

use TwigCsFixer\Config\Config;
use TwigCsFixer\File\Finder;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Standard\Symfony;
use TwigCsFixer\Standard\TwigCsFixer;

$ruleset = new Ruleset();
$ruleset->addStandard(new TwigCsFixer());
$ruleset->addStandard(new Symfony());

$finder = new Finder();
$finder->in(__DIR__ . '/templates');

$config = new Config();
$config->setRuleset($ruleset);
$config->setFinder($finder);
$config->setCacheFile(__DIR__ . '/var/cache/.twig-cs-fixer.cache');

return $config;

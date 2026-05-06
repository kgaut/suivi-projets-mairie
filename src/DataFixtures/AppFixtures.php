<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Placeholder de fixtures applicatives.
 *
 * Sera enrichi au Lot 1 avec les WorkingGroup et utilisateurs de
 * démonstration pour les environnements de dev / staging / test.
 */
final class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // À implémenter au Lot 1.
        $manager->flush();
    }
}

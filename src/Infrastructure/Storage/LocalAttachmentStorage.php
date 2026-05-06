<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Storage\AttachmentStorageInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Implémentation locale (filesystem) de `AttachmentStorageInterface`.
 *
 * - Stockage physique dans `%kernel.project_dir%/var/uploads/` en dev,
 *   monté sur un volume Docker `uploads:/app/var/uploads` en prod
 *   (cf. `docker-compose.prod.yml`).
 * - URL publique : `/uploads/{path}`. Le serveur web (FrankenPHP/Caddy)
 *   sert directement ce dossier (aucune route Symfony — éviter de
 *   hopper PHP pour servir des images statiques).
 *
 * À remplacer par une implémentation GED externe au moment opportun
 * (Lot 4 ou plus tard, cf. specs §6).
 */
final class LocalAttachmentStorage implements AttachmentStorageInterface
{
    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly string $rootDir,
        private readonly string $publicPrefix = '/uploads/',
    ) {
        $this->filesystem = new Filesystem();
    }

    public function store(string $path, string $contents): void
    {
        $absolute = $this->absolute($path);
        $this->filesystem->mkdir(\dirname($absolute));
        $this->filesystem->dumpFile($absolute, $contents);
    }

    public function delete(string $path): void
    {
        $absolute = $this->absolute($path);
        if ($this->filesystem->exists($absolute)) {
            $this->filesystem->remove($absolute);
        }
    }

    public function exists(string $path): bool
    {
        return $this->filesystem->exists($this->absolute($path));
    }

    public function publicUrl(string $path): string
    {
        return $this->publicPrefix.ltrim($path, '/');
    }

    private function absolute(string $path): string
    {
        // Sécurité : refuse les paths qui essayent de remonter via `..`
        $normalized = ltrim($path, '/');
        if (str_contains($normalized, '..')) {
            throw new \InvalidArgumentException(\sprintf('Invalid storage path: "%s"', $path));
        }

        return rtrim($this->rootDir, '/').'/'.$normalized;
    }
}

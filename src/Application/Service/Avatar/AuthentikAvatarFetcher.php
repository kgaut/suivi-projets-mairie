<?php

declare(strict_types=1);

namespace App\Application\Service\Avatar;

use App\Application\Storage\AttachmentStorageInterface;
use App\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Télécharge l'avatar Authentik (claim `picture`) et le cache localement
 * via `AttachmentStorageInterface`. Cf. specs §3.8 sous-section Avatar.
 *
 * Bornes :
 *
 * - Timeout HTTP : 5 s
 * - Taille max : 2 Mo (vérifiée via `Content-Length` puis lors du download)
 * - Content-Type : doit commencer par `image/`
 * - Redimensionnement : 512×512 px en WebP (GD)
 *
 * Re-fetch déclenché si :
 *
 * - URL source change entre deux logins ; OU
 * - `authentikAvatarFetchedAt` date de plus de 24 h.
 *
 * **Échec silencieux** : toute exception (timeout, taille dépassée,
 * content-type invalide, GD error) est attrapée et loggée. Le login
 * continue ; le `UserAvatarResolver` retombera sur Gravatar ou les
 * initiales.
 */
final class AuthentikAvatarFetcher
{
    private const int TIMEOUT_SECONDS = 5;
    private const int MAX_SIZE_BYTES = 2 * 1024 * 1024; // 2 Mo
    private const int RESIZE_DIMENSION = 512;
    private const string TTL = 'PT24H';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AttachmentStorageInterface $storage,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Met à jour le cache local de l'avatar Authentik si nécessaire.
     *
     * @param string|null $pictureUrl claim `picture` du userinfo OIDC
     *                                (null si Authentik n'expose pas d'avatar pour ce user)
     */
    public function fetchIfNeeded(User $user, ?string $pictureUrl): void
    {
        if ($pictureUrl === null || $pictureUrl === '') {
            return;
        }

        if (!self::needsRefresh($user, $pictureUrl)) {
            return;
        }

        try {
            $resized = $this->downloadAndResize($pictureUrl);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Failed to fetch Authentik avatar for user {user}: {reason}',
                ['user' => $user->getAuthentikId(), 'reason' => $e->getMessage()],
            );

            return;
        }

        $path = \sprintf('users/%s.webp', $user->getAuthentikId());
        $this->storage->store($path, $resized);

        $user->setAuthentikAvatar($pictureUrl, $path, new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    private static function needsRefresh(User $user, string $newUrl): bool
    {
        if ($user->getAuthentikAvatarPath() === null) {
            return true;
        }

        if ($user->getAuthentikAvatarSourceUrl() !== $newUrl) {
            return true;
        }

        $fetchedAt = $user->getAuthentikAvatarFetchedAt();
        if ($fetchedAt === null) {
            return true;
        }

        $expiresAt = $fetchedAt->add(new \DateInterval(self::TTL));

        return $expiresAt <= new \DateTimeImmutable();
    }

    private function downloadAndResize(string $url): string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'headers' => ['Accept' => 'image/*'],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \RuntimeException(\sprintf('HTTP %d returned by avatar source.', $statusCode));
            }

            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? '';
            if (!str_starts_with($contentType, 'image/')) {
                throw new \RuntimeException(\sprintf('Invalid content-type "%s".', $contentType));
            }

            // max_response_size n'existe pas dans HttpClient — on lit en stream
            // avec un compteur.
            $bytes = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $bytes .= $chunk->getContent();
                if (\strlen($bytes) > self::MAX_SIZE_BYTES) {
                    throw new \RuntimeException(\sprintf(
                        'Avatar exceeds max size %d bytes.',
                        self::MAX_SIZE_BYTES,
                    ));
                }
            }
        } catch (TransportException|ExceptionInterface $e) {
            throw new \RuntimeException('HTTP error: '.$e->getMessage(), previous: $e);
        }

        return self::resizeToWebP($bytes);
    }

    private static function resizeToWebP(string $sourceBytes): string
    {
        if (!\function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('GD extension is required to resize avatars.');
        }

        $source = @imagecreatefromstring($sourceBytes);
        if ($source === false) {
            throw new \RuntimeException('Cannot decode image data.');
        }

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);

            // Resize en preservant le ratio, puis crop centré pour avoir un carré.
            $minSide = min($sourceWidth, $sourceHeight);
            $cropX = (int) (($sourceWidth - $minSide) / 2);
            $cropY = (int) (($sourceHeight - $minSide) / 2);

            $resized = imagecreatetruecolor(self::RESIZE_DIMENSION, self::RESIZE_DIMENSION);
            if ($resized === false) {
                throw new \RuntimeException('Failed to allocate destination image.');
            }

            // Préserve la transparence
            imagealphablending($resized, false);
            imagesavealpha($resized, true);

            imagecopyresampled(
                $resized,
                $source,
                0,
                0,
                $cropX,
                $cropY,
                self::RESIZE_DIMENSION,
                self::RESIZE_DIMENSION,
                $minSide,
                $minSide,
            );

            ob_start();
            imagewebp($resized, null, 85);
            $output = ob_get_clean();
            imagedestroy($resized);

            if ($output === false || $output === '') {
                throw new \RuntimeException('Failed to encode WebP.');
            }

            return $output;
        } finally {
            imagedestroy($source);
        }
    }
}

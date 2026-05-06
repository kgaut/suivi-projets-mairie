<?php

declare(strict_types=1);

namespace App\Tests\Application\Service\Avatar;

use App\Application\Service\Avatar\AuthentikAvatarFetcher;
use App\Application\Storage\AttachmentStorageInterface;
use App\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(AuthentikAvatarFetcher::class)]
#[AllowMockObjectsWithoutExpectations]
final class AuthentikAvatarFetcherTest extends TestCase
{
    /** PNG 4×4 valide généré par GD à la volée (bytes valides garantis). */
    private static function validPng(): string
    {
        $im = imagecreatetruecolor(4, 4);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 100, 50));
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);

        return $bytes ?: '';
    }

    public function testNullPictureUrlIsNoOp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $storage = $this->createMock(AttachmentStorageInterface::class);
        $storage->expects($this->never())->method('store');

        $fetcher = new AuthentikAvatarFetcher(new MockHttpClient(), $storage, $em);
        $user = new User('sub', 'u', 'u@e.fr', 'U');

        $fetcher->fetchIfNeeded($user, null);

        $this->assertNull($user->getAuthentikAvatarPath());
    }

    public function testEmptyPictureUrlIsNoOp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $fetcher = new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em);
        $user = new User('sub', 'u', 'u@e.fr', 'U');

        $fetcher->fetchIfNeeded($user, '');

        $this->assertNull($user->getAuthentikAvatarPath());
    }

    public function testCachedAvatarFreshIsNoOp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $http = new MockHttpClient([]);
        $fetcher = new AuthentikAvatarFetcher($http, $this->createStub(AttachmentStorageInterface::class), $em);

        $user = new User('sub', 'u', 'u@e.fr', 'U');
        $user->setAuthentikAvatar(
            'https://idp.example.fr/picture.png',
            'users/sub.webp',
            new \DateTimeImmutable('-1 hour'),  // Frais (< 24h)
        );

        $fetcher->fetchIfNeeded($user, 'https://idp.example.fr/picture.png');

        // Pas de download attendu — la version cache est encore valide.
        $this->assertSame(0, $http->getRequestsCount());
    }

    public function testStaleCacheTriggersRefresh(): void
    {
        if (!\function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD extension not available.');
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $captured = null;
        $storage = $this->createMock(AttachmentStorageInterface::class);
        $storage->expects($this->once())->method('store')->willReturnCallback(
            static function (string $path, string $contents) use (&$captured): void {
                $captured = ['path' => $path, 'size' => \strlen($contents)];
            },
        );

        $http = new MockHttpClient([
            new MockResponse(self::validPng(), [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'image/png'],
            ]),
        ]);

        $fetcher = new AuthentikAvatarFetcher($http, $storage, $em);

        $user = new User('sub-stale', 'u', 'u@e.fr', 'U');
        $user->setAuthentikAvatar(
            'https://idp.example.fr/picture.png',
            'users/sub-stale.webp',
            new \DateTimeImmutable('-25 hours'),  // Périmé
        );

        $fetcher->fetchIfNeeded($user, 'https://idp.example.fr/picture.png');

        $this->assertNotNull($captured);
        $this->assertSame('users/sub-stale.webp', $captured['path']);
        $this->assertGreaterThan(0, $captured['size']);
    }

    public function testNewUrlTriggersRefresh(): void
    {
        if (!\function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD extension not available.');
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $storage = $this->createMock(AttachmentStorageInterface::class);
        $storage->expects($this->once())->method('store');

        $http = new MockHttpClient([
            new MockResponse(self::validPng(), [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'image/png'],
            ]),
        ]);

        $fetcher = new AuthentikAvatarFetcher($http, $storage, $em);

        $user = new User('sub-newurl', 'u', 'u@e.fr', 'U');
        $user->setAuthentikAvatar(
            'https://idp.example.fr/old-picture.png',
            'users/sub-newurl.webp',
            new \DateTimeImmutable('-1 hour'),  // Frais MAIS l'URL change
        );

        $fetcher->fetchIfNeeded($user, 'https://idp.example.fr/new-picture.png');

        $this->assertSame('https://idp.example.fr/new-picture.png', $user->getAuthentikAvatarSourceUrl());
    }

    public function testNon200ResponseIsLoggedAndIgnored(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $storage = $this->createMock(AttachmentStorageInterface::class);
        $storage->expects($this->never())->method('store');

        $http = new MockHttpClient([new MockResponse('not found', ['http_code' => 404])]);
        $fetcher = new AuthentikAvatarFetcher($http, $storage, $em);

        $user = new User('sub', 'u', 'u@e.fr', 'U');

        // Ne doit PAS lever d'exception (échec silencieux).
        $fetcher->fetchIfNeeded($user, 'https://idp.example.fr/picture.png');

        $this->assertNull($user->getAuthentikAvatarPath());
    }

    public function testInvalidContentTypeIsIgnored(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $storage = $this->createMock(AttachmentStorageInterface::class);
        $storage->expects($this->never())->method('store');

        $http = new MockHttpClient([
            new MockResponse('<html>', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'text/html'],
            ]),
        ]);
        $fetcher = new AuthentikAvatarFetcher($http, $storage, $em);

        $user = new User('sub', 'u', 'u@e.fr', 'U');
        $fetcher->fetchIfNeeded($user, 'https://idp.example.fr/picture.png');

        $this->assertNull($user->getAuthentikAvatarPath());
    }

    public function testOversizedResponseIsIgnored(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $storage = $this->createMock(AttachmentStorageInterface::class);
        $storage->expects($this->never())->method('store');

        // 3 Mo > limite 2 Mo
        $hugeBytes = str_repeat("\x00", 3 * 1024 * 1024);
        $http = new MockHttpClient([
            new MockResponse($hugeBytes, [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'image/png'],
            ]),
        ]);
        $fetcher = new AuthentikAvatarFetcher($http, $storage, $em);

        $user = new User('sub', 'u', 'u@e.fr', 'U');
        $fetcher->fetchIfNeeded($user, 'https://idp.example.fr/picture.png');

        $this->assertNull($user->getAuthentikAvatarPath());
    }
}

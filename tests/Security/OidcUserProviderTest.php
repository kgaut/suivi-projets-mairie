<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Application\Service\Avatar\AuthentikAvatarFetcher;
use App\Application\Storage\AttachmentStorageInterface;
use App\Domain\User;
use App\Infrastructure\Repository\UserRepository;
use App\Security\OidcAccessGuard;
use App\Security\OidcUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use Drenso\OidcBundle\Model\OidcTokens;
use Drenso\OidcBundle\Model\OidcUserData;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

#[CoversClass(OidcUserProvider::class)]
#[AllowMockObjectsWithoutExpectations] // certains mocks servent de stubs (loadUserByIdentifier, etc.)
final class OidcUserProviderTest extends TestCase
{
    private function tokens(): OidcTokens
    {
        $raw = new stdClass();
        $raw->id_token = 'fake-id-token';
        $raw->access_token = 'fake-access-token';

        return new OidcTokens($raw);
    }

    public function testEnsureUserExistsCreatesUserIfNotFound(): void
    {
        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->willReturnCallback(
            static function (User $u) use (&$persisted): void {
                $persisted = $u;
            },
        );
        $em->expects($this->once())->method('flush');

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findOneByAuthentikId')
            ->with('sub-new')
            ->willReturn(null);

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), 'admin_spm');

        $provider->ensureUserExists('sub-new', new OidcUserData([
            'sub' => 'sub-new',
            'preferred_username' => 'jdupont',
            'email' => 'jean.dupont@mairie.example.fr',
            'name' => 'Jean Dupont',
            'groups' => ['commission-numerique', 'agents-services-techniques'],
        ]), $this->tokens());

        $this->assertInstanceOf(User::class, $persisted);
        $this->assertSame('sub-new', $persisted->getAuthentikId());
        $this->assertSame('jdupont', $persisted->getUsername());
        $this->assertSame('Jean Dupont', $persisted->getDisplayName());
        $this->assertSame(
            ['commission-numerique', 'agents-services-techniques'],
            $persisted->getGroupsSnapshot(),
        );
        $this->assertSame(['ROLE_USER'], $persisted->getRoles());
        $this->assertNotNull($persisted->getLastLoginAt());
    }

    public function testEnsureUserExistsAttributesAdminRoleIfInAdminGroup(): void
    {
        $captured = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->willReturnCallback(
            static function (User $u) use (&$captured): void {
                $captured = $u;
            },
        );
        $em->expects($this->once())->method('flush');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('findOneByAuthentikId')->willReturn(null);

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), 'admin_spm');

        $provider->ensureUserExists('sub-admin', new OidcUserData([
            'sub' => 'sub-admin',
            'preferred_username' => 'alice',
            'email' => 'alice@mairie.example.fr',
            'name' => 'Alice Admin',
            'groups' => ['admin_spm', 'commission-numerique'],
        ]), $this->tokens());

        $this->assertNotNull($captured);
        $this->assertContains('ROLE_ADMIN', $captured->getRoles());
        $this->assertContains('ROLE_USER', $captured->getRoles());
    }

    public function testEnsureUserExistsUpdatesExistingUserOnLogin(): void
    {
        $existing = new User('sub-existing', 'old_username', 'old@example.fr', 'Old Name');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('findOneByAuthentikId')->willReturn($existing);

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), 'admin_spm');

        $provider->ensureUserExists('sub-existing', new OidcUserData([
            'sub' => 'sub-existing',
            'preferred_username' => 'new_username',
            'email' => 'new@mairie.example.fr',
            'name' => 'New Name',
            'groups' => ['commission-numerique'],
        ]), $this->tokens());

        $this->assertSame('new_username', $existing->getUsername());
        $this->assertSame('new@mairie.example.fr', $existing->getEmail());
        $this->assertSame('New Name', $existing->getDisplayName());
        $this->assertSame(['commission-numerique'], $existing->getGroupsSnapshot());
        $this->assertSame(['ROLE_USER'], $existing->getRoles());
    }

    public function testEnsureUserExistsHandlesMissingGroupsClaim(): void
    {
        $captured = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->willReturnCallback(
            static function (User $u) use (&$captured): void {
                $captured = $u;
            },
        );
        $em->expects($this->once())->method('flush');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('findOneByAuthentikId')->willReturn(null);

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), 'admin_spm');

        $provider->ensureUserExists('sub-x', new OidcUserData([
            'sub' => 'sub-x',
            'preferred_username' => 'u',
            'email' => 'u@e.fr',
            'name' => 'U',
            // claim "groups" absent
        ]), $this->tokens());

        $this->assertNotNull($captured);
        $this->assertSame([], $captured->getGroupsSnapshot());
        $this->assertSame(['ROLE_USER'], $captured->getRoles());
    }

    public function testLoadUserByIdentifierReturnsUserWhenFound(): void
    {
        $existing = new User('sub-found', 'u', 'u@e.fr', 'U');

        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findOneByAuthentikId')
            ->with('sub-found')
            ->willReturn($existing);

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), 'admin_spm');

        $this->assertSame($existing, $provider->loadUserByIdentifier('sub-found'));
    }

    public function testLoadUserByIdentifierThrowsWhenNotFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findOneByAuthentikId')->willReturn(null);

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), 'admin_spm');

        $this->expectException(UserNotFoundException::class);
        $provider->loadUserByIdentifier('sub-missing');
    }

    public function testSupportsClassRecognisesUser(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserRepository::class);
        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), 'admin_spm');

        $this->assertTrue($provider->supportsClass(User::class));
        $this->assertFalse($provider->supportsClass(stdClass::class));
    }
}

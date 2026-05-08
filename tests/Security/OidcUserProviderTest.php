<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Application\Event\Security\UserLoggedIn;
use App\Application\Event\User\UserFirstSeen;
use App\Application\Event\User\UserProfileUpdated;
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
use Psr\Log\NullLogger;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
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

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em, new NullLogger(), new EventDispatcher()), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), new EventDispatcher(), 'admin_spm');

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

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em, new NullLogger(), new EventDispatcher()), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), new EventDispatcher(), 'admin_spm');

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

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em, new NullLogger(), new EventDispatcher()), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), new EventDispatcher(), 'admin_spm');

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

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em, new NullLogger(), new EventDispatcher()), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), new EventDispatcher(), 'admin_spm');

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

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em, new NullLogger(), new EventDispatcher()), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), new EventDispatcher(), 'admin_spm');

        $this->assertSame($existing, $provider->loadUserByIdentifier('sub-found'));
    }

    public function testLoadUserByIdentifierThrowsWhenNotFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findOneByAuthentikId')->willReturn(null);

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em, new NullLogger(), new EventDispatcher()), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), new EventDispatcher(), 'admin_spm');

        $this->expectException(UserNotFoundException::class);
        $provider->loadUserByIdentifier('sub-missing');
    }

    public function testSupportsClassRecognisesUser(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserRepository::class);
        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em, new NullLogger(), new EventDispatcher()), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), new EventDispatcher(), 'admin_spm');

        $this->assertTrue($provider->supportsClass(User::class));
        $this->assertFalse($provider->supportsClass(stdClass::class));
    }

    public function testNewUserDispatchesUserFirstSeenAndUserLoggedIn(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findOneByAuthentikId')->willReturn(null);

        $captured = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UserFirstSeen::class, static function (UserFirstSeen $e) use (&$captured): void {
            $captured['first_seen'] = $e;
        });
        $dispatcher->addListener(UserLoggedIn::class, static function (UserLoggedIn $e) use (&$captured): void {
            $captured['logged_in'] = $e;
        });
        $dispatcher->addListener(UserProfileUpdated::class, static function (UserProfileUpdated $e) use (&$captured): void {
            $captured['profile_updated'] = $e;
        });

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em, new NullLogger(), new EventDispatcher()), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), $dispatcher, 'admin_spm');

        $provider->ensureUserExists('sub-new', new OidcUserData([
            'sub' => 'sub-new',
            'preferred_username' => 'jdupont',
            'email' => 'jean@mairie.example.fr',
            'name' => 'Jean Dupont',
            'groups' => ['commission-numerique'],
        ]), $this->tokens());

        $this->assertArrayHasKey('first_seen', $captured);
        $this->assertSame('sub-new', $captured['first_seen']->subjectAuthentikId());
        $this->assertSame('user', $captured['first_seen']->category());
        $this->assertSame('first_seen', $captured['first_seen']->action());

        $this->assertArrayHasKey('logged_in', $captured);
        $this->assertSame('sub-new', $captured['logged_in']->subjectAuthentikId());
        $this->assertSame('security', $captured['logged_in']->category());
        $this->assertSame('login.success', $captured['logged_in']->action());

        $this->assertArrayNotHasKey('profile_updated', $captured, 'UserProfileUpdated must not fire on creation');
    }

    public function testReturningUserWithChangedFieldsDispatchesUserProfileUpdated(): void
    {
        $existing = new User('sub-existing', 'old_username', 'old@example.fr', 'Old Name');

        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findOneByAuthentikId')->willReturn($existing);

        $captured = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UserProfileUpdated::class, static function (UserProfileUpdated $e) use (&$captured): void {
            $captured[] = $e;
        });

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em, new NullLogger(), new EventDispatcher()), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), $dispatcher, 'admin_spm');

        $provider->ensureUserExists('sub-existing', new OidcUserData([
            'sub' => 'sub-existing',
            'preferred_username' => 'new_username',
            'email' => 'new@mairie.example.fr',
            'name' => 'New Name',
            'groups' => ['nouveau-groupe'],
        ]), $this->tokens());

        $this->assertCount(1, $captured);
        $event = $captured[0];
        $this->assertSame('sub-existing', $event->subjectAuthentikId());
        $context = $event->context();
        $this->assertArrayHasKey('username', $context);
        $this->assertArrayHasKey('email', $context);
        $this->assertArrayHasKey('display_name', $context);
        $this->assertArrayHasKey('groups', $context);
        $this->assertSame(['before' => 'old_username', 'after' => 'new_username'], $context['username']);
    }

    public function testReturningUserWithoutChangesDoesNotDispatchUserProfileUpdated(): void
    {
        $existing = new User('sub-stable', 'username', 'email@e.fr', 'Display Name');
        $existing->setGroupsSnapshot(['groupe-stable']);

        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findOneByAuthentikId')->willReturn($existing);

        $dispatched = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UserProfileUpdated::class, static function () use (&$dispatched): void {
            ++$dispatched;
        });

        $provider = new OidcUserProvider($repo, $em, new OidcAccessGuard('', $em, new NullLogger(), new EventDispatcher()), new AuthentikAvatarFetcher(new MockHttpClient(), $this->createStub(AttachmentStorageInterface::class), $em), $dispatcher, 'admin_spm');

        $provider->ensureUserExists('sub-stable', new OidcUserData([
            'sub' => 'sub-stable',
            'preferred_username' => 'username',
            'email' => 'email@e.fr',
            'name' => 'Display Name',
            'groups' => ['groupe-stable'],
        ]), $this->tokens());

        $this->assertSame(0, $dispatched);
    }
}

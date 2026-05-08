<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Storage\AttachmentStorageInterface;
use App\Domain\Enum\AvatarSource;
use App\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * Page `/profile` : affiche les infos Authentik de l'utilisateur courant,
 * son avatar (cascade), et permet d'uploader un avatar local + de configurer
 * ses préférences avatar (source forcée, autorisation Gravatar).
 *
 * Cf. specs §3.8 et `docs/lots/lot-0-cadrage.md` §3 Vague 2.
 */
#[Route('/profile', name: 'app_profile_')]
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    private const int AVATAR_MAX_BYTES = 2 * 1024 * 1024;

    private const int AVATAR_RESIZE_DIMENSION = 512;

    /**
     * @var array<string, string> Map MIME → extension acceptée pour l'upload local
     */
    private const array AVATAR_ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(): Response
    {
        return $this->render('profile/show.html.twig', [
            'user' => $this->currentUser(),
            'avatar_sources' => AvatarSource::cases(),
        ]);
    }

    #[Route('/avatar', name: 'avatar_upload', methods: ['POST'])]
    public function uploadAvatar(
        Request $request,
        AttachmentStorageInterface $storage,
        EntityManagerInterface $em,
    ): RedirectResponse {
        $user = $this->currentUser();

        /** @var UploadedFile|null $file */
        $file = $request->files->get('avatar');
        if ($file === null) {
            $this->addFlash('error', 'Aucun fichier reçu.');

            return $this->redirectToRoute('app_profile_show');
        }

        if ($file->getSize() > self::AVATAR_MAX_BYTES) {
            $this->addFlash('error', 'Le fichier dépasse 2 Mo.');

            return $this->redirectToRoute('app_profile_show');
        }

        $mime = $file->getMimeType() ?? '';
        if (!isset(self::AVATAR_ALLOWED_MIMES[$mime])) {
            $this->addFlash('error', 'Format non autorisé (jpg, png, webp uniquement).');

            return $this->redirectToRoute('app_profile_show');
        }

        try {
            $resized = $this->resizeToWebp($file->getPathname());
        } catch (Throwable $throwable) {
            $this->addFlash('error', "Impossible de traiter l'image : " . $throwable->getMessage());

            return $this->redirectToRoute('app_profile_show');
        }

        // Supprime l'ancien upload si présent
        if ($user->getAvatarPath() !== null) {
            $storage->delete($user->getAvatarPath());
        }

        $path = \sprintf('users/upload-%s.webp', $user->getAuthentikId());
        $storage->store($path, $resized);
        $user->setAvatarPath($path);
        $em->flush();

        $this->addFlash('success', 'Avatar mis à jour.');

        return $this->redirectToRoute('app_profile_show');
    }

    #[Route('/avatar/delete', name: 'avatar_delete', methods: ['POST'])]
    public function deleteAvatar(
        AttachmentStorageInterface $storage,
        EntityManagerInterface $em,
    ): RedirectResponse {
        $user = $this->currentUser();
        if ($user->getAvatarPath() !== null) {
            $storage->delete($user->getAvatarPath());
            $user->setAvatarPath(null);
            $em->flush();
            $this->addFlash('success', 'Avatar supprimé.');
        }

        return $this->redirectToRoute('app_profile_show');
    }

    #[Route('/preferences/avatar-source', name: 'preferences_avatar_source', methods: ['POST'])]
    public function preferencesAvatarSource(Request $request, EntityManagerInterface $em): RedirectResponse
    {
        $user = $this->currentUser();

        $sourceValue = (string) $request->request->get('avatar_source', 'auto');
        $source = AvatarSource::tryFrom($sourceValue) ?? AvatarSource::AUTO;
        $user->setAvatarSource($source);

        $em->flush();
        $this->addFlash('success', 'Source d\'avatar mise à jour.');

        return $this->redirectToRoute('app_profile_show');
    }

    #[Route('/preferences/gravatar', name: 'preferences_gravatar', methods: ['POST'])]
    public function preferencesGravatar(Request $request, EntityManagerInterface $em): RedirectResponse
    {
        $user = $this->currentUser();
        $user->setGravatarAllowed($request->request->getBoolean('gravatar_allowed'));

        $em->flush();
        $this->addFlash('success', 'Préférence Gravatar enregistrée.');

        return $this->redirectToRoute('app_profile_show');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new LogicException('Expected an instance of App\Domain\User.');
        }

        return $user;
    }

    private function resizeToWebp(string $path): string
    {
        if (!\function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Extension GD requise.');
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Impossible de lire le fichier uploadé.');
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new RuntimeException('Image invalide.');
        }

        try {
            $w = imagesx($source);
            $h = imagesy($source);
            $minSide = min($w, $h);
            $cropX = (int) (($w - $minSide) / 2);
            $cropY = (int) (($h - $minSide) / 2);

            $dim = self::AVATAR_RESIZE_DIMENSION;
            $resized = imagecreatetruecolor($dim, $dim);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);

            imagecopyresampled($resized, $source, 0, 0, $cropX, $cropY, $dim, $dim, $minSide, $minSide);

            ob_start();
            imagewebp($resized, null, 85);
            $output = ob_get_clean();
            imagedestroy($resized);

            return $output ?: throw new RuntimeException('Encodage WebP échoué.');
        } finally {
            imagedestroy($source);
        }
    }
}

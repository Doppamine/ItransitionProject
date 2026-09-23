<?php

declare(strict_types=1);

namespace App\Controller;

use App\CV\CVViewBuilder;
use App\Entity\ProfileAttributeValue;
use App\Entity\User;
use App\Enum\AttributeType;
use App\Image\CloudinaryImages;
use App\Profile\ProfileValueMapper;
use App\Repository\CVRepository;
use App\Repository\PositionRepository;
use App\Repository\ProfileRepository;
use App\Security\CVVoter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CANDIDATE')]
final class ImageUploadController extends AbstractController
{
    #[Route('/profile/images/{definitionId}/sign', name: 'app_profile_image_sign', requirements: ['definitionId' => '\d+'], methods: ['POST'], defaults: ['scope' => 'profile', 'targetId' => 0])]
    #[Route('/cvs/{targetId}/images/{definitionId}/sign', name: 'app_cv_image_sign', requirements: ['targetId' => '\d+', 'definitionId' => '\d+'], methods: ['POST'], defaults: ['scope' => 'cv'])]
    public function sign(#[CurrentUser] User $user, string $scope, int $targetId, int $definitionId, Request $request, ProfileRepository $profiles, CVRepository $cvs, PositionRepository $positions, CVViewBuilder $builder, CloudinaryImages $images): JsonResponse
    {
        if (!$this->isCsrfTokenValid('image_'.$scope.'_'.$targetId, $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid security token.'], 403);
        }
        $value = $this->editableValue($user, $scope, $targetId, $definitionId, $profiles, $cvs, $positions, $builder);
        if (!$images->configured()) {
            return $this->json(['error' => 'Image upload is unavailable.'], 503);
        }
        $upload = $images->signedUpload((int) $value->getProfile()->getId(), $definitionId);
        $pending = $request->getSession()->get('pending_images', []);
        $pending = is_array($pending) ? $pending : [];
        $pending = array_filter($pending, static fn ($entry): bool => is_array($entry) && isset($entry['issued']) && $entry['issued'] >= time() - 900);
        $pending = array_slice($pending, -9, null, true);
        $pending[$upload['fields']['public_id']] = ['scope' => $scope, 'target' => $targetId, 'definition' => $definitionId, 'issued' => time()];
        $request->getSession()->set('pending_images', $pending);
        return $this->json($upload);
    }

    #[Route('/profile/images/{definitionId}/complete', name: 'app_profile_image_complete', requirements: ['definitionId' => '\d+'], methods: ['POST'], defaults: ['scope' => 'profile', 'targetId' => 0])]
    #[Route('/cvs/{targetId}/images/{definitionId}/complete', name: 'app_cv_image_complete', requirements: ['targetId' => '\d+', 'definitionId' => '\d+'], methods: ['POST'], defaults: ['scope' => 'cv'])]
    public function complete(#[CurrentUser] User $user, string $scope, int $targetId, int $definitionId, Request $request, ProfileRepository $profiles, CVRepository $cvs, PositionRepository $positions, CVViewBuilder $builder, CloudinaryImages $images, ProfileValueMapper $mapper, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('image_'.$scope.'_'.$targetId, $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid security token.'], 403);
        }
        $value = $this->editableValue($user, $scope, $targetId, $definitionId, $profiles, $cvs, $positions, $builder);
        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || !isset($body['public_id'], $body['signature'], $body['version'], $body['profileVersion']) || !is_string($body['public_id']) || !is_string($body['signature']) || !is_int($body['version']) || !is_int($body['profileVersion'])) {
            return $this->json(['error' => 'Invalid upload response.'], 422);
        }
        $key = $body['public_id'];
        $pending = $request->getSession()->get('pending_images', []);
        $entry = is_array($pending) ? ($pending[$key] ?? null) : null;
        if (!is_array($entry) || $entry['scope'] !== $scope || $entry['target'] !== $targetId || $entry['definition'] !== $definitionId || $entry['issued'] < time() - 900 || !$images->verifyResponse($key, $body['version'], $body['signature'])) {
            return $this->json(['error' => 'Image upload could not be verified.'], 422);
        }
        try {
            $em->lock($value->getProfile(), LockMode::OPTIMISTIC, $body['profileVersion']);
            $mapper->setUploadedImage($value, $key);
            $em->flush();
        } catch (OptimisticLockException) {
            return $this->json(['error' => 'Profile changed elsewhere. Reload to continue.'], 409);
        }
        unset($pending[$key]);
        $request->getSession()->set('pending_images', $pending);
        return $this->json(['status' => 'saved', 'version' => $value->getProfile()->getVersion(), 'url' => $images->url($key)]);
    }

    private function editableValue(User $user, string $scope, int $targetId, int $definitionId, ProfileRepository $profiles, CVRepository $cvs, PositionRepository $positions, CVViewBuilder $builder): ProfileAttributeValue
    {
        if ($scope === 'profile') {
            $profile = $profiles->findForUser($user) ?? throw $this->createNotFoundException();
        } elseif ($scope === 'cv') {
            $cv = $cvs->findDetailed($targetId) ?? throw $this->createNotFoundException();
            $positions->hydrateChildren([$cv->getPosition()]);
            $this->denyAccessUnlessGranted(CVVoter::EDIT, $cv);
            $profile = $cv->getProfile();
            $profiles->hydrateValues([$profile]);
            if (!isset($builder->renderedDefinitions($cv)[$definitionId])) {
                throw $this->createAccessDeniedException();
            }
        } else {
            throw $this->createNotFoundException();
        }
        foreach ($profile->getValues() as $value) {
            if ($value->getDefinition()->getId() === $definitionId && $value->getDefinition()->getType() === AttributeType::IMAGE) {
                return $value;
            }
        }
        throw $this->createAccessDeniedException();
    }
}

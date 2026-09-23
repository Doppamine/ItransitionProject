<?php

declare(strict_types=1);

namespace App\Controller;

use App\CV\CVViewBuilder;
use App\Entity\CV;
use App\Entity\Position;
use App\Entity\User;
use App\Enum\CVStatus;
use App\Position\PositionEligibilityChecker;
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

final class CVController extends AbstractController
{
    #[Route('/positions/{id}/cv', name: 'app_cv_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_CANDIDATE')]
    public function create(#[CurrentUser] User $user, int $id, Request $request, PositionRepository $positions, ProfileRepository $profiles, CVRepository $cvs, PositionEligibilityChecker $eligibility, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('cv_create_'.$id, $request->request->get('_token'))) {
            return new Response('Invalid security token.', 403);
        }

        $cv = $em->wrapInTransaction(function () use ($user, $id, $positions, $profiles, $cvs, $eligibility, $em): CV {
            $profile = $profiles->findOneBy(['user' => $user]) ?? throw $this->createNotFoundException('Profile not found.');
            $em->lock($profile, LockMode::PESSIMISTIC_WRITE);
            $profiles->hydrateValues([$profile]);
            $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
            if (!$eligibility->isEligible($position, $profile)) {
                throw $this->createAccessDeniedException();
            }
            $existing = $cvs->findOneBy(['profile' => $profile, 'position' => $position]);
            if ($existing !== null) {
                return $existing;
            }

            foreach ($position->getAttributes() as $attribute) {
                $profile->selectAttribute($attribute->getDefinition());
            }
            $cv = new CV($profile, $position);
            $em->persist($cv);
            return $cv;
        });

        return $this->redirectToRoute('app_cv_show', ['id' => $cv->getId()]);
    }

    #[Route('/cvs', name: 'app_cv_index', methods: ['GET'])]
    #[IsGranted('ROLE_CANDIDATE')]
    public function index(#[CurrentUser] User $user, ProfileRepository $profiles, CVRepository $cvs, PositionRepository $positions): Response
    {
        $profile = $profiles->findForEligibility($user) ?? throw $this->createNotFoundException('Profile not found.');
        $items = $cvs->findForProfile($profile);
        $positions->hydrateChildren(array_map(static fn (CV $cv): Position => $cv->getPosition(), $items));
        $visible = array_values(array_filter($items, fn (CV $cv): bool => $this->isGranted(CVVoter::VIEW, $cv)));

        return $this->render('cv/index.html.twig', ['cvs' => $visible]);
    }

    #[Route('/cvs/{id}', name: 'app_cv_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function show(int $id, CVRepository $cvs, PositionRepository $positions, ProfileRepository $profiles, CVViewBuilder $builder): Response
    {
        $cv = $this->detailed($id, $cvs, $positions, $profiles);
        $this->denyAccessUnlessGranted(CVVoter::VIEW, $cv);

        return $this->render('cv/show.html.twig', [
            'cv' => $cv,
            'view' => $builder->build($cv),
            'editable' => $this->isGranted(CVVoter::EDIT, $cv),
            'publishable' => $cv->getStatus() === CVStatus::DRAFT && $this->isGranted(CVVoter::PUBLISH, $cv),
        ]);
    }

    #[Route('/cvs/{id}/autosave', name: 'app_cv_autosave', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function autosave(int $id, Request $request, CVRepository $cvs, PositionRepository $positions, ProfileRepository $profiles, CVViewBuilder $builder, ProfileValueMapper $mapper, EntityManagerInterface $em): JsonResponse
    {
        $cv = $this->detailed($id, $cvs, $positions, $profiles);
        $this->denyAccessUnlessGranted(CVVoter::EDIT, $cv);
        if (!$this->isCsrfTokenValid('cv_edit_'.$id, $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid security token.'], 403);
        }
        try {
            $body = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid request.'], 422);
        }
        if (!is_array($body) || !isset($body['version'], $body['changes']) || !is_int($body['version']) || $body['version'] < 1 || !is_array($body['changes']) || count($body['changes']) > 100) {
            return $this->json(['error' => 'Invalid request.'], 422);
        }

        $profile = $cv->getProfile();
        try {
            $em->lock($profile, LockMode::OPTIMISTIC, $body['version']);
            $rendered = $builder->renderedDefinitions($cv);
            foreach ($body['changes'] as $key => $input) {
                if (!ctype_digit((string) $key) || !isset($rendered[(int) $key])) {
                    return $this->json(['error' => 'Invalid attribute.'], 422);
                }
            }
            foreach ($body['changes'] as $key => $input) {
                try {
                    $value = $profile->selectAttribute($rendered[(int) $key]);
                    $mapper->apply($value, $input);
                } catch (\InvalidArgumentException $exception) {
                    $em->clear();
                    return $this->json(['error' => 'Invalid value.', 'field' => (string) $key], 422);
                }
            }
            if ($body['changes'] !== []) {
                $em->flush();
            }
        } catch (OptimisticLockException) {
            return $this->json(['error' => 'Profile changed elsewhere. Reload to continue.'], 409);
        }

        return $this->json(['status' => 'saved', 'version' => $profile->getVersion()]);
    }

    #[Route('/cvs/{id}/publish', name: 'app_cv_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function publish(int $id, Request $request, CVRepository $cvs, PositionRepository $positions, ProfileRepository $profiles, CVViewBuilder $builder, EntityManagerInterface $em): Response
    {
        $cv = $this->detailed($id, $cvs, $positions, $profiles);
        $this->denyAccessUnlessGranted(CVVoter::PUBLISH, $cv);
        if (!$this->isCsrfTokenValid('cv_publish_'.$id, $request->request->get('_token'))) {
            return new Response('Invalid security token.', 403);
        }
        if (!$builder->isComplete($cv)) {
            return new Response('Complete all highlighted CV attributes before publishing.', 422);
        }
        $cv->publish();
        $em->flush();
        return $this->redirectToRoute('app_cv_show', ['id' => $id]);
    }

    #[Route('/positions/{id}/cvs', name: 'app_position_cvs', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function positionCVs(int $id, PositionRepository $positions, CVRepository $cvs, ProfileRepository $profiles): Response
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        $items = $cvs->findForPosition($position, !$this->isGranted('ROLE_ADMIN'));
        $profiles->hydrateValues(array_map(static fn (CV $cv) => $cv->getProfile(), $items));
        $items = array_values(array_filter($items, fn (CV $cv): bool => $this->isGranted(CVVoter::VIEW, $cv)));
        return $this->render('cv/position_index.html.twig', ['position' => $position, 'cvs' => $items]);
    }

    private function detailed(int $id, CVRepository $cvs, PositionRepository $positions, ProfileRepository $profiles): CV
    {
        $cv = $cvs->findDetailed($id) ?? throw $this->createNotFoundException('CV not found.');
        $positions->hydrateChildren([$cv->getPosition()]);
        $profiles->hydrateValues([$cv->getProfile()]);
        return $cv;
    }
}

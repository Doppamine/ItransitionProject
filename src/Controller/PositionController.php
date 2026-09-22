<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\PositionAccessType;
use App\Position\PositionEligibilityChecker;
use App\Repository\PositionRepository;
use App\Repository\ProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/positions', name: 'app_positions_')]
final class PositionController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[CurrentUser] ?User $user, PositionRepository $positions, ProfileRepository $profiles, PositionEligibilityChecker $eligibility): Response
    {
        $manager = $this->isGranted('ROLE_RECRUITER') || $this->isGranted('ROLE_ADMIN');
        $items = $positions->findForList(!$manager && !$this->isGranted('ROLE_CANDIDATE'));
        if (!$manager && $this->isGranted('ROLE_CANDIDATE')) {
            $profile = $user === null ? null : $profiles->findForEligibility($user);
            $items = $profile === null ? [] : array_values(array_filter($items, static fn (Position $position): bool => $eligibility->isEligible($position, $profile)));
        }
        return $this->render('positions/index.html.twig', ['positions' => $items, 'manager' => $manager]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(#[CurrentUser] ?User $user, int $id, PositionRepository $positions, ProfileRepository $profiles, PositionEligibilityChecker $eligibility): Response
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        $manager = $this->isGranted('ROLE_RECRUITER') || $this->isGranted('ROLE_ADMIN');
        if (!$manager && $position->getAccessType() === PositionAccessType::RESTRICTED) {
            $profile = $user !== null && $this->isGranted('ROLE_CANDIDATE') ? $profiles->findForEligibility($user) : null;
            if (!$profile instanceof Profile || !$eligibility->isEligible($position, $profile)) {
                throw $this->createNotFoundException('Position not found.');
            }
        }
        return $this->render('positions/show.html.twig', ['position' => $position, 'manager' => $manager]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\User;
use App\Discussion\DiscussionPostViewBuilder;
use App\Enum\PositionAccessType;
use App\Position\PositionEligibilityChecker;
use App\Position\PositionDiscussionAccess;
use App\Repository\DiscussionPostRepository;
use App\Repository\PositionRepository;
use App\Repository\ProfileRepository;
use App\Repository\CVRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/positions', name: 'app_positions_')]
final class PositionController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[CurrentUser] ?User $user, PositionRepository $positions, ProfileRepository $profiles, PositionEligibilityChecker $eligibility, PositionDiscussionAccess $discussionAccess): Response
    {
        $manager = $discussionAccess->isManager($user);
        $items = $positions->findForList(!$manager && !$this->isGranted('ROLE_CANDIDATE'));
        if (!$manager && $this->isGranted('ROLE_CANDIDATE')) {
            $profile = $user === null ? null : $profiles->findForEligibility($user);
            $items = $profile === null ? [] : array_values(array_filter($items, static fn (Position $position): bool => $eligibility->isEligible($position, $profile)));
        }
        return $this->render('positions/index.html.twig', ['positions' => $items, 'manager' => $manager]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(#[CurrentUser] ?User $user, int $id, PositionRepository $positions, CVRepository $cvs, PositionDiscussionAccess $discussionAccess, DiscussionPostRepository $posts, DiscussionPostViewBuilder $postViews): Response
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        $manager = $discussionAccess->isManager($user);
        $profile = $discussionAccess->eligibleCandidateProfile($user, $position);
        $candidateEligible = $profile instanceof Profile;
        if (!$manager && $position->getAccessType() === PositionAccessType::RESTRICTED) {
            if (!$candidateEligible) {
                throw $this->createNotFoundException('Position not found.');
            }
        }
        return $this->render('positions/show.html.twig', [
            'position' => $position,
            'manager' => $manager,
            'candidateEligible' => $candidateEligible,
            'cv' => $candidateEligible ? $cvs->findOneBy(['profile' => $profile, 'position' => $position]) : null,
            'discussionAllowed' => $manager || $candidateEligible,
            'discussionPosts' => $manager || $candidateEligible ? $postViews->build($posts->latestForPosition($position), $manager) : [],
        ]);
    }
}

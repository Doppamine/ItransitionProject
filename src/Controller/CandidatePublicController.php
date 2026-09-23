<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ProfileRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CandidatePublicController extends AbstractController
{
    #[Route('/candidates/{id}/public', name: 'app_candidate_public', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function show(int $id, EntityManagerInterface $em, ProfileRepository $profiles, ProjectRepository $projects): Response
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user instanceof User || !in_array('ROLE_CANDIDATE', $user->getRoles(), true)) {
            throw $this->createNotFoundException('Candidate not found.');
        }
        $profile = $profiles->findOneBy(['user' => $user]) ?? throw $this->createNotFoundException('Candidate not found.');
        $details = $profiles->publicDetailsForUsers([$id])[$id] ?? [];
        return $this->render('candidates/public.html.twig', [
            'name' => trim(($details['first name'] ?? '').' '.($details['last name'] ?? '')) ?: 'Candidate #'.$id,
            'location' => $details['location'] ?? '',
            'projects' => $projects->findRecentForProfile($profile),
        ]);
    }
}

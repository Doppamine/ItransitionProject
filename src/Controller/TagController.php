<?php

declare(strict_types=1);

namespace App\Controller;

use App\CV\CVResultLoader;
use App\Entity\User;
use App\Position\PositionResultLoader;
use App\Repository\DashboardRepository;
use App\Repository\TagRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class TagController extends AbstractController
{
    #[Route('/tags/{id}', name: 'app_tag_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(#[CurrentUser] ?User $user, int $id, TagRepository $tags, DashboardRepository $dashboard, PositionResultLoader $positions, CVResultLoader $cvs): Response
    {
        $tag = $tags->find($id) ?? throw $this->createNotFoundException('Tag not found.');
        $manager = $this->isGranted('ROLE_RECRUITER');
        $admin = $this->isGranted('ROLE_ADMIN');
        $candidate = !$manager && $this->isGranted('ROLE_CANDIDATE');
        $positionRows = [];
        $cvRows = [];
        if ($manager) {
            $cvRows = $cvs->visibleRowsByIds($dashboard->taggedCVIds($id, !$admin, $admin ? 50 : 250), 50);
        } else {
            $positionRows = $positions->visibleByIds($dashboard->taggedPositionIds($id, !$candidate, $candidate ? 250 : 50), $user, 50);
        }
        return $this->render('tags/show.html.twig', ['tag' => $tag, 'positions' => $positionRows, 'cvRows' => $cvRows, 'manager' => $manager, 'admin' => $admin]);
    }
}

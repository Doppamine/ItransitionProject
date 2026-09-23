<?php

namespace App\Controller;

use App\Entity\Position;
use App\Entity\User;
use App\Position\PositionResultLoader;
use App\Repository\DashboardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user, DashboardRepository $dashboard, PositionResultLoader $positions): Response
    {
        $manager = $this->isGranted('ROLE_RECRUITER');
        $candidate = !$manager && $this->isGranted('ROLE_CANDIDATE');
        $publicOnly = !$manager && !$candidate;
        $overfetch = $candidate ? 100 : 5;

        $latest = $positions->visibleByIds($dashboard->latestPositionIds($publicOnly, $overfetch), $user, 5);
        $popularCounts = $dashboard->popularPositionCounts($publicOnly, $overfetch);
        $popular = [];
        foreach ($positions->visibleByIds(array_keys($popularCounts), $user, 5) as $position) {
            $popular[] = ['position' => $position, 'count' => $popularCounts[$position->getId()]];
        }
        $visibleTagIds = null;
        if ($candidate) {
            $taggedIds = $dashboard->recentTaggedPositionIds(500);
            $visible = $positions->visibleByIds($taggedIds, $user, count($taggedIds));
            $visibleTagIds = array_map(static fn (Position $position): int => (int) $position->getId(), $visible);
        }
        $tags = $dashboard->tagCloud($publicOnly, $visibleTagIds);
        $maxWeight = max(array_column($tags, 'weight') ?: [1]);
        foreach ($tags as &$tag) {
            $tag['size'] = min(5, max(1, (int) ceil($tag['weight'] * 5 / $maxWeight)));
        }
        unset($tag);

        return $this->render('home/index.html.twig', [
            'latest' => $latest,
            'popular' => $popular,
            'tags' => $tags,
            'statistics' => $dashboard->statistics(),
        ]);
    }
}

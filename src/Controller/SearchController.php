<?php

declare(strict_types=1);

namespace App\Controller;

use App\CV\CVResultLoader;
use App\Entity\User;
use App\Position\PositionResultLoader;
use App\Repository\SearchRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SearchController extends AbstractController
{
    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user, Request $request, SearchRepository $search, PositionResultLoader $positions, CVResultLoader $cvs): Response
    {
        $input = $request->query->get('q', '');
        if (!is_string($input) || !mb_check_encoding($input, 'UTF-8')) {
            return new Response('Invalid search query.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $query = trim(str_replace("\0", ' ', $input));
        if (mb_strlen($query) > 200) {
            return new Response('Search query must be at most 200 characters.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $items = [];
        $cvRows = [];
        $manager = $this->isGranted('ROLE_RECRUITER');
        $admin = $this->isGranted('ROLE_ADMIN');
        if ($query !== '' && $search->hasIndexableTerm($query)) {
            $candidate = $this->isGranted('ROLE_CANDIDATE');
            $ids = $search->positionIds($query, !$manager && !$candidate, $candidate && !$manager ? 250 : 50);
            $items = $positions->visibleByIds($ids, $user, 50);
            if ($manager) {
                $cvIds = $search->cvIds($query, !$admin, $admin ? 50 : 250);
                $cvRows = $cvs->visibleRowsByIds($cvIds, 50);
            }
        }
        return $this->render('search/index.html.twig', ['query' => $query, 'positions' => $items, 'cvRows' => $cvRows, 'showCVs' => $manager, 'admin' => $admin]);
    }
}

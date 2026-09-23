<?php

declare(strict_types=1);

namespace App\Controller;

use App\Discussion\DiscussionPostViewBuilder;
use App\Entity\DiscussionPost;
use App\Entity\Position;
use App\Entity\User;
use App\Position\PositionDiscussionAccess;
use App\Repository\DiscussionPostRepository;
use App\Repository\PositionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/positions/{id}/discussion', name: 'app_discussion_', requirements: ['id' => '\d+'])]
#[IsGranted('IS_AUTHENTICATED')]
final class DiscussionController extends AbstractController
{
    #[Route('/posts', name: 'posts', methods: ['GET'])]
    public function posts(#[CurrentUser] User $user, int $id, Request $request, PositionRepository $positions, PositionDiscussionAccess $access, DiscussionPostRepository $posts, DiscussionPostViewBuilder $views): Response
    {
        $position = $this->accessiblePosition($user, $id, $positions, $access);
        $after = $request->query->get('after', '0');
        if (!is_string($after) || !ctype_digit($after) || strlen($after) > 18) {
            return new Response('Invalid post cursor.', Response::HTTP_BAD_REQUEST);
        }
        return $this->render('positions/_posts.html.twig', [
            'posts' => $views->build($posts->after($position, (int) $after), $access->isManager($user)),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, int $id, Request $request, PositionRepository $positions, PositionDiscussionAccess $access, EntityManagerInterface $em): Response
    {
        $position = $this->accessiblePosition($user, $id, $positions, $access);
        if (!$this->isCsrfTokenValid('discussion_post_'.$id, $request->request->get('_token'))) {
            return new Response('Invalid security token.', Response::HTTP_FORBIDDEN);
        }
        $content = $request->request->all()['content'] ?? null;
        if (!is_string($content) || trim($content) === '' || mb_strlen(trim($content)) > 10000) {
            return new Response('Enter a post of at most 10000 characters.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $em->persist(new DiscussionPost($position, $user, $content));
        $em->flush();
        return $this->redirectToRoute('app_positions_show', ['id' => $id]);
    }

    private function accessiblePosition(User $user, int $id, PositionRepository $positions, PositionDiscussionAccess $access): Position
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        if (!$access->canParticipate($user, $position)) {
            throw $this->createNotFoundException('Position not found.');
        }
        return $position;
    }
}

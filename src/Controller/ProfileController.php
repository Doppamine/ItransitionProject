<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RecentAttributeTracker;
use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\Profile;
use App\Entity\User;
use App\Profile\ProfileValueMapper;
use App\Repository\ProfileRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, Request $request, ProfileRepository $profiles, EntityManagerInterface $entityManager, RecentAttributeTracker $recents): Response
    {
        $profile = $this->profile($profiles, $user);
        $category = $request->query->get('category');
        $categoryId = is_string($category) && ctype_digit($category) ? (int) $category : null;
        $query = $request->query->get('q');
        $query = is_string($query) ? mb_substr($query, 0, 100) : '';
        $builtIns = [];
        $optional = [];
        foreach ($profile->getValues() as $value) {
            if ($value->getDefinition()->isBuiltIn()) {
                $builtIns[$value->getDefinition()->getNormalizedName()] = $value;
            } else {
                $optional[] = $value;
            }
        }
        $orderedBuiltIns = [];
        foreach (['first name', 'last name', 'location', 'personal photo'] as $name) {
            if (isset($builtIns[$name])) {
                $orderedBuiltIns[] = $builtIns[$name];
                unset($builtIns[$name]);
            }
        }
        array_push($orderedBuiltIns, ...array_values($builtIns));
        usort($optional, static fn ($a, $b): int => strcasecmp($a->getDefinition()->getName(), $b->getDefinition()->getName()));
        $selectedIds = array_map(static fn ($value): int => (int) $value->getDefinition()->getId(), $profile->getValues());

        return $this->render('profile/index.html.twig', [
            'profile' => $profile,
            'builtIns' => $orderedBuiltIns,
            'optional' => $optional,
            'categories' => $entityManager->getRepository(AttributeCategory::class)->findBy([], ['name' => 'ASC']),
            'available' => $profiles->searchAvailable($profile, $query, $categoryId),
            'recent' => array_values(array_filter($recents->list(), static fn (array $row): bool => !in_array($row['id'], $selectedIds, true))),
            'query' => $query,
            'categoryId' => $categoryId,
        ]);
    }

    #[Route('/profile/autosave', name: 'app_profile_autosave', methods: ['POST'])]
    public function autosave(#[CurrentUser] User $user, Request $request, ProfileRepository $profiles, ProfileValueMapper $mapper, EntityManagerInterface $entityManager): JsonResponse
    {
        if (!$this->isCsrfTokenValid('profile', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid security token.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $body = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid request.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!is_array($body) || !isset($body['version'], $body['changes']) || !is_int($body['version']) || $body['version'] < 1 || !is_array($body['changes']) || count($body['changes']) > 100) {
            return $this->json(['error' => 'Invalid request.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $profile = $this->profile($profiles, $user);
        try {
            $entityManager->lock($profile, LockMode::OPTIMISTIC, $body['version']);
            $selectedById = [];
            foreach ($profile->getValues() as $selected) {
                $selectedById[$selected->getDefinition()->getId()] = $selected;
            }
            foreach ($body['changes'] as $id => $input) {
                if (!ctype_digit((string) $id) || !isset($selectedById[(int) $id])) {
                    return $this->json(['error' => 'Invalid attribute.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }
            foreach ($body['changes'] as $id => $input) {
                try {
                    $mapper->apply($selectedById[(int) $id], $input);
                } catch (\InvalidArgumentException $exception) {
                    $entityManager->clear();
                    return $this->json(['error' => 'Invalid value.', 'field' => (string) $id], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }
            if ($body['changes'] !== []) {
                $entityManager->flush();
            }
        } catch (OptimisticLockException) {
            return $this->json(['error' => 'Profile changed elsewhere. Reload to continue.'], Response::HTTP_CONFLICT);
        }

        return $this->json(['status' => 'saved', 'version' => $profile->getVersion()]);
    }

    #[Route('/profile/attributes/{id}/select', name: 'app_profile_select', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function select(#[CurrentUser] User $user, AttributeDefinition $definition, Request $request, ProfileRepository $profiles, EntityManagerInterface $entityManager, RecentAttributeTracker $recents): Response
    {
        $token = $request->request->all()['_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('profile', $token)) {
            return new Response('Invalid security token.', Response::HTTP_FORBIDDEN);
        }
        if ($definition->isBuiltIn()) {
            return new Response('Built-in attributes are already selected.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->profile($profiles, $user)->selectAttribute($definition);
        $entityManager->flush();
        $recents->record((int) $definition->getId());
        return new RedirectResponse('/profile#info');
    }

    #[Route('/profile/attributes/{id}/remove', name: 'app_profile_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(#[CurrentUser] User $user, AttributeDefinition $definition, Request $request, ProfileRepository $profiles, EntityManagerInterface $entityManager): Response
    {
        $token = $request->request->all()['_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('profile', $token)) {
            return new Response('Invalid security token.', Response::HTTP_FORBIDDEN);
        }
        if ($definition->isBuiltIn()) {
            return new Response('Built-in attributes cannot be removed.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $profile = $this->profile($profiles, $user);
        if ($profile->getValueFor($definition) === null) {
            return new Response('Attribute is not selected.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $profile->removeAttribute($definition);
        $entityManager->flush();
        return new RedirectResponse('/profile#info');
    }

    private function profile(ProfileRepository $profiles, User $user): Profile
    {
        return $profiles->findForUser($user) ?? throw $this->createNotFoundException('Profile not found.');
    }
}

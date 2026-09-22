<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Profile;
use App\Entity\Project;
use App\Entity\User;
use App\Form\ProjectType;
use App\Project\ProjectTagSynchronizer;
use App\Repository\ProfileRepository;
use App\Repository\ProjectRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ProjectController extends AbstractController
{
    #[Route('/profile/projects', name: 'app_projects_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, ProfileRepository $profiles, ProjectRepository $projects): Response
    {
        return $this->render('projects/index.html.twig', [
            'projects' => $projects->findRecentForProfile($this->profile($profiles, $user)),
        ]);
    }

    #[Route('/profile/projects/new', name: 'app_projects_new', methods: ['GET', 'POST'])]
    public function create(#[CurrentUser] User $user, Request $request, ProfileRepository $profiles, ProjectTagSynchronizer $tags, EntityManagerInterface $em): Response
    {
        $profile = $this->profile($profiles, $user);
        $form = $this->createForm(ProjectType::class, ['description' => '', 'tags' => '']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $names = $this->validData($form, $data, $tags);
            if ($form->isValid()) {
                $project = new Project($profile, trim($data['name']), $data['startDate'], $data['endDate'], (string) ($data['description'] ?? ''));
                $em->getConnection()->transactional(function () use ($tags, $project, $names, $em): void {
                    $tags->synchronize($project, $names);
                    $em->persist($project);
                    $em->flush();
                });
                $this->addFlash('success', 'Project created.');
                return $this->redirectToRoute('app_projects_index');
            }
        }

        return $this->render('projects/form.html.twig', ['form' => $form, 'project' => null],
            new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/profile/projects/{id}/edit', name: 'app_projects_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(#[CurrentUser] User $user, int $id, Request $request, ProfileRepository $profiles, ProjectRepository $projects, ProjectTagSynchronizer $tags, EntityManagerInterface $em): Response
    {
        $profile = $this->profile($profiles, $user);
        $project = $this->ownedProject($projects, $profile, $id);
        $form = $this->createForm(ProjectType::class, [
            'name' => $project->getName(),
            'startDate' => $project->getStartDate(),
            'endDate' => $project->getEndDate(),
            'description' => $project->getDescription(),
            'tags' => implode(', ', array_map(static fn ($tag): string => $tag->getName(), $project->getTags())),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $names = $this->validData($form, $data, $tags);
            if ($form->isValid()) {
                $em->getConnection()->transactional(function () use ($project, $data, $tags, $names, $em): void {
                    $project->update(trim($data['name']), $data['startDate'], $data['endDate'], (string) ($data['description'] ?? ''));
                    $tags->synchronize($project, $names);
                    $em->flush();
                });
                $this->addFlash('success', 'Project saved.');
                return $this->redirectToRoute('app_projects_index');
            }
        }

        return $this->render('projects/form.html.twig', ['form' => $form, 'project' => $project],
            new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/profile/projects/{id}/delete', name: 'app_projects_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(#[CurrentUser] User $user, int $id, Request $request, ProfileRepository $profiles, ProjectRepository $projects, EntityManagerInterface $em): Response
    {
        $project = $this->ownedProject($projects, $this->profile($profiles, $user), $id);
        $token = $request->request->all()['_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('project_delete_'.$id, $token)) {
            return new Response('Invalid security token.', Response::HTTP_FORBIDDEN);
        }

        $em->remove($project);
        $em->flush();
        $this->addFlash('success', 'Project deleted.');
        return $this->redirectToRoute('app_projects_index');
    }

    #[Route('/profile/tags/search', name: 'app_profile_tags_search', methods: ['GET'])]
    public function searchTags(Request $request, TagRepository $tags): JsonResponse
    {
        $query = $request->query->get('q');
        return $this->json($tags->search(is_string($query) ? $query : ''));
    }

    private function profile(ProfileRepository $profiles, User $user): Profile
    {
        return $profiles->findOneBy(['user' => $user]) ?? throw $this->createNotFoundException('Profile not found.');
    }

    private function ownedProject(ProjectRepository $projects, Profile $profile, int $id): Project
    {
        return $projects->findOneBy(['id' => $id, 'profile' => $profile]) ?? throw $this->createNotFoundException('Project not found.');
    }

    /** @param array<string, mixed> $data
     *  @return array<string, string>
     */
    private function validData(FormInterface $form, array $data, ProjectTagSynchronizer $tags): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            $form->get('name')->addError(new FormError('Enter a project name of at most 255 characters.'));
        }
        if (!$data['startDate'] instanceof \DateTimeImmutable) {
            $form->get('startDate')->addError(new FormError('Enter a start date.'));
        } elseif ($data['endDate'] !== null && $data['startDate'] > $data['endDate']) {
            $form->get('endDate')->addError(new FormError('End date must be on or after start date.'));
        }
        if (mb_strlen((string) ($data['description'] ?? '')) > 10000) {
            $form->get('description')->addError(new FormError('Description must be at most 10000 characters.'));
        }
        try {
            return $tags->parse((string) ($data['tags'] ?? ''));
        } catch (\InvalidArgumentException $exception) {
            $form->get('tags')->addError(new FormError($exception->getMessage()));
            return [];
        }
    }
}

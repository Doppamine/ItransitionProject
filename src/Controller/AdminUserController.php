<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AttributeDefinition;
use App\Entity\Profile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users', name: 'app_admin_users')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUserController extends AbstractController
{
    private const ROLE_ACTIONS = [
        'add_candidate' => ['ROLE_CANDIDATE', true],
        'remove_candidate' => ['ROLE_CANDIDATE', false],
        'add_recruiter' => ['ROLE_RECRUITER', true],
        'remove_recruiter' => ['ROLE_RECRUITER', false],
        'add_admin' => ['ROLE_ADMIN', true],
        'remove_admin' => ['ROLE_ADMIN', false],
    ];

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $prefix = $request->query->get('email');
        $prefix = is_string($prefix) ? mb_substr(mb_strtolower(trim($prefix)), 0, 100) : '';
        $builder = $em->getRepository(User::class)->createQueryBuilder('u')->orderBy('u.id', 'DESC')->setMaxResults(100);
        if ($prefix !== '') {
            $builder->andWhere('u.normalizedEmail LIKE :prefix')->setParameter('prefix', addcslashes($prefix, '%_\\').'%');
        }
        return $this->render('admin/users.html.twig', ['users' => $builder->getQuery()->getResult(), 'emailPrefix' => $prefix]);
    }

    #[Route('/action', name: '_action', methods: ['POST'])]
    public function action(#[CurrentUser] User $actor, Request $request, EntityManagerInterface $em, TokenStorageInterface $tokens): Response
    {
        if (!$this->isCsrfTokenValid('admin_users', $request->request->get('_token'))) {
            return new Response('Invalid security token.', 403);
        }
        $action = $request->request->get('action');
        $rawIds = $request->request->all('ids');
        if (!is_string($action) || (!isset(self::ROLE_ACTIONS[$action]) && !in_array($action, ['block', 'unblock', 'delete'], true)) || count($rawIds) < 1 || count($rawIds) > 100) {
            return new Response('Select users and a valid action.', 422);
        }
        $ids = [];
        foreach ($rawIds as $raw) {
            if (!is_string($raw) || !ctype_digit($raw) || (int) $raw < 1) {
                return new Response('Invalid user selection.', 422);
            }
            $ids[(int) $raw] = (int) $raw;
        }
        $users = $em->getRepository(User::class)->createQueryBuilder('u')->where('u.id IN (:ids)')->setParameter('ids', array_values($ids))->setMaxResults(100)->getQuery()->getResult();
        if (count($users) !== count($ids)) {
            return new Response('User selection changed. Reload the list.', 422);
        }
        if (in_array($action, ['block', 'delete'], true)) {
            foreach ($users as $user) {
                if ($user->getId() === $actor->getId()) {
                    $this->addFlash('error', 'flash.admin_self');
                    return $this->redirectToRoute('app_admin_users');
                }
            }
        }
        $profilesByUser = [];
        $builtIns = [];
        if ($action === 'add_candidate') {
            foreach ($em->getRepository(Profile::class)->findBy(['user' => $users]) as $profile) {
                $profilesByUser[$profile->getUser()->getId()] = true;
            }
            $builtIns = $em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
        }
        $selfRemovedAdmin = false;
        foreach ($users as $user) {
            if ($action === 'delete') {
                $em->remove($user);
            } elseif ($action === 'block' || $action === 'unblock') {
                $user->setBlocked($action === 'block');
            } else {
                [$role, $add] = self::ROLE_ACTIONS[$action];
                $roles = array_values(array_filter($user->getRoles(), static fn (string $existing): bool => $existing !== $role));
                if ($add) {
                    $roles[] = $role;
                }
                $user->setRoles($roles);
                if ($action === 'add_candidate' && !isset($profilesByUser[$user->getId()])) {
                    $em->persist(Profile::createWithBuiltIns($user, $builtIns));
                }
                if ($action === 'remove_admin' && $user->getId() === $actor->getId()) {
                    $selfRemovedAdmin = true;
                }
            }
        }
        $em->flush();
        if ($selfRemovedAdmin) {
            $tokens->setToken(null);
            return $this->redirectToRoute('app_home');
        }
        $this->addFlash('success', 'flash.admin_updated');
        return $this->redirectToRoute('app_admin_users');
    }
}

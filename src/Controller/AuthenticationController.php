<?php

declare(strict_types=1);

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthenticationController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(Request $request): Response
    {
        return $this->render('security/login.html.twig', ['loginError' => $request->query->has('error')]);
    }

    #[Route('/connect/google', name: 'app_google_start', methods: ['GET'])]
    public function google(ClientRegistry $clients): RedirectResponse
    {
        return $clients->getClient('google')->redirect(['openid', 'email']);
    }

    #[Route('/connect/github', name: 'app_github_start', methods: ['GET'])]
    public function github(ClientRegistry $clients): RedirectResponse
    {
        return $clients->getClient('github')->redirect(['user:email']);
    }

    #[Route('/connect/google/check', name: 'app_google_callback', methods: ['GET'])]
    public function googleCallback(): Response
    {
        return new Response('OAuth callback was not processed.', Response::HTTP_BAD_REQUEST);
    }

    #[Route('/connect/github/check', name: 'app_github_callback', methods: ['GET'])]
    public function githubCallback(): Response
    {
        return new Response('OAuth callback was not processed.', Response::HTTP_BAD_REQUEST);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Logout is handled by the security firewall.');
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PreferenceController extends AbstractController
{
    #[Route('/preferences/locale', name: 'app_preference_locale', methods: ['POST'])]
    public function locale(#[CurrentUser] ?User $user, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('preference_locale', $request->request->get('_token'))) {
            return new Response('Invalid security token.', Response::HTTP_FORBIDDEN);
        }
        $locale = $request->request->get('locale');
        if (!is_string($locale) || !in_array($locale, ['en', 'ru'], true)) {
            return new Response('Unsupported locale.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($user !== null) {
            $user->setLocale($locale);
            $em->flush();
        } else {
            $request->getSession()->set('ui_locale', $locale);
        }
        return $this->redirect($this->returnPath($request));
    }

    #[Route('/preferences/theme', name: 'app_preference_theme', methods: ['POST'])]
    public function theme(#[CurrentUser] ?User $user, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('preference_theme', $request->request->get('_token'))) {
            return new Response('Invalid security token.', Response::HTTP_FORBIDDEN);
        }
        $theme = $request->request->get('theme');
        if (!is_string($theme) || !in_array($theme, ['light', 'dark'], true)) {
            return new Response('Unsupported theme.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($user !== null) {
            $user->setTheme($theme);
            $em->flush();
        } else {
            $request->getSession()->set('ui_theme', $theme);
        }
        return $this->redirect($this->returnPath($request));
    }

    private function returnPath(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if (!is_string($referer)) {
            return '/';
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || !isset($parts['host']) || strcasecmp($parts['host'], $request->getHost()) !== 0) {
            return '/';
        }
        $path = $parts['path'] ?? '/';
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\') || str_starts_with($path, '/preferences/')) {
            return '/';
        }
        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}

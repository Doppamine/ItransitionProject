<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\OAuthProvider;
use GuzzleHttp\Exception\GuzzleException;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\GithubResourceOwner;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class SocialAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly SocialAccountResolver $accounts,
        private readonly RouterInterface $router,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return in_array($request->attributes->get('_route'), ['app_google_callback', 'app_github_callback'], true);
    }

    public function authenticate(Request $request): Passport
    {
        $provider = match ($request->attributes->get('_route')) {
            'app_google_callback' => OAuthProvider::GOOGLE,
            'app_github_callback' => OAuthProvider::GITHUB,
            default => throw new \LogicException('Unsupported OAuth callback route.'),
        };

        $client = $this->clientRegistry->getClient($provider->value);
        $token = $this->fetchAccessToken($client);

        try {
            $owner = $client->fetchUserFromToken($token);
        } catch (IdentityProviderException|GuzzleException $exception) {
            throw new CustomUserMessageAuthenticationException('Social sign-in could not be completed.', previous: $exception);
        }

        if (($provider === OAuthProvider::GOOGLE && !$owner instanceof GoogleUser)
            || ($provider === OAuthProvider::GITHUB && !$owner instanceof GithubResourceOwner)) {
            throw new CustomUserMessageAuthenticationException('The identity provider returned an invalid profile.');
        }

        $id = $owner->getId();

        if ((!is_string($id) && !is_int($id)) || trim((string) $id) === '') {
            throw new CustomUserMessageAuthenticationException('The identity provider returned an invalid account identifier.');
        }

        $providerUserId = (string) $id;

        return new SelfValidatingPassport(new UserBadge(
            $provider->value.':'.$providerUserId,
            fn (): \App\Entity\User => $this->accounts->resolve(
                $provider,
                $providerUserId,
                fn (): ?string => $provider === OAuthProvider::GOOGLE
                    ? VerifiedProviderEmail::google($owner)
                    : $this->githubEmail($client, $token),
            ),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new RedirectResponse($this->router->generate('app_login', ['error' => '1']));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->router->generate('app_login'));
    }

    private function githubEmail(OAuth2ClientInterface $client, AccessToken $token): ?string
    {
        $provider = $client->getOAuth2Provider();

        if (!$provider instanceof Github) {
            throw new CustomUserMessageAuthenticationException('The GitHub client is not configured correctly.');
        }

        $url = $provider->getResourceOwnerDetailsUrl($token).'/emails';
        $request = $provider->getAuthenticatedRequest('GET', $url, $token, [
            'headers' => ['Accept' => 'application/vnd.github+json'],
        ]);

        try {
            $emails = $provider->getParsedResponse($request);
        } catch (IdentityProviderException|GuzzleException|\UnexpectedValueException $exception) {
            throw new CustomUserMessageAuthenticationException('GitHub email verification could not be completed.', previous: $exception);
        }

        return is_array($emails) ? VerifiedProviderEmail::github($emails) : null;
    }
}

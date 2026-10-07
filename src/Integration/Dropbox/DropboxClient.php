<?php

declare(strict_types=1);

namespace App\Integration\Dropbox;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DropboxClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(DROPBOX_APP_KEY)%')] private readonly string $appKey,
        #[Autowire('%env(DROPBOX_APP_SECRET)%')] private readonly string $appSecret,
        #[Autowire('%env(DROPBOX_REFRESH_TOKEN)%')] private readonly string $refreshToken,
        #[Autowire('%env(DROPBOX_SUPPORT_FOLDER)%')] private readonly string $supportFolder,
    ) {
    }

    public function uploadJson(string $fileName, string $jsonContent): DropboxUploadResult
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\.json\z/', $fileName) !== 1) {
            throw new \InvalidArgumentException('A simple JSON filename is required.');
        }

        $accessToken = $this->getAccessToken();
        try {
            $response = $this->httpClient->request('POST', 'https://content.dropboxapi.com/2/files/upload', [
                'max_redirects' => 0,
                'auth_bearer' => $accessToken,
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                    'Dropbox-API-Arg' => json_encode([
                        'path' => rtrim($this->supportFolder, '/').'/'.$fileName,
                        'mode' => 'add',
                        'autorename' => true,
                        'mute' => false,
                    ], JSON_THROW_ON_ERROR),
                ],
                'body' => $jsonContent,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new DropboxApiException();
            }

            $data = $response->toArray();
        } catch (ExceptionInterface|\JsonException) {
            throw new DropboxApiException();
        }

        foreach (['id', 'name', 'path_display'] as $field) {
            if (!is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                throw new DropboxApiException();
            }
        }

        return new DropboxUploadResult($data['id'], $data['name'], $data['path_display']);
    }

    private function getAccessToken(): string
    {
        try {
            $response = $this->httpClient->request('POST', 'https://api.dropbox.com/oauth2/token', [
                'max_redirects' => 0,
                'auth_basic' => [$this->appKey, $this->appSecret],
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->refreshToken,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new DropboxAuthenticationException();
            }

            $data = $response->toArray();
        } catch (ExceptionInterface) {
            throw new DropboxAuthenticationException();
        }

        $accessToken = $data['access_token'] ?? null;
        if (!is_string($accessToken) || trim($accessToken) === '') {
            throw new DropboxAuthenticationException();
        }

        return $accessToken;
    }
}

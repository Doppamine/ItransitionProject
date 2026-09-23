<?php

declare(strict_types=1);

namespace App\Image;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CloudinaryImages
{
    public function __construct(
        #[Autowire('%env(default::CLOUDINARY_CLOUD_NAME)%')] private readonly ?string $cloudName,
        #[Autowire('%env(default::CLOUDINARY_API_KEY)%')] private readonly ?string $apiKey,
        #[Autowire('%env(default::CLOUDINARY_API_SECRET)%')] private readonly ?string $apiSecret,
    ) {
    }

    public function configured(): bool
    {
        return $this->validCloudName() && !empty($this->apiKey) && !empty($this->apiSecret);
    }

    /** @return array{url: string, fields: array<string, string>} */
    public function signedUpload(int $profileId, int $definitionId): array
    {
        if (!$this->configured()) {
            throw new \LogicException('Image upload is not configured.');
        }
        $fields = [
            'timestamp' => (string) time(),
            'public_id' => sprintf('profiles/%d/attributes/%d/%s', $profileId, $definitionId, bin2hex(random_bytes(16))),
            'allowed_formats' => 'gif,jpeg,jpg,png,webp',
            'overwrite' => 'false',
        ];
        $fields['signature'] = $this->sign($fields);
        $fields['api_key'] = $this->apiKey;

        return ['url' => 'https://api.cloudinary.com/v1_1/'.$this->cloudName.'/image/upload', 'fields' => $fields];
    }

    public function verifyResponse(string $publicId, int $version, string $signature): bool
    {
        if (!$this->configured() || $version < 1 || !preg_match('~^profiles/[1-9][0-9]*/attributes/[1-9][0-9]*/[a-f0-9]{32}$~D', $publicId)) {
            return false;
        }
        return hash_equals(sha1('public_id='.$publicId.'&version='.$version.$this->apiSecret), $signature);
    }

    public function url(?string $publicId): ?string
    {
        if (!$this->validCloudName() || $publicId === null || !preg_match('~^profiles/[1-9][0-9]*/attributes/[1-9][0-9]*/[a-f0-9]{32}$~D', $publicId)) {
            return null;
        }
        return 'https://res.cloudinary.com/'.$this->cloudName.'/image/upload/f_auto,q_auto,c_limit,w_800,h_800/'.$publicId;
    }

    /** @param array<string, string> $fields */
    private function sign(array $fields): string
    {
        ksort($fields);
        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = $name.'='.$value;
        }
        return sha1(implode('&', $parts).$this->apiSecret);
    }

    private function validCloudName(): bool
    {
        return is_string($this->cloudName) && preg_match('/^[a-zA-Z0-9_-]+$/D', $this->cloudName) === 1;
    }
}

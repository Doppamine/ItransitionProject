<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\Position;
use App\Entity\User;
use App\Integration\Dropbox\DropboxClient;
use Doctrine\DBAL\Connection;

final class SupportTicketCreator
{
    public function __construct(private readonly DropboxClient $dropbox, private readonly Connection $connection)
    {
    }

    public function create(User $reporter, string $summary, string $priority, string $pageUrl, ?Position $position = null): bool
    {
        $adminEmails = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT email FROM app_user WHERE roles::jsonb @> CAST(:roles AS jsonb) ORDER BY email',
            ['roles' => '["ROLE_ADMIN"]'],
        );
        if ($adminEmails === []) {
            return false;
        }

        $json = json_encode([
            'summary' => $summary,
            'reportedBy' => ['email' => $reporter->getEmail(), 'roles' => $reporter->getRoles()],
            'positionTitle' => $position?->getTitle() ?? '',
            'pageUrl' => $pageUrl,
            'priority' => $priority,
            'adminEmails' => $adminEmails,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $fileName = 'support-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(16)).'.json';
        $this->dropbox->uploadJson($fileName, $json);

        return true;
    }
}

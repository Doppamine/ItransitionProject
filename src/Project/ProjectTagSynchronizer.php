<?php

declare(strict_types=1);

namespace App\Project;

use App\Entity\Project;
use App\Entity\Tag;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ProjectTagSynchronizer
{
    public function __construct(private EntityManagerInterface $em, private TagRepository $tags)
    {
    }

    /** @return array<string, string> normalized name => display name */
    public function parse(string $input): array
    {
        if (trim($input) === '') {
            return [];
        }

        $parts = explode(',', $input);
        if (trim(end($parts)) === '') {
            array_pop($parts);
        }
        if (count($parts) > 50) {
            throw new \InvalidArgumentException('Enter at most 50 tag entries.');
        }

        $names = [];
        foreach ($parts as $part) {
            $tag = new Tag($part);
            $names[$tag->getNormalizedName()] ??= $tag->getName();
        }
        if (count($names) > 20) {
            throw new \InvalidArgumentException('Use at most 20 different technology tags.');
        }

        return $names;
    }

    /** @param array<string, string> $names normalized name => display name */
    public function synchronize(Project $project, array $names): void
    {
        if ($names === []) {
            $project->replaceTags([]);
            return;
        }

        $values = [];
        $parameters = [];
        foreach ($names as $normalized => $display) {
            $values[] = '(?, ?)';
            $parameters[] = $display;
            $parameters[] = $normalized;
        }
        $this->em->getConnection()->executeStatement(
            'INSERT INTO tag (name, normalized_name) VALUES '.implode(', ', $values).' ON CONFLICT (normalized_name) DO NOTHING',
            $parameters,
        );

        $found = $this->tags->findBy(['normalizedName' => array_keys($names)]);
        if (count($found) !== count($names)) {
            throw new \RuntimeException('Technology tags could not be saved.');
        }
        $project->replaceTags($found);
    }
}

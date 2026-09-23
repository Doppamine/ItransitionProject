<?php

declare(strict_types=1);

namespace App\CV;

use App\Entity\CV;
use App\Repository\AttributeDefinitionRepository;
use App\Repository\ProjectRepository;

final class CVViewBuilder
{
    public function __construct(
        private readonly CVAttributes $attributes,
        private readonly AttributeDefinitionRepository $definitions,
        private readonly ProjectRepository $projects,
    ) {
    }

    /** @return array{identity: array, attributes: array, projects: array, version: ?int} */
    public function build(CV $cv): array
    {
        $profile = $cv->getProfile();
        $position = $cv->getPosition();
        $builtIns = $this->definitions->findCVBuiltIns();
        $identity = $this->attributes->builtIns($profile, $position, $builtIns);
        $attributes = $this->attributes->position($profile, $position, $builtIns);
        $this->definitions->hydrateOptions(array_map(static fn (array $item) => $item['definition'], array_merge($identity, $attributes)));

        return [
            'identity' => $identity,
            'attributes' => $attributes,
            'projects' => $this->projects->findForCV($profile, $position->getProjectTags(), $position->getMaxProjects()),
            'version' => $profile->getVersion(),
        ];
    }

    public function isComplete(CV $cv): bool
    {
        return $this->attributes->isComplete($cv->getProfile(), $cv->getPosition(), $this->definitions->findCVBuiltIns());
    }

    /** @return array<int, \App\Entity\AttributeDefinition> */
    public function renderedDefinitions(CV $cv): array
    {
        $profile = $cv->getProfile();
        $position = $cv->getPosition();
        $builtIns = $this->definitions->findCVBuiltIns();
        $items = array_merge(
            $this->attributes->builtIns($profile, $position, $builtIns),
            $this->attributes->position($profile, $position, $builtIns),
        );
        $definitions = [];
        foreach ($items as $item) {
            $definitions[$item['definition']->getId()] = $item['definition'];
        }
        $this->definitions->hydrateOptions(array_values($definitions));
        return $definitions;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RecentAttributeTracker;
use App\Entity\AttributeDefinition;
use App\Entity\AttributeOption;
use App\Entity\Position;
use App\Entity\PositionAccessRule;
use App\Entity\PositionAttribute;
use App\Entity\PositionProjectTag;
use App\Enum\AccessRuleOperator;
use App\Enum\AttributeType;
use App\Enum\PositionAccessType;
use App\Form\PositionAccessRuleType;
use App\Form\PositionType;
use App\Position\PositionDuplicator;
use App\Repository\AttributeDefinitionRepository;
use App\Repository\PositionRepository;
use App\Repository\TagRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/positions', name: 'app_positions_')]
#[IsGranted('ROLE_RECRUITER')]
final class PositionManagementController extends AbstractController
{
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(PositionType::class, ['shortDescription' => '', 'accessType' => 'public', 'maxProjects' => 0]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $position = new Position((string) $data['title'], (string) ($data['shortDescription'] ?? ''), PositionAccessType::from($data['accessType']), (int) $data['maxProjects']);
                $em->persist($position);
                $em->flush();
                $this->addFlash('success', 'flash.position_created');
                return $this->redirectToRoute('app_positions_index');
            } catch (\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }
        return $this->render('positions/form.html.twig', $this->formContext($form, null), new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, PositionRepository $positions, AttributeDefinitionRepository $definitions, TagRepository $tags, EntityManagerInterface $em, RecentAttributeTracker $recents): Response
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        $form = $this->createForm(PositionType::class, [
            'title' => $position->getTitle(), 'shortDescription' => $position->getShortDescription(),
            'accessType' => $position->getAccessType()->value, 'maxProjects' => $position->getMaxProjects(),
            'version' => (string) $position->getVersion(),
        ], ['editing' => true]);
        $form->handleRequest($request);
        $status = 200;
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $this->lock($position, $data['version'] ?? null, $em);
                $position->update((string) $data['title'], (string) ($data['shortDescription'] ?? ''), PositionAccessType::from($data['accessType']), (int) $data['maxProjects']);
                $em->flush();
                $this->addFlash('success', 'flash.position_saved');
                return $this->redirectToRoute('app_positions_edit', ['id' => $id]);
            } catch (OptimisticLockException|\UnexpectedValueException) {
                $form->addError(new FormError('This Position changed elsewhere. Reload before editing.'));
                $status = 409;
            } catch (\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
                $status = 422;
            }
        } elseif ($form->isSubmitted()) {
            $status = 422;
        }
        return $this->render('positions/form.html.twig', $this->formContext($form, $position, $definitions, $tags, $recents), new Response(status: $status));
    }

    #[Route('/{id}/duplicate', name: 'duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(int $id, Request $request, PositionRepository $positions, PositionDuplicator $duplicator, EntityManagerInterface $em): Response
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        if (!$this->validToken($request, 'position_duplicate_'.$id)) {
            return new Response('Invalid security token.', 403);
        }
        $em->persist($duplicator->duplicate($position));
        $em->flush();
        $this->addFlash('success', 'flash.position_duplicated');
        return $this->redirectToRoute('app_positions_index');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, PositionRepository $positions, EntityManagerInterface $em): Response
    {
        $position = $positions->find($id) ?? throw $this->createNotFoundException('Position not found.');
        if (!$this->validToken($request, 'position_delete_'.$id)) {
            return new Response('Invalid security token.', 403);
        }
        $em->remove($position);
        $em->flush();
        $this->addFlash('success', 'flash.position_deleted');
        return $this->redirectToRoute('app_positions_index');
    }

    #[Route('/{id}/attributes/add', name: 'attribute_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addAttribute(int $id, Request $request, PositionRepository $positions, EntityManagerInterface $em, RecentAttributeTracker $recents): Response
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        if (($error = $this->lockChild($position, $request, $em)) !== null) { return $error; }
        $definitionId = $request->request->get('definition');
        $sortOrder = $request->request->get('sortOrder');
        $definition = is_string($definitionId) && ctype_digit($definitionId) ? $em->find(AttributeDefinition::class, (int) $definitionId) : null;
        try {
            if (!$definition instanceof AttributeDefinition || !is_string($sortOrder) || !ctype_digit($sortOrder)) { throw new \InvalidArgumentException('Choose an attribute and a non-negative order.'); }
            $position->addAttribute($definition, (int) $sortOrder);
            $em->flush();
            $recents->record((int) $definition->getId());
            $this->addFlash('success', 'flash.template_attribute_added');
        } catch (\InvalidArgumentException|UniqueConstraintViolationException $exception) {
            $this->addFlash('error', $exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'flash.attribute_already_selected');
        }
        return $this->redirectToRoute('app_positions_edit', ['id' => $id]);
    }

    #[Route('/{id}/attributes/{childId}/remove', name: 'attribute_remove', requirements: ['id' => '\d+', 'childId' => '\d+'], methods: ['POST'])]
    public function removeAttribute(int $id, int $childId, Request $request, PositionRepository $positions, EntityManagerInterface $em): Response
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        if (($error = $this->lockChild($position, $request, $em)) !== null) { return $error; }
        $position->removeAttribute($this->attribute($position, $childId));
        $em->flush();
        return $this->redirectToRoute('app_positions_edit', ['id' => $id]);
    }

    #[Route('/{id}/attributes/{childId}/order', name: 'attribute_order', requirements: ['id' => '\d+', 'childId' => '\d+'], methods: ['POST'])]
    public function orderAttribute(int $id, int $childId, Request $request, PositionRepository $positions, EntityManagerInterface $em): Response
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        if (($error = $this->lockChild($position, $request, $em)) !== null) { return $error; }
        $order = $request->request->get('sortOrder');
        if (!is_string($order) || !ctype_digit($order)) { return new Response('Invalid order.', 422); }
        $position->moveAttribute($this->attribute($position, $childId), (int) $order);
        $em->flush();
        return $this->redirectToRoute('app_positions_edit', ['id' => $id]);
    }

    #[Route('/{id}/tags/add', name: 'tag_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addTag(int $id, Request $request, PositionRepository $positions, TagRepository $tags, EntityManagerInterface $em): Response
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        if (($error = $this->lockChild($position, $request, $em)) !== null) { return $error; }
        $name = $request->request->get('tag');
        $tag = is_string($name) ? $tags->findOneBy(['normalizedName' => mb_strtolower(trim($name), 'UTF-8')]) : null;
        try {
            if ($tag === null) { throw new \InvalidArgumentException('Choose an existing Project Tag.'); }
            $position->addProjectTag($tag);
            $em->flush();
            $this->addFlash('success', 'flash.project_tag_added');
        } catch (\InvalidArgumentException|UniqueConstraintViolationException $exception) {
            $this->addFlash('error', $exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'flash.tag_already_selected');
        }
        return $this->redirectToRoute('app_positions_edit', ['id' => $id]);
    }

    #[Route('/{id}/tags/{childId}/remove', name: 'tag_remove', requirements: ['id' => '\d+', 'childId' => '\d+'], methods: ['POST'])]
    public function removeTag(int $id, int $childId, Request $request, PositionRepository $positions, EntityManagerInterface $em): Response
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        if (($error = $this->lockChild($position, $request, $em)) !== null) { return $error; }
        $position->removeProjectTag($this->tagLink($position, $childId));
        $em->flush();
        return $this->redirectToRoute('app_positions_edit', ['id' => $id]);
    }

    #[Route('/tags/search', name: 'tag_search', methods: ['GET'])]
    public function searchTags(Request $request, TagRepository $tags): JsonResponse
    {
        $query = $request->query->get('q');
        return $this->json($tags->search(is_string($query) ? $query : ''));
    }

    #[Route('/{id}/rules/new', name: 'rule_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function addRule(int $id, Request $request, PositionRepository $positions, AttributeDefinitionRepository $definitions, EntityManagerInterface $em): Response
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        $definitionId = $request->query->get('definition');
        $definition = is_string($definitionId) && ctype_digit($definitionId) ? $definitions->find((int) $definitionId) : null;
        if (!$definition instanceof AttributeDefinition || $definition->getType() === AttributeType::IMAGE) {
            return $this->render(
                'positions/rule_select.html.twig',
                ['position' => $position, 'definitions' => $definitions->findSelectable(true)],
                new Response(status: $request->isMethod('POST') ? 422 : 200),
            );
        }
        $form = $this->createForm(PositionAccessRuleType::class, ['version' => (string) $position->getVersion()], ['definition' => $definition]);
        $form->handleRequest($request);
        $status = 200;
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $this->lock($position, $data['version'] ?? null, $em);
                $operator = AccessRuleOperator::from((string) $data['operator']);
                $rule = new PositionAccessRule($position, $definition, $operator);
                $this->applyExpected($rule, $definition, $data, $em);
                $em->flush();
                $this->addFlash('success', 'flash.access_rule_added');
                return $this->redirectToRoute('app_positions_edit', ['id' => $id]);
            } catch (OptimisticLockException|\UnexpectedValueException) {
                $form->addError(new FormError('This Position changed elsewhere. Reload before adding a rule.'));
                $status = 409;
            } catch (\InvalidArgumentException|\LogicException $exception) {
                $form->addError(new FormError($exception->getMessage()));
                $status = 422;
            }
        } elseif ($form->isSubmitted()) {
            $status = 422;
        }
        return $this->render('positions/rule_form.html.twig', ['position' => $position, 'definition' => $definition, 'form' => $form], new Response(status: $status));
    }

    #[Route('/{id}/rules/{ruleId}/remove', name: 'rule_remove', requirements: ['id' => '\d+', 'ruleId' => '\d+'], methods: ['POST'])]
    public function removeRule(int $id, int $ruleId, Request $request, PositionRepository $positions, EntityManagerInterface $em): Response
    {
        $position = $positions->findDetailed($id) ?? throw $this->createNotFoundException('Position not found.');
        if (($error = $this->lockChild($position, $request, $em)) !== null) { return $error; }
        foreach ($position->getAccessRules() as $rule) {
            if ($rule->getId() === $ruleId) { $position->removeAccessRule($rule); $em->flush(); return $this->redirectToRoute('app_positions_edit', ['id' => $id]); }
        }
        throw $this->createNotFoundException('Access Rule not found.');
    }

    /** @return array<string, mixed> */
    private function formContext(FormInterface $form, ?Position $position, ?AttributeDefinitionRepository $definitions = null, ?TagRepository $tags = null, ?RecentAttributeTracker $recents = null): array
    {
        return ['form' => $form, 'position' => $position,
            'definitions' => $position === null ? [] : $definitions?->findSelectable() ?? [],
            'availableTags' => $position === null ? [] : $tags?->findAlphabetical() ?? [],
            'recent' => $recents?->list() ?? []];
    }

    private function lock(Position $position, mixed $version, EntityManagerInterface $em): void
    {
        if (!is_string($version) || !ctype_digit($version) || (int) $version < 1) { throw new \UnexpectedValueException('Invalid Position version.'); }
        $em->lock($position, LockMode::OPTIMISTIC, (int) $version);
    }

    private function lockChild(Position $position, Request $request, EntityManagerInterface $em): ?Response
    {
        if (!$this->validToken($request, 'position_child_'.$position->getId())) { return new Response('Invalid security token.', 403); }
        try { $this->lock($position, $request->request->get('version'), $em); }
        catch (OptimisticLockException|\UnexpectedValueException) { return new Response('This Position changed elsewhere. Reload before editing.', 409); }
        return null;
    }

    private function validToken(Request $request, string $id): bool
    {
        $token = $request->request->get('_token');
        return is_string($token) && $this->isCsrfTokenValid($id, $token);
    }

    private function attribute(Position $position, int $id): PositionAttribute
    {
        foreach ($position->getAttributes() as $attribute) { if ($attribute->getId() === $id) { return $attribute; } }
        throw $this->createNotFoundException('Position Attribute not found.');
    }

    private function tagLink(Position $position, int $id): PositionProjectTag
    {
        foreach ($position->getProjectTagLinks() as $link) { if ($link->getId() === $id) { return $link; } }
        throw $this->createNotFoundException('Position Project Tag not found.');
    }

    /** @param array<string, mixed> $data */
    private function applyExpected(PositionAccessRule $rule, AttributeDefinition $definition, array $data, EntityManagerInterface $em): void
    {
        match ($definition->getType()) {
            AttributeType::STRING, AttributeType::TEXT => $rule->setTextExpected((string) ($data['textValue'] ?? '')),
            AttributeType::NUMERIC => $rule->setNumericExpected((string) ($data['numericValue'] ?? '')),
            AttributeType::DATE => $rule->setDateExpected($data['dateValue'] instanceof \DateTimeImmutable ? $data['dateValue'] : throw new \InvalidArgumentException('Enter an expected date.')),
            AttributeType::PERIOD => $rule->setPeriodExpected(
                $data['periodStart'] instanceof \DateTimeImmutable ? $data['periodStart'] : throw new \InvalidArgumentException('Enter an expected period.'),
                $data['periodEnd'] instanceof \DateTimeImmutable ? $data['periodEnd'] : throw new \InvalidArgumentException('Enter an expected period.'),
            ),
            AttributeType::BOOLEAN => $rule->setBooleanExpected(is_bool($data['booleanValue'] ?? null) ? $data['booleanValue'] : throw new \InvalidArgumentException('Choose Yes or No.')),
            AttributeType::SELECT => $rule->setOptionExpected($this->selectedOption($definition, $data['option'] ?? null, $em)),
            AttributeType::IMAGE => throw new \InvalidArgumentException('Image attributes cannot be used for Access Rules.'),
        };
    }

    private function selectedOption(AttributeDefinition $definition, mixed $id, EntityManagerInterface $em): AttributeOption
    {
        $option = is_string($id) && ctype_digit($id) ? $em->find(AttributeOption::class, (int) $id) : null;
        if (!$option instanceof AttributeOption || $option->getDefinition()->getId() !== $definition->getId()) { throw new \InvalidArgumentException('Choose an option from the selected AttributeDefinition.'); }
        return $option;
    }
}

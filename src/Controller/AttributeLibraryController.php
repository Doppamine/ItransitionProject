<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\AttributeOption;
use App\Enum\AttributeType;
use App\Form\AttributeDefinitionType;
use App\Repository\AttributeDefinitionRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/attributes', name: 'app_attributes_')]
final class AttributeLibraryController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, AttributeDefinitionRepository $definitions, EntityManagerInterface $em): Response
    {
        $q = $request->query->get('q');
        $q = is_string($q) ? mb_substr($q, 0, 100) : '';
        $category = $request->query->get('category');
        $categoryId = is_string($category) && ctype_digit($category) ? (int) $category : null;
        $type = $request->query->get('type');
        $type = is_string($type) ? AttributeType::tryFrom($type) : null;

        return $this->render('attributes/index.html.twig', [
            'definitions' => $definitions->search($q, $categoryId, $type),
            'categories' => $em->getRepository(AttributeCategory::class)->findBy([], ['name' => 'ASC']),
            'types' => AttributeType::cases(),
            'q' => $q,
            'categoryId' => $categoryId,
            'selectedType' => $type?->value,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function create(Request $request, AttributeDefinitionRepository $definitions, EntityManagerInterface $em): Response
    {
        $categories = $em->getRepository(AttributeCategory::class)->findBy([], ['name' => 'ASC']);
        $form = $this->createForm(AttributeDefinitionType::class, ['description' => ''], [
            'categories' => $this->categoryChoices($categories),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $category = $this->selectedCategory($categories, $data['category'] ?? null);
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255) {
                $form->get('name')->addError(new FormError('Enter a name of at most 255 characters.'));
            } elseif ($definitions->findOneBy(['normalizedName' => mb_strtolower($name, 'UTF-8')]) !== null) {
                $form->get('name')->addError(new FormError('An attribute with this name already exists.'));
            }
            if ($category !== null && $form->isValid()) {
                $definition = new AttributeDefinition($category, $name, AttributeType::from($data['type']), (string) ($data['description'] ?? ''));
                $em->persist($definition);
                try {
                    $em->flush();
                } catch (UniqueConstraintViolationException) {
                    $form->get('name')->addError(new FormError('An attribute with this name already exists.'));
                }
                if ($form->isValid()) {
                    $this->addFlash('success', 'flash.attribute_created');
                    return $this->redirectToRoute('app_attributes_index');
                }
            }
        }

        return $this->render('attributes/form.html.twig', ['form' => $form, 'definition' => null, 'used' => false],
            new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(AttributeDefinition $definition, Request $request, AttributeDefinitionRepository $definitions, EntityManagerInterface $em): Response
    {
        $categories = $em->getRepository(AttributeCategory::class)->findBy([], ['name' => 'ASC']);
        $used = $definitions->isUsed($definition);
        $typeFixed = $definition->isBuiltIn() || $used || ($definition->getType() === AttributeType::SELECT && $definition->getOptions() !== []);
        $form = $this->createForm(AttributeDefinitionType::class, [
            'name' => $definition->getName(),
            'category' => (string) $definition->getCategory()->getId(),
            'description' => $definition->getDescription(),
            'type' => $definition->getType()->value,
            'version' => (string) $definition->getVersion(),
        ], ['categories' => $this->categoryChoices($categories), 'built_in' => $definition->isBuiltIn(), 'type_fixed' => $typeFixed, 'editing' => true]);
        $form->handleRequest($request);
        $status = Response::HTTP_OK;
        if ($form->isSubmitted()) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            $raw = $request->request->all('attribute_definition');
            if ($definition->isBuiltIn() && (
                (isset($raw['name']) && $raw['name'] !== $definition->getName())
                || (isset($raw['type']) && $raw['type'] !== $definition->getType()->value)
            )) {
                $form->addError(new FormError('Built-in names and types cannot change.'));
            }
            if ($typeFixed && isset($raw['type']) && $raw['type'] !== $definition->getType()->value) {
                $form->addError(new FormError('This attribute type is fixed.'));
            }
            if ($form->isValid()) {
                $data = $form->getData();
                $name = trim((string) ($data['name'] ?? ''));
                $category = $this->selectedCategory($categories, $data['category'] ?? null);
                $type = AttributeType::from($data['type']);
                $version = $data['version'] ?? null;
                if (!is_string($version) || !ctype_digit($version) || (int) $version < 1) {
                    $form->addError(new FormError('Reload the page and try again.'));
                } else {
                    try {
                        $em->lock($definition, LockMode::OPTIMISTIC, (int) $version);
                    } catch (OptimisticLockException) {
                        $form->addError(new FormError('This attribute changed elsewhere. Reload before editing.'));
                        $status = Response::HTTP_CONFLICT;
                    }
                }
                if ($name === '' || mb_strlen($name) > 255) {
                    $form->get('name')->addError(new FormError('Enter a name of at most 255 characters.'));
                } elseif ($name !== $definition->getName()) {
                    $duplicate = $definitions->findOneBy(['normalizedName' => mb_strtolower($name, 'UTF-8')]);
                    if ($duplicate !== null && $duplicate->getId() !== $definition->getId()) {
                        $form->get('name')->addError(new FormError('An attribute with this name already exists.'));
                    }
                }
                if ($type !== $definition->getType() && ($definition->isBuiltIn() || $used || ($definition->getType() === AttributeType::SELECT && $definition->getOptions() !== []))) {
                    $form->get('type')->addError(new FormError('The type cannot change once the attribute is used, built in, or has options.'));
                }
                if ($category !== null && $form->isValid()) {
                    try {
                        $save = function () use ($definition, $name, $category, $data, $type, $used, $em): void {
                            $definition->rename($name);
                            $definition->changeCategory($category);
                            $definition->changeDescription((string) ($data['description'] ?? ''));
                            $definition->changeType($type, $used);
                            $em->flush();
                        };
                        if ($type !== $definition->getType()) {
                            $em->getConnection()->transactional(function () use ($em, $definition, $version, $definitions, $save): void {
                                $em->lock($definition, LockMode::PESSIMISTIC_WRITE);
                                $em->lock($definition, LockMode::OPTIMISTIC, (int) $version);
                                if ($definitions->isUsed($definition)) {
                                    throw new \LogicException('Attribute is now used.');
                                }
                                $save();
                            });
                        } else {
                            $save();
                        }
                    } catch (\LogicException) {
                        $form->get('type')->addError(new FormError('This attribute is now used. Reload before changing its type.'));
                    } catch (UniqueConstraintViolationException) {
                        $form->get('name')->addError(new FormError('An attribute with this name already exists.'));
                    } catch (OptimisticLockException) {
                        $form->addError(new FormError('This attribute changed elsewhere. Reload before editing.'));
                        $status = Response::HTTP_CONFLICT;
                    }
                    if ($form->isValid()) {
                        $this->addFlash('success', 'flash.attribute_saved');
                        return $this->redirectToRoute('app_attributes_edit', ['id' => $definition->getId()]);
                    }
                }
            }
        }

        return $this->render('attributes/form.html.twig', ['form' => $form, 'definition' => $definition, 'used' => $used], new Response(status: $status));
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(AttributeDefinition $definition, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->validToken($request, 'attribute_delete_'.$definition->getId())) {
            return new Response('Invalid security token.', Response::HTTP_FORBIDDEN);
        }
        if ($definition->isBuiltIn()) {
            return new Response('Built-in attributes cannot be deleted.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $definition->assertDeletable();
        $em->getConnection()->executeStatement('DELETE FROM attribute_definition WHERE id = ?', [$definition->getId()]);
        $this->addFlash('success', 'flash.attribute_deleted');
        return $this->redirectToRoute('app_attributes_index');
    }

    #[Route('/{id}/options', name: 'option_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addOption(AttributeDefinition $definition, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->validToken($request, 'attribute_option_'.$definition->getId())) {
            return new Response('Invalid security token.', Response::HTTP_FORBIDDEN);
        }
        if ($definition->getType() !== AttributeType::SELECT) {
            return new Response('Only Select attributes can have options.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $label = $request->request->get('label');
        if (!is_string($label) || mb_strlen(trim($label)) > 255) {
            $this->addFlash('error', 'flash.option_label_length');
        } else {
            $options = $definition->getOptions();
            $order = $options === [] ? 10 : max(array_map(static fn (AttributeOption $option): int => $option->getSortOrder(), $options)) + 10;
            try {
                $definition->addOption($label, $order);
                $em->flush();
                $this->addFlash('success', 'flash.option_added');
            } catch (\InvalidArgumentException|UniqueConstraintViolationException) {
                $this->addFlash('error', 'flash.option_label_invalid');
            } catch (OptimisticLockException) {
                $this->addFlash('error', 'flash.attribute_changed');
            }
        }
        return $this->redirectToRoute('app_attributes_edit', ['id' => $definition->getId()]);
    }

    #[Route('/{id}/options/{optionId}/rename', name: 'option_rename', requirements: ['id' => '\d+', 'optionId' => '\d+'], methods: ['POST'])]
    public function renameOption(AttributeDefinition $definition, int $optionId, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->validToken($request, 'attribute_option_'.$definition->getId())) {
            return new Response('Invalid security token.', Response::HTTP_FORBIDDEN);
        }
        $option = $this->ownedOption($definition, $optionId);
        $label = $request->request->get('label');
        if (!is_string($label) || mb_strlen(trim($label)) > 255) {
            $this->addFlash('error', 'flash.option_label_length');
        } else {
            try {
                $definition->renameOption($option, $label);
                $em->flush();
                $this->addFlash('success', 'flash.option_renamed');
            } catch (\InvalidArgumentException|UniqueConstraintViolationException) {
                $this->addFlash('error', 'flash.option_label_invalid');
            } catch (OptimisticLockException) {
                $this->addFlash('error', 'flash.attribute_changed');
            }
        }
        return $this->redirectToRoute('app_attributes_edit', ['id' => $definition->getId()]);
    }

    #[Route('/{id}/options/{optionId}/remove', name: 'option_remove', requirements: ['id' => '\d+', 'optionId' => '\d+'], methods: ['POST'])]
    public function removeOption(AttributeDefinition $definition, int $optionId, Request $request, AttributeDefinitionRepository $definitions, EntityManagerInterface $em): Response
    {
        if (!$this->validToken($request, 'attribute_option_'.$definition->getId())) {
            return new Response('Invalid security token.', Response::HTTP_FORBIDDEN);
        }
        $option = $this->ownedOption($definition, $optionId);
        if ($definitions->isOptionReferenced($option)) {
            $this->addFlash('error', 'flash.option_in_use');
        } else {
            try {
                $definition->removeOption($option);
                $em->flush();
                $this->addFlash('success', 'flash.option_removed');
            } catch (ForeignKeyConstraintViolationException) {
                $this->addFlash('error', 'flash.option_in_use');
            } catch (OptimisticLockException) {
                $this->addFlash('error', 'flash.attribute_changed');
            }
        }
        return $this->redirectToRoute('app_attributes_edit', ['id' => $definition->getId()]);
    }

    /** @param list<AttributeCategory> $categories */
    private function categoryChoices(array $categories): array
    {
        $choices = [];
        foreach ($categories as $category) {
            $choices[$category->getName()] = (string) $category->getId();
        }
        return $choices;
    }

    /** @param list<AttributeCategory> $categories */
    private function selectedCategory(array $categories, mixed $id): ?AttributeCategory
    {
        foreach ($categories as $category) {
            if ((string) $category->getId() === $id) {
                return $category;
            }
        }
        return null;
    }

    private function validToken(Request $request, string $id): bool
    {
        $token = $request->request->all()['_token'] ?? null;
        return is_string($token) && $this->isCsrfTokenValid($id, $token);
    }

    private function ownedOption(AttributeDefinition $definition, int $id): AttributeOption
    {
        foreach ($definition->getOptions() as $option) {
            if ($option->getId() === $id) {
                return $option;
            }
        }
        throw $this->createNotFoundException('Option not found.');
    }
}

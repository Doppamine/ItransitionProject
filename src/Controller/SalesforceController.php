<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Profile;
use App\Entity\User;
use App\Form\SalesforceExportType;
use App\Integration\Salesforce\SalesforceAccountContactCreator;
use App\Integration\Salesforce\SalesforceAccountContactInput;
use App\Integration\Salesforce\SalesforceApiException;
use App\Integration\Salesforce\SalesforceAuthenticationException;
use App\Integration\Salesforce\SalesforceCompositeException;
use App\Repository\ProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SalesforceController extends AbstractController
{
    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly SalesforceAccountContactCreator $creator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/account/salesforce', name: 'app_account_salesforce', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function selfExport(#[CurrentUser] User $user, Request $request): Response
    {
        return $this->export($user, $request, 'app_account_salesforce');
    }

    #[Route('/admin/users/{id}/salesforce', name: 'app_admin_user_salesforce', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminExport(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $user = $em->find(User::class, $id) ?? throw $this->createNotFoundException('User not found.');

        return $this->export($user, $request, 'app_admin_user_salesforce', ['id' => $id]);
    }

    private function export(User $user, Request $request, string $route, array $parameters = []): Response
    {
        $details = $this->profileDetails($this->profiles->findForUser($user));
        $form = $this->createForm(SalesforceExportType::class, null, [
            'action' => $this->generateUrl($route, $parameters),
            'needs_last_name' => $details['lastName'] === null,
        ]);
        $form->handleRequest($request);
        $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->validData($form);
            if ($form->isValid()) {
                try {
                    $this->creator->create(new SalesforceAccountContactInput(
                        companyName: $data['companyName'],
                        lastName: $details['lastName'] ?? $data['lastName'],
                        website: $this->optional($data['website']),
                        firstName: $details['firstName'],
                        email: $user->getEmail(),
                        jobTitle: $this->optional($data['jobTitle']),
                        phone: $this->optional($data['phone']),
                        location: $details['location'],
                    ));
                    $this->addFlash('success', 'salesforce.created');

                    return $this->redirectToRoute($route, $parameters, Response::HTTP_SEE_OTHER);
                } catch (SalesforceAuthenticationException|SalesforceApiException|SalesforceCompositeException) {
                    $form->addError(new FormError($this->translator->trans('salesforce.failed')));
                    $status = Response::HTTP_SERVICE_UNAVAILABLE;
                }
            }
        }

        return $this->render('salesforce/export.html.twig', [
            'form' => $form,
            'targetUser' => $user,
            'details' => $details,
        ], new Response(status: $status));
    }

    /** @return array<string, string> */
    private function validData(FormInterface $form): array
    {
        $data = array_map(static fn (?string $value): string => trim($value ?? ''), $form->getData());
        if ($form->getExtraData() !== []) {
            $form->addError(new FormError($this->translator->trans('salesforce.invalid_fields')));
        }
        if ($data['companyName'] === '') {
            $form->get('companyName')->addError(new FormError($this->translator->trans('salesforce.company_required')));
        }
        if ($form->has('lastName') && $data['lastName'] === '') {
            $form->get('lastName')->addError(new FormError($this->translator->trans('salesforce.last_name_required')));
        }
        foreach (SalesforceExportType::MAX_LENGTHS as $field => $limit) {
            if ($form->has($field) && mb_strlen($data[$field]) > $limit) {
                $form->get($field)->addError(new FormError($this->translator->trans('salesforce.too_long', ['%limit%' => $limit])));
            }
        }
        if ($data['website'] !== '' && (filter_var($data['website'], FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($data['website'], PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $form->get('website')->addError(new FormError($this->translator->trans('salesforce.invalid_website')));
        }

        return $data;
    }

    /** @return array{firstName: ?string, lastName: ?string, location: ?string} */
    private function profileDetails(?Profile $profile): array
    {
        $details = ['firstName' => null, 'lastName' => null, 'location' => null];
        $names = ['first name' => 'firstName', 'last name' => 'lastName', 'location' => 'location'];
        foreach ($profile?->getValues() ?? [] as $value) {
            $definition = $value->getDefinition();
            $name = $definition->getNormalizedName();
            if ($definition->isBuiltIn() && isset($names[$name])) {
                $details[$names[$name]] = $this->optional($value->getTextValue());
            }
        }

        return $details;
    }

    private function optional(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }
}

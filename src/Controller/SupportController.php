<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Position;
use App\Entity\User;
use App\Enum\PositionAccessType;
use App\Form\SupportTicketType;
use App\Integration\Dropbox\DropboxApiException;
use App\Integration\Dropbox\DropboxAuthenticationException;
use App\Position\PositionDiscussionAccess;
use App\Repository\PositionRepository;
use App\Support\SupportTicketCreator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SupportController extends AbstractController
{
    public function __construct(
        private readonly SupportTicketCreator $creator,
        private readonly PositionRepository $positions,
        private readonly PositionDiscussionAccess $positionAccess,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/support', name: 'app_support', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function index(#[CurrentUser] User $user, Request $request): Response
    {
        $pageUrl = $this->sourceUrl($request);
        $position = $this->sourcePosition($user, $request, $pageUrl);
        $form = $this->createForm(SupportTicketType::class, ['priority' => 'Average'], [
            'action' => $this->generateUrl('app_support', ['from' => $pageUrl, 'position' => $position?->getId()]),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        if ($request->isMethod('POST') && !$form->isSubmitted()) {
            $form->addError(new FormError($this->translator->trans('support.invalid_fields')));
        }
        if ($form->isSubmitted()) {
            $data = $form->getData();
            $summary = is_string($data['summary'] ?? null) ? trim($data['summary']) : '';
            if ($summary === '') {
                $form->get('summary')->addError(new FormError($this->translator->trans('support.summary_required')));
            } elseif (mb_strlen($summary) > 2000) {
                $form->get('summary')->addError(new FormError($this->translator->trans('support.summary_too_long')));
            }
            if (!$form->get('priority')->isSynchronized() || !in_array($data['priority'] ?? null, ['High', 'Average', 'Low'], true)) {
                $form->get('priority')->addError(new FormError($this->translator->trans('support.priority_invalid')));
            }
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($this->translator->trans('support.invalid_fields')));
            }
            if ($form->isValid()) {
                try {
                    if ($this->creator->create($user, $summary, $data['priority'], $pageUrl, $position)) {
                        $this->addFlash('success', 'support.created');

                        return $this->redirectToRoute('app_support', status: Response::HTTP_SEE_OTHER);
                    }
                } catch (DropboxAuthenticationException|DropboxApiException|\JsonException) {
                }
                $form->addError(new FormError($this->translator->trans('support.failed')));
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
            }
        }

        return $this->render('support/index.html.twig', ['form' => $form], new Response(status: $status));
    }

    private function sourceUrl(Request $request): string
    {
        $fallback = $this->generateUrl('app_support', referenceType: UrlGeneratorInterface::ABSOLUTE_URL);
        $source = $request->query->all()['from'] ?? null;
        if (!is_string($source) || $source === '' || preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($source))) {
            return $fallback;
        }
        if (str_starts_with($source, '/') && !str_starts_with($source, '//')) {
            return $request->getSchemeAndHttpHost().$source;
        }
        $parts = parse_url($source);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || strtolower($parts['scheme'] ?? '') !== $request->getScheme()
            || strcasecmp($parts['host'] ?? '', $request->getHost()) !== 0
            || ($parts['port'] ?? ($request->isSecure() ? 443 : 80)) !== $request->getPort()) {
            return $fallback;
        }

        return $request->getSchemeAndHttpHost().($parts['path'] ?? '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private function sourcePosition(User $user, Request $request, string $pageUrl): ?Position
    {
        $id = $request->query->all()['position'] ?? null;
        if (!is_string($id) || !ctype_digit($id) || strlen($id) > 10 || (int) $id > 2147483647
            || !preg_match('#^'.preg_quote($request->getBaseUrl().'/positions/'.$id, '#').'(?:/|$)#', (string) parse_url($pageUrl, PHP_URL_PATH))) {
            return null;
        }
        $position = $this->positions->findDetailed((int) $id);
        if ($position === null || ($position->getAccessType() === PositionAccessType::RESTRICTED && !$this->positionAccess->canParticipate($user, $position))) {
            return null;
        }

        return $position;
    }
}

<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final readonly class UiPreferencesSubscriber
{
    public function __construct(private Security $security, private LocaleSwitcher $localeSwitcher)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $user = $this->security->getUser();
        $locale = $user instanceof User ? $user->getLocale() : $request->getSession()->get('ui_locale', 'en');
        $theme = $user instanceof User ? $user->getTheme() : $request->getSession()->get('ui_theme', 'light');
        $request->setLocale($locale);
        $request->attributes->set('ui_theme', $theme);
        $this->localeSwitcher->setLocale($locale);
    }
}

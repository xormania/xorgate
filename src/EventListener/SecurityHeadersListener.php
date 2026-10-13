<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\CspNonce;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adapted from UXor's request-aware demo policy at
 * 9b2fa7309786cee435503de87cf0cd5f0a9d9782.
 */
final class SecurityHeadersListener
{
    private const DEBUG_EXCEPTION = '_xorgate_debug_exception';

    public function __construct(
        #[Autowire(service: 'app.csp_nonce.script')]
        private readonly CspNonce $scriptNonce,
        #[Autowire(service: 'app.csp_nonce.style')]
        private readonly CspNonce $styleNonce,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    #[AsEventListener(KernelEvents::EXCEPTION)]
    public function onKernelException(ExceptionEvent $event): void
    {
        if ($this->debug && $event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::DEBUG_EXCEPTION, true);
        }
    }

    #[AsEventListener(KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        // Retain same-origin referrers for Symfony's stateless CSRF checks.
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        $policy = [
            'object-src' => "'none'",
            'base-uri' => "'none'",
            'form-action' => "'self'",
            'frame-ancestors' => "'self'",
        ];

        // Symfony debug pages generate their own inline code. The exemption is
        // development-only; application pages and production errors stay strict.
        $debugPage = $this->debug && (
            $request->attributes->get(self::DEBUG_EXCEPTION)
            || str_starts_with((string) $request->attributes->get('_route'), '_')
        );
        if (!$debugPage) {
            $policy['default-src'] = "'self'";
            $policy['img-src'] = "'self' data:";
            $policy['script-src'] = "'nonce-{$this->scriptNonce}' 'strict-dynamic'";
            $policy['style-src'] = "'self' 'nonce-{$this->styleNonce}'";
            $policy['style-src-attr'] = "'none'";
        }

        $headers->set('Content-Security-Policy', implode('; ', array_map(
            static fn (string $directive, string $sources): string => "{$directive} {$sources}",
            array_keys($policy),
            $policy,
        )));
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Request-scoped nonce exposed through a Stringable Twig global.
 *
 * Adapted from UXor's demo at 9b2fa7309786cee435503de87cf0cd5f0a9d9782.
 * Keeping the value on the main request avoids nonce reuse in FrankenPHP workers.
 */
#[Exclude]
final class CspNonce implements \Stringable
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly string $directive,
    ) {
        if (!\in_array($directive, ['script', 'style'], true)) {
            throw new \InvalidArgumentException('A CSP nonce requires a script or style directive.');
        }
    }

    public function __toString(): string
    {
        if (null === $request = $this->requestStack->getMainRequest()) {
            return '';
        }

        $attribute = '_xorgate_csp_nonce_'.$this->directive;
        $nonce = $request->attributes->get($attribute);
        if (!\is_string($nonce)) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $request->attributes->set($attribute, $nonce);
        }

        return $nonce;
    }
}

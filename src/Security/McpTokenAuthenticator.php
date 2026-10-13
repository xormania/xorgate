<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class McpTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(#[Autowire('%env(XORGATE_MCP_TOKEN)%')] private readonly string $token)
    {
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $authorization = $request->headers->get('Authorization', '');
        if (strlen($this->token) < 32 || !str_starts_with($authorization, 'Bearer ') || !hash_equals($this->token, substr($authorization, 7))) {
            throw new BadCredentialsException('A valid MCP bearer token is required.');
        }

        return new SelfValidatingPassport(new UserBadge('local-operator', static fn () => new InMemoryUser('local-operator', null, ['ROLE_MCP'])));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->start($request, $exception);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'MCP authentication required.'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
    }
}

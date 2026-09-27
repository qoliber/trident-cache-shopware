<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Controller;

use Qoliber\Trident\Admin\AdminException;
use Qoliber\Trident\Admin\AdminService;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the Trident screens (`/api/_action/trident/...`).
 *
 * Admin API routes authenticate with the administration's OAuth bearer token
 * (not a cookie), so there is no cross-site request to forge; reads need the
 * `trident_cache:read` privilege, every change `trident_cache:update`, and
 * the actions in {@see AdminService::CONFIRM} an explicit confirmation.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class AdminController extends AbstractController
{
    public function __construct(private readonly AdminService $admin)
    {
    }

    #[Route(path: '/api/_action/trident/overview', name: 'api.action.trident.overview', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['trident_cache:read']], methods: ['GET'])]
    public function overview(): JsonResponse
    {
        return new JsonResponse($this->admin->overview());
    }

    #[Route(path: '/api/_action/trident/screen/{screen}', name: 'api.action.trident.screen', requirements: ['screen' => '[a-z]+'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['trident_cache:read']], methods: ['GET'])]
    public function screen(string $screen, Request $request): JsonResponse
    {
        return $this->guard(fn (): array => $this->admin->screen($screen, $request->query->all()));
    }

    #[Route(path: '/api/_action/trident/action/{action}', name: 'api.action.trident.action', requirements: ['action' => '[a-z_]+'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['trident_cache:update']], methods: ['POST'])]
    public function action(string $action, Request $request): JsonResponse
    {
        $body = $request->getContent() === '' ? [] : json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'The body must be a JSON object'], 400);
        }

        return $this->guard(fn (): array => $this->admin->action($action, $body));
    }

    /**
     * @param callable(): array<string, mixed> $call
     */
    private function guard(callable $call): JsonResponse
    {
        try {
            return new JsonResponse($call());
        } catch (AdminException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}

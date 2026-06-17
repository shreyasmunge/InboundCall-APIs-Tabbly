<?php

declare(strict_types=1);

namespace Tabbly\Inbound\Controllers;

use Tabbly\Inbound\Repositories\AgentRepository;
use Tabbly\Inbound\Services\AuthService;
use Tabbly\Inbound\Services\InboundService;
use Tabbly\Inbound\Support\ApiResponse;
use Tabbly\Inbound\Support\PhoneUtil;

final class InboundController
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly InboundService $inbound = new InboundService(),
    ) {
    }

    public function handle(string $action, ?string $apiKey, ?string $agentId): void
    {
        try {
            if ($apiKey === null || trim($apiKey) === '') {
                ApiResponse::error('Invalid or missing API key', 401);
            }

            if ($agentId === null || trim($agentId) === '') {
                ApiResponse::error('agent_id is required', 400);
            }

            $agentId = trim($agentId);
            $org = $this->auth->resolveOrganization(trim($apiKey));
            if ($org === null) {
                ApiResponse::error('Invalid or missing API key', 401);
            }

            if (($org['subscription_status'] ?? 'no') !== 'yes') {
                ApiResponse::error('Subscription inactive', 403);
            }

            $organizationId = $org['organization_id'];
            $agent = $this->agents->findBasicById($agentId);
            if ($agent === null) {
                ApiResponse::error('Agent not found', 404);
            }

            if ((string) ($agent['organization_id'] ?? '') !== (string) $organizationId) {
                ApiResponse::error('Agent does not belong to this organization', 403);
            }

            $phone = $agent['phone_number'] ?? '';
            if (PhoneUtil::isFreeTier($phone)) {
                ApiResponse::error(
                    'Inbound calling is not available for free-tier phone numbers. Purchase a number to enable inbound.',
                    403
                );
            }

            $data = match ($action) {
                'status' => $this->inbound->getStatus($agentId),
                'create' => $this->inbound->create($agentId, $organizationId),
                'refresh-metadata' => $this->inbound->refreshMetadata($agentId),
                'disable' => $this->inbound->disable($agentId),
                default => throw new \InvalidArgumentException('Unknown action'),
            };

            ApiResponse::success($data);
        } catch (\InvalidArgumentException $e) {
            ApiResponse::error($e->getMessage(), 404);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            if (!is_int($code) || $code < 400 || $code > 599) {
                $code = 500;
            }
            ApiResponse::error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            ApiResponse::error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
}

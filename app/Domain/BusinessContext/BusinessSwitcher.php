<?php

namespace App\Domain\BusinessContext;

use App\Models\Business;
use App\Models\BusinessSwitchAudit;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BusinessSwitcher
{
    public function __construct(private readonly ActiveBusinessContext $context) {}

    /**
     * @return Collection<int, Business>
     */
    public function allowedBusinesses(User $user): Collection
    {
        return $user->businesses()
            ->where('is_active', true)
            ->whereIn('connection_name', config('business.allowed_connections'))
            ->get();
    }

    public function resolve(User $user, Session $session): ?Business
    {
        $businesses = $this->allowedBusinesses($user);
        $sessionUuid = $session->get(config('business.session_key'));

        $business = $sessionUuid
            ? $businesses->firstWhere('uuid', $sessionUuid)
            : null;

        $business ??= $user->last_business_id
            ? $businesses->firstWhere('id', $user->last_business_id)
            : null;

        $business ??= $businesses->first();

        if (! $business) {
            $session->forget(config('business.session_key'));
            $this->context->clear();

            return null;
        }

        $session->put(config('business.session_key'), $business->uuid);
        $this->context->set($business);

        if ($user->last_business_id !== $business->id) {
            $user->forceFill(['last_business_id' => $business->id])->save();
        }

        return $business;
    }

    public function switch(User $user, string $businessUuid, Request $request): Business
    {
        $fromBusinessId = $this->context->id();
        $requestedBusiness = Business::query()->where('uuid', $businessUuid)->first();
        $allowedConnection = $requestedBusiness
            && in_array($requestedBusiness->connection_name, config('business.allowed_connections'), true);
        $hasAccess = $requestedBusiness
            && $user->businesses()
                ->whereKey($requestedBusiness->id)
                ->where('is_active', true)
                ->exists();

        if (! $requestedBusiness || ! $allowedConnection || ! $hasAccess) {
            $this->audit(
                user: $user,
                request: $request,
                requestedUuid: $businessUuid,
                fromBusinessId: $fromBusinessId,
                toBusinessId: $requestedBusiness?->id,
                result: 'denied',
                reason: ! $requestedBusiness
                    ? 'unknown_business'
                    : (! $allowedConnection ? 'connection_not_allowed' : 'access_denied'),
            );

            throw new AccessDeniedHttpException('K tomuto fakturačnímu subjektu nemáte oprávnění.');
        }

        $request->session()->put(config('business.session_key'), $requestedBusiness->uuid);
        $user->forceFill(['last_business_id' => $requestedBusiness->id])->save();
        $this->context->set($requestedBusiness);

        $this->audit(
            user: $user,
            request: $request,
            requestedUuid: $businessUuid,
            fromBusinessId: $fromBusinessId,
            toBusinessId: $requestedBusiness->id,
            result: 'success',
        );

        return $requestedBusiness;
    }

    private function audit(
        User $user,
        Request $request,
        string $requestedUuid,
        ?int $fromBusinessId,
        ?int $toBusinessId,
        string $result,
        ?string $reason = null,
    ): void {
        BusinessSwitchAudit::query()->create([
            'user_id' => $user->id,
            'from_business_id' => $fromBusinessId,
            'to_business_id' => $toBusinessId,
            'requested_business_uuid' => $requestedUuid,
            'result' => $result,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'occurred_at' => now(),
        ]);
    }
}

<?php

namespace App\Support;

use Spatie\Permission\Contracts\PermissionsTeamResolver;

class TenantTeamResolver implements PermissionsTeamResolver
{
    protected $teamId = null;

    public function getPermissionsTeamId(): int|string|null
    {
        if ($this->teamId !== null) {
            return $this->teamId;
        }

        // Get the current authenticated user's tenant_id
        if (auth()->check() && auth()->user()->tenant_id) {
            return auth()->user()->tenant_id;
        }

        // If no user is authenticated, we can't determine tenant
        return null;
    }

    public function setPermissionsTeamId($id): void
    {
        $this->teamId = $id;
    }
}
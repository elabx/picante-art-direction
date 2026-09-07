<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function view(User $user, Campaign $record): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function create(User $user): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function update(User $user, Campaign $record): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function delete(User $user, Campaign $record): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function deleteAny(User $user): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function restore(User $user, Campaign $record): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function restoreAny(User $user): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function forceDelete(User $user, Campaign $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}

<?php

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;

class GenerationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function view(User $user, Generation $record): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Generation $record): bool
    {
        return false;
    }

    public function delete(User $user, Generation $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Generation $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Generation $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}

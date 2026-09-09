<?php

namespace App\Policies;

use App\Models\Pipeline;
use App\Models\User;

class PipelinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function view(User $user, Pipeline $record): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function create(User $user): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function update(User $user, Pipeline $record): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function delete(User $user, Pipeline $record): bool
    {
        return $user->fresh()?->isArtDirector() === true && $record->campaigns()->withTrashed()->doesntExist();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Pipeline $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Pipeline $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}

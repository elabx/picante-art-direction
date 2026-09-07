<?php

namespace App\Policies;

use App\Models\PipelineField;
use App\Models\User;

class PipelineFieldPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function view(User $user, PipelineField $record): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PipelineField $record): bool
    {
        return $user->fresh()?->isArtDirector() === true;
    }

    public function delete(User $user, PipelineField $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, PipelineField $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, PipelineField $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}

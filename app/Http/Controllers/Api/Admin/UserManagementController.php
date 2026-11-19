<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'role' => ['nullable', Rule::in(['listener', 'artist', 'admin'])],
            'blocked' => ['nullable', Rule::in(['0', '1'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = User::query()->withCount(['tracks', 'playlists', 'followers']);
        $term = trim((string) ($data['q'] ?? ''));

if ($term !== '') {
    if (ctype_digit($term)) {
        $query->where('id', $term);
    } else {
        $query->where(function ($q) use ($term) {
            $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%');
        });
    }
}
        if (! empty($data['role'])) $query->where('role', $data['role']);
        if (isset($data['blocked']) && $data['blocked'] !== '') $query->where('is_blocked', $data['blocked'] === '1');
        return AdminUserResource::collection($query->orderByDesc('id')->paginate($data['per_page'] ?? 20)->withQueryString());
    }

    public function show(User $user): AdminUserResource
    {
        return new AdminUserResource($user->loadCount(['tracks', 'playlists', 'followers']));
    }

    public function update(Request $request, User $user): AdminUserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'role' => ['sometimes', Rule::in(['listener', 'artist'])],
        ]);
        // Never allow this endpoint to demote an admin or grant administrative rights.
        abort_if($user->isAdmin() && array_key_exists('role', $data), 409, 'Роль администратора нельзя изменить в этой форме.');
        if (isset($data['email']) && $data['email'] !== $user->email) {
            $user->forceFill(['email_verified_at' => null]);
        }
        $user->fill($data)->save();
        return new AdminUserResource($user->refresh()->loadCount(['tracks', 'playlists', 'followers']));
    }
}

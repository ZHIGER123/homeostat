<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function destroy(Request $request, AccountDeletionService $accounts): Response
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:128'],
            'confirmation' => ['required', Rule::in(['УДАЛИТЬ'])],
        ]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Неверный текущий пароль.']);
        }
        $accounts->delete($request->user(), $request->user());
        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    public function forgot(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /** Always the same answer, whether or not the account exists (no account enumeration). */
    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($data); // broker also throttles per address

        return back()->with('success', __('If an account exists for that email, a reset link is on its way.'));
    }

    public function show(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required'], 'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            // Sign every other device out as well.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            Audit::record('auth.password_reset', $user);
            event(new PasswordReset($user));
        });

        return $status === Password::PasswordReset
            ? redirect()->route('login')->with('success', __('Your password was changed. Please sign in.'))
            : back()->withErrors(['email' => __('This reset link is invalid or has expired.')]);
    }
}

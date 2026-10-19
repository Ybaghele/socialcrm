<?php

namespace App\Http\Controllers\Auth;

use App\Actions\CreateWorkspace;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * Signing up also provisions the tenant. Without a workspace there is no
     * /w/{slug} URL to send the user to, so the account would dead-end on the
     * dashboard. User and workspace are created in one transaction: if the trial
     * plan is missing/misconfigured we roll both back rather than leave an
     * account that cannot reach a single screen.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'workspace_name' => ['nullable', 'string', 'max:120'],
        ]);

        [$user, $workspace] = DB::transaction(function () use ($request): array {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $workspace = app(CreateWorkspace::class)->handle(
                $user,
                $request->string('workspace_name')->trim()->value() ?: $user->name,
            );

            return [$user, $workspace];
        });

        event(new Registered($user));

        Auth::login($user);

        // Land on the screen that makes the product useful instead of an empty inbox.
        // intended() lets an invitation link lead a brand-new user into the invite
        // flow right after signup; without one it falls back to the channel screen.
        return redirect()
            ->intended(route('channels.index', ['workspace' => $workspace->slug]))
            ->with('success', 'Welcome! Connect a channel to start receiving conversations.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DemoAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DemoLoginController extends Controller
{
    /**
     * Signs the visitor in as a demo account, without a password. While demo sign-in is off, or
     * when the seeder has not created the account, this answers as if the route did not exist.
     * The account is found by the mark the seeder left, never by email, so nobody's own account
     * can be opened this way.
     */
    public function store(Request $request, DemoAccount $demoAccount): RedirectResponse
    {
        abort_unless(config('app.demo_login'), 404);

        $user = User::query()->where('demo_account', $demoAccount)->firstOrFail();

        Auth::login($user);
        $request->session()->regenerate();

        return to_route($demoAccount->isOperator() ? 'operatorReport.show' : 'generations.index');
    }
}

<?php

declare(strict_types=1);

use App\Enums\DemoAccount;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Env;
use Inertia\Testing\AssertableInertia;

/**
 * The seeded user behind a demo account.
 */
function demoUser(DemoAccount $account): User
{
    return User::query()->where('demo_account', $account)->sole();
}

describe('while demo sign-in is on', function (): void {
    beforeEach(function (): void {
        config(['app.demo_login' => true]);
        $this->seed(DatabaseSeeder::class);
    });

    it('signs a visitor in as the demo account they chose and shows them its page', function (DemoAccount $account, string $landsOn): void {
        $this->post(route('demoLogin.store', $account))->assertRedirect(route($landsOn));

        $this->assertAuthenticatedAs(demoUser($account));
    })->with([
        'a demo user' => [DemoAccount::Ada, 'generations.index'],
        'the other demo user' => [DemoAccount::Bob, 'generations.index'],
        'the demo operator' => [DemoAccount::Operator, 'operatorReport.show'],
    ]);

    it('starts a new session on sign-in', function (): void {
        $this->get(route('login'));
        $sessionBefore = session()->getId();

        $this->post(route('demoLogin.store', DemoAccount::Ada));

        expect(session()->getId())->not->toBe($sessionBefore);
    });

    it('knows no account outside the three', function (): void {
        User::factory()->create(['email' => 'someone@example.com']);

        $this->post(route('demoLogin.store', 'someone'))->assertNotFound();
        $this->assertGuest();
    });

    it('refuses more attempts than the limit allows', function (): void {
        foreach (range(1, 10) as $attempt) {
            $this->post(route('demoLogin.store', DemoAccount::Ada));
            auth()->logout();
        }

        $this->post(route('demoLogin.store', DemoAccount::Ada))->assertTooManyRequests();
    });

    it('lists the demo accounts on the sign-in page', function (): void {
        $this->get(route('login'))->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('auth/Login')
            ->where('demoAccounts', [
                ['key' => 'ada', 'name' => 'Ada (demo user)', 'description' => 'Generate images and see your own history and charges.'],
                ['key' => 'bob', 'name' => 'Bob (demo user)', 'description' => 'Generate images and see your own history and charges.'],
                ['key' => 'operator', 'name' => 'Operator (demo)', 'description' => 'The operator report: user charges, provider costs and profit, reconciled with Pixelforge.'],
            ]));
    });

    it('does not let a demo account change its profile', function (DemoAccount $account): void {
        $user = demoUser($account);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Mine now', 'email' => 'mine@example.org'])
            ->assertForbidden();

        expect($user->fresh()->only(['name', 'email']))->toBe(['name' => $account->label(), 'email' => $account->email()]);
    })->with(DemoAccount::cases());

    it('tells the profile page that a demo account cannot be changed', function (): void {
        $this->actingAs(demoUser(DemoAccount::Ada))
            ->get(route('profile.edit'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('canUpdate', false));

        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('canUpdate', true));
    });

    it('does not let a demo account change its password, which nobody knows', function (): void {
        $user = demoUser(DemoAccount::Operator);

        $this->actingAs($user)
            ->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertSessionHasErrors('current_password');

        expect($user->fresh()->password)->toBe($user->password);
    });

    it('does not open a demo account with a password', function (): void {
        $this->post(route('login.store'), ['email' => DemoAccount::Operator->email(), 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    });
});

it('answers as not found while demo sign-in is off', function (): void {
    $this->seed(DatabaseSeeder::class);

    $this->post(route('demoLogin.store', DemoAccount::Operator))->assertNotFound();
    $this->assertGuest();
});

it('answers as not found before the demo accounts are created', function (): void {
    config(['app.demo_login' => true]);

    $this->post(route('demoLogin.store', DemoAccount::Ada))->assertNotFound();
    $this->assertGuest();
});

it('never opens the account of someone who merely holds a demo account\'s email', function (): void {
    config(['app.demo_login' => true]);
    User::factory()->create(['email' => DemoAccount::Bob->email()]);
    $this->seed(DatabaseSeeder::class);

    $this->post(route('demoLogin.store', DemoAccount::Bob))->assertNotFound();
    $this->assertGuest();
});

it('lists only the demo accounts that exist', function (): void {
    config(['app.demo_login' => true]);
    User::factory()->create(['email' => DemoAccount::Bob->email()]);
    $this->seed(DatabaseSeeder::class);

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->has('demoAccounts', 2)
        ->where('demoAccounts.0.key', 'ada')
        ->where('demoAccounts.1.key', 'operator'));
});

it('lists no demo accounts before they are created', function (): void {
    config(['app.demo_login' => true]);

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('demoAccounts', []));
});

/**
 * Reads `config/app.php` afresh with the variable set as an environment file would set it.
 */
it('is switched on only by a value that clearly means on', function (string $value, bool $on): void {
    $before = Env::get('DEMO_LOGIN_ENABLED');
    $_ENV['DEMO_LOGIN_ENABLED'] = $_SERVER['DEMO_LOGIN_ENABLED'] = $value;
    putenv("DEMO_LOGIN_ENABLED={$value}");

    try {
        expect((require config_path('app.php'))['demo_login'])->toBe($on);
    } finally {
        $_ENV['DEMO_LOGIN_ENABLED'] = $_SERVER['DEMO_LOGIN_ENABLED'] = $before;
        putenv("DEMO_LOGIN_ENABLED={$before}");
    }
})->with([
    'true' => ['true', true],
    '1' => ['1', true],
    'on' => ['on', true],
    'yes' => ['yes', true],
    'false' => ['false', false],
    '0' => ['0', false],
    'off' => ['off', false],
    'no' => ['no', false],
    'empty' => ['', false],
    'anything else' => ['enabled', false],
]);

it('lists no demo accounts on the sign-in page while demo sign-in is off', function (): void {
    $this->seed(DatabaseSeeder::class);

    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('auth/Login')
        ->where('demoAccounts', []));
});

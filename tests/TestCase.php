<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use OrcaRail\Cashier\Billable;
use OrcaRail\Cashier\CashierServiceProvider;
use OrcaRail\Cashier\Tests\Fixtures\FakeHttpClient;
use OrcaRail\HttpClient\ClientInterface;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected FakeHttpClient $fakeHttp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeHttp = new FakeHttpClient();

        $app = $this->app;
        if ($app === null) {
            throw new \RuntimeException('Application is not bootstrapped.');
        }

        $app->instance(ClientInterface::class, $this->fakeHttp);

        // Re-bind client so it picks up the fake HTTP client.
        $app->forgetInstance(\OrcaRail\OrcaRailClient::class);
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CashierServiceProvider::class];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('orcarail-cashier.api_key', 'ak_test');
        $app['config']->set('orcarail-cashier.api_secret', 'sk_test');
        $app['config']->set('orcarail-cashier.webhook.secret', 'whsec_test');
        $app['config']->set('orcarail-cashier.currency', 'usd');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function createUser(string $email = 'user@example.com'): User
    {
        return User::query()->create([
            'email' => $email,
            'name' => 'Test User',
        ]);
    }
}

/**
 * @property int $id
 * @property string $email
 * @property string|null $name
 */
class User extends Model
{
    use Billable;

    protected $guarded = [];

    protected $table = 'users';
}

<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Tests;

use BadRush\Weathermap\WeathermapServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private string $tempStorageDir = '';

    protected function getPackageProviders($app): array
    {
        return [
            WeathermapServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->tempStorageDir = sys_get_temp_dir().'/wm-test-'.uniqid('', true);
        @mkdir($this->tempStorageDir, 0750, true);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('weathermap.storage_dir', $this->tempStorageDir);
        $app['config']->set('weathermap.rrd_dir', $this->tempStorageDir.'/rrd');
        $app['config']->set('auth.providers.users.model', TestUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->json('roles')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->increments('device_id');
            $table->string('hostname');
            $table->string('sysName')->nullable();
            $table->string('os')->nullable();
            $table->boolean('status')->default(true);
        });

        Schema::create('ports', function (Blueprint $table): void {
            $table->increments('port_id');
            $table->unsignedInteger('device_id');
            $table->string('ifName')->nullable();
            $table->string('ifDescr')->nullable();
            $table->string('ifAlias')->nullable();
            $table->double('ifInOctets_rate')->default(0);
            $table->double('ifOutOctets_rate')->default(0);
            $table->double('ifSpeed')->default(1_000_000_000);
            $table->string('ifOperStatus')->default('up');
            $table->unsignedInteger('poll_time')->nullable();
        });
    }

    protected function tearDown(): void
    {
        if ($this->tempStorageDir !== '' && is_dir($this->tempStorageDir)) {
            $this->deleteDirectory($this->tempStorageDir);
        }
        parent::tearDown();
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    protected function makeUser(string $name, array $roles = ['user'], array $permissions = []): TestUser
    {
        return TestUser::query()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'.'.uniqid().'@example.test',
            'password' => 'secret',
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    private function deleteDirectory(string $dir): void
    {
        $items = scandir($dir);
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

class TestUser extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'roles', 'permissions'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'permissions' => 'array',
        ];
    }
}

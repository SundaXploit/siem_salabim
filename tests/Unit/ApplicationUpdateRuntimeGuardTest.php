<?php

namespace Tests\Unit;

use App\Exceptions\ApplicationUpdateException;
use App\Services\ApplicationUpdateRuntimeGuard;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationUpdateRuntimeGuardTest extends TestCase
{
    private ApplicationUpdateRuntimeGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        config(['application_updates.runtime_marker' => storage_path('framework/testing/runtime-guard-'.Str::uuid().'.lock')]);
        $this->guard = app(ApplicationUpdateRuntimeGuard::class);
    }

    protected function tearDown(): void
    {
        if (is_file($this->guard->markerPath())) {
            unlink($this->guard->markerPath());
        }
        parent::tearDown();
    }

    public function test_only_the_owner_can_release_a_deployment_marker(): void
    {
        $owner = (string) Str::uuid();
        $this->guard->activate($owner);
        $this->assertTrue($this->guard->isActive());

        try {
            $this->guard->deactivate((string) Str::uuid());
            $this->fail('A different owner released the deployment guard.');
        } catch (ApplicationUpdateException) {
            $this->assertSame($owner, trim(file_get_contents($this->guard->markerPath())));
        }

        $this->guard->deactivate($owner);
        $this->assertFalse($this->guard->isActive());
    }

    public function test_a_second_deployment_cannot_replace_an_active_marker(): void
    {
        $owner = (string) Str::uuid();
        $this->guard->activate($owner);
        try {
            $this->guard->activate((string) Str::uuid());
            $this->fail('An active deployment marker was replaced.');
        } catch (ApplicationUpdateException) {
            $this->assertSame($owner, trim(file_get_contents($this->guard->markerPath())));
        }
    }

    public function test_invalid_owner_is_rejected_before_creating_a_marker(): void
    {
        try {
            $this->guard->activate('../invalid');
            $this->fail('An invalid deployment owner was accepted.');
        } catch (ApplicationUpdateException) {
            $this->assertFalse($this->guard->isActive());
        }
    }
}

<?php

namespace Tests;

use App\Modules\Identity\Contracts\AvatarFetcher;
use App\Modules\Identity\Testing\FakeAvatarFetcher;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The real fetcher talks to the Discord CDN; no test may ever reach it. The fake fails until scripted.
        $this->app->singleton(AvatarFetcher::class, FakeAvatarFetcher::class);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

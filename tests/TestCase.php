<?php

namespace Funseco\Votes\Tests;

use Funseco\Admin\Tests\Support\AbilitySnapshot;
use Funseco\Core\Testing\TestCase as CoreTestCase;
use Funseco\Pay2Play\Pay2PlayServiceProvider;
use Funseco\Jukebox\JukeboxServiceProvider;
use Funseco\Admin\AdminServiceProvider;
use Funseco\TimeLimit\TimeLimitServiceProvider;
use Funseco\Votes\VotesServiceProvider;

abstract class TestCase extends CoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AbilitySnapshot::apply();
    }

    /** Dependencies first, then this plugin (isolated runs load only these). */
    protected function plugins(): array
    {
        return [
            AdminServiceProvider::class,
            TimeLimitServiceProvider::class,
            JukeboxServiceProvider::class,
            Pay2PlayServiceProvider::class,
            VotesServiceProvider::class,
        ];
    }
}

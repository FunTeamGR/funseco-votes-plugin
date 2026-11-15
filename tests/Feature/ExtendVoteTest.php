<?php
namespace Funseco\Votes\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Funseco\Core\Testing\FakesServer;
use Funseco\Votes\Tests\TestCase;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\CurrentSpectators;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Chat\Facades\Chat;
use Funseco\Core\SharedKernel\Events\ReplayRequested;
use Funseco\TimeLimit\FlexiTimer;
use Funseco\Votes\VoteBox;

class ExtendVoteTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        CurrentPlayers::clear();
        CurrentSpectators::clear();
        foreach (['a', 'b'] as $login) {
            CurrentPlayers::add(Player::factory()->create(['login' => $login, 'nickname' => strtoupper($login)]));
        }
        config(['timelimit.flexitime.default_time' => 5, 'timelimit.flexitime.author_mult' => 0, 'timelimit.flexitime.custom_time' => false]);
        $this->map = Map::factory()->create(['uid' => 'U1']);
        CurrentMap::set($this->map);
        ServerInfo::setGameMode(1);
        app(VoteBox::class)->mapStarted($this->map);
    }

    private function passExtend(string $login = 'a'): void
    {
        Chat::resolve('/extend', $login);
        app(VoteBox::class)->pass();
    }

    public function test_extend_is_an_alias_of_replay()
    {
        Chat::resolve('/extend', 'a');
        $this->assertSame('replay', app(VoteBox::class)->running()['type']);
    }

    public function test_passed_vote_adds_default_time_instead_of_replaying()
    {
        $timer = app(FlexiTimer::class);
        $timer->start($this->map);
        $timer->set(120);
        $dispatched = [];
        Event::listen(ReplayRequested::class, function ($e) use (&$dispatched) {
            $dispatched[] = $e;
        });

        $this->passExtend();

        $this->assertSame(420, $timer->secondsLeft());
        $this->assertSame([], $this->sent('ChooseNextChallenge'));
        $this->assertSame([], $dispatched);
        $all = $this->messages('ChatSendServerMessage');
        $this->assertSame(format_colors('{#server}>> {#vote}Track time extended by {#highlite}5:00{#vote} minutes!'), end($all));
    }

    public function test_paused_timer_stays_paused()
    {
        $timer = app(FlexiTimer::class);
        $timer->start($this->map);
        $timer->set(120);
        $timer->pause();

        $this->passExtend();

        $this->assertSame(420, $timer->secondsLeft());
        $this->assertTrue($timer->paused());
    }

    public function test_falls_back_to_replay_without_flexitime()
    {
        state()->forget('timelimit.flexi');
        Event::forget(ReplayRequested::class);

        $this->passExtend();

        $this->assertSame([[$this->map->file_name]], $this->sent('ChooseNextChallenge'));
    }

    public function test_extend_counts_toward_replays_in_a_row()
    {
        app(FlexiTimer::class)->start($this->map);
        $this->passExtend();

        Chat::resolve('/extend', 'b');
        $toB = array_values(array_map(fn ($a) => $a[0], array_filter($this->sent('ChatSendServerMessageToLogin'), fn ($a) => $a[1] === 'b')));
        $this->assertSame([format_colors('{#server}> {#vote}No more than {#highlite}1{#vote} replay per track allowed!')], $toB);
    }

    private function assertPlainReplay(FlexiTimer $timer, int $left): void
    {
        $this->assertSame($left, $timer->secondsLeft(), 'time untouched');
        $this->assertSame([[$this->map->file_name]], $this->sent('ChooseNextChallenge'));
        $this->assertStringNotContainsString('extended', implode('|', $this->messages('ChatSendServerMessage')));
    }

    public function test_expired_timer_is_not_extended()
    {
        Event::forget(ReplayRequested::class);
        $timer = app(FlexiTimer::class);
        $timer->start($this->map);
        $timer->set(0);
        $timer->expireIfDone();

        $this->passExtend();

        $this->assertPlainReplay($timer, 0);
    }

    public function test_timer_stopped_at_the_score_screen_is_not_extended()
    {
        Event::forget(ReplayRequested::class);
        $timer = app(FlexiTimer::class);
        $timer->start($this->map);
        $timer->set(120);
        $timer->stop();

        $this->passExtend();

        $this->assertPlainReplay($timer, 120);
    }

    public function test_timer_ending_after_the_vote_started_falls_back_to_replay()
    {
        Event::forget(ReplayRequested::class);
        $timer = app(FlexiTimer::class);
        $timer->start($this->map);
        $timer->set(120);
        Chat::resolve('/extend', 'a');
        $timer->stop();
        app(VoteBox::class)->pass();

        $this->assertPlainReplay($timer, 120);
    }

    public function test_vote_title_says_extend_only_while_time_can_be_added()
    {
        $timer = app(FlexiTimer::class);
        $timer->start($this->map);
        Chat::resolve('/replay', 'a');
        $this->assertSame('Extend Track time', app(VoteBox::class)->running()['desc']);
        $this->assertStringContainsString('Extend Track time', strip_tags(implode('|', $this->messages('ChatSendServerMessage'))));

        app(VoteBox::class)->cancel('quiet');
        app(VoteBox::class)->mapEnded();
        app(VoteBox::class)->mapStarted($this->map);
        $timer->stop();
        Chat::resolve('/replay', 'a');
        $this->assertSame('Replay Track after Finish', app(VoteBox::class)->running()['desc']);
    }
}

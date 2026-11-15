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
use Funseco\Core\SharedKernel\Events\PlayerDisconnected;
use Funseco\Core\SharedKernel\Events\ReplayRequested;
use Funseco\Core\SharedKernel\Events\VoteCancelled;
use Funseco\Core\SharedKernel\Events\VotePassed;
use Funseco\Core\SharedKernel\Events\VotePassRequested;
use Funseco\Core\SharedKernel\Events\VoteRequested;
use Funseco\Votes\VoteBox;

class VotesTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    /** @var array<string, Player> */
    private array $p = [];
    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        CurrentPlayers::clear();
        CurrentSpectators::clear();
        config(['adminops.masteradmins' => ['boss']]);
        foreach (['a' => 'Alice', 'b' => 'Bob', 'c' => 'Carol', 'd' => 'Dave', 'e' => 'Eve', 'boss' => 'Boss'] as $login => $nick) {
            $this->p[$login] = Player::factory()->create(['login' => $login, 'nickname' => $nick]);
            CurrentPlayers::add($this->p[$login]);
        }
        $this->map = Map::factory()->create(['uid' => 'U1']);
        CurrentMap::set($this->map);
        ServerInfo::setGameMode(1);   // Time Attack
        app(VoteBox::class)->mapStarted($this->map);
    }

    private function broadcasts(): array
    {
        return $this->messages('ChatSendServerMessage');
    }

    private function lastBroadcast(): string
    {
        $all = $this->broadcasts();

        return (string) end($all);
    }

    private function toLogin(string $login): array
    {
        return array_values(array_map(fn ($a) => $a[0], array_filter($this->sent('ChatSendServerMessageToLogin'), fn ($a) => $a[1] === $login)));
    }

    public function test_skip_vote_passes_at_the_required_number()
    {
        // 6 players × 0.6 = 4 votes
        Chat::resolve('/skip', 'a');
        $this->assertSame(format_colors("{#server}>> {#highlite}Alice{#vote} is requesting to {#highlite}Skip this Track{#vote}.\n{#vote}This will require {#highlite}4{#vote} votes to pass. Type {#highlite}/y{#vote} to vote yes."), $this->broadcasts()[0]);

        foreach (['a', 'b', 'c'] as $login) {
            Chat::resolve('/y', $login);
        }
        $this->assertSame([], $this->sent('NextChallenge'));
        $this->assertSame(format_colors('{#server}>> {#highlite}1{#vote} more vote needed to {#highlite}Skip this Track{#vote}. Type {#highlite}/y{#vote} to vote yes.'), $this->lastBroadcast());

        Chat::resolve('/y', 'd');
        $this->assertSame([[]], $this->sent('NextChallenge'));
        $this->assertContains(format_colors('{#server}>> {#vote}Vote Passed! Server to {#highlite}Skip this Track{#vote}.'), $this->broadcasts());
        $this->assertNull(app(VoteBox::class)->running());
    }

    public function test_one_vote_per_player_and_one_vote_at_a_time()
    {
        Chat::resolve('/skip', 'a');
        Chat::resolve('/y', 'a'); // the starter already counts
        Chat::resolve('/replay', 'b');

        $this->assertSame([format_colors('{#server}> {#error}You have already voted!')], $this->toLogin('a'));
        $this->assertSame([format_colors('{#server}> {#error}A vote is already in progress. Wait for this to complete.')], $this->toLogin('b'));
    }

    public function test_per_track_limit_resets_on_the_next_map()
    {
        config(['votes.types.skip.per_track' => 1]);
        Chat::resolve('/skip', 'a');
        Chat::resolve('/cancel', 'a');
        Chat::resolve('/skip', 'b');
        $this->assertSame([format_colors('{#server}> {#error}Already ran {#highlite}$i 1{#error} /skip vote for this track - that\'s enough!')], $this->toLogin('b'));

        app(VoteBox::class)->mapEnded();
        app(VoteBox::class)->mapStarted(Map::factory()->create());
        $this->assertTrue(app(VoteBox::class)->start($this->p['b'], 'skip', 'Skip this Track'));
    }

    public function test_spectators_and_scoreboard_block_votes()
    {
        CurrentSpectators::add($this->p['e']);
        Chat::resolve('/skip', 'e');
        $this->assertSame([format_colors('{#server}> {#error}Spectators not allowed to start votes!')], $this->toLogin('e'));

        app(VoteBox::class)->mapEnded();
        Chat::resolve('/skip', 'a');
        $this->assertSame([format_colors('{#server}> {#error}Chat-based votes disabled during scoreboard!')], $this->toLogin('a'));
    }

    public function test_time_attack_vote_expires_with_reminders()
    {
        config(['votes.types.skip.expire_seconds' => 90, 'votes.reminder_interval' => 30]);
        $this->travelTo(now());
        Chat::resolve('/skip', 'a');

        $this->travel(31)->seconds();
        app(VoteBox::class)->tick();
        $this->assertStringContainsString('more votes needed', $this->lastBroadcast());

        $this->travel(60)->seconds();
        app(VoteBox::class)->tick();
        $this->assertSame(format_colors('{#server}>> {#vote}Vote to {#highlite}Skip this Track{#vote} expired by {#highlite}Server{#vote}!'), $this->lastBroadcast());
        $this->assertNull(app(VoteBox::class)->running());
    }

    public function test_rounds_vote_expires_after_rounds()
    {
        ServerInfo::setGameMode(0);
        Chat::resolve('/endround', 'a');

        app(VoteBox::class)->roundEnded();
        $this->assertNull(app(VoteBox::class)->running(), 'endround expires after 1 round');
    }

    public function test_map_change_cancels_running_vote()   // [RF 2]
    {
        Event::fake([VoteCancelled::class]);
        Chat::resolve('/skip', 'a');

        app(VoteBox::class)->mapEnded();

        $this->assertSame(format_colors('{#server}>> {#vote}Current vote cancelled!'), $this->lastBroadcast());
        Event::assertDispatched(VoteCancelled::class, fn ($e) => $e->type === 'skip');
    }

    public function test_starter_or_target_leaving_cancels()   // [RF 1]
    {
        Chat::resolve('/skip', 'a');
        app(VoteBox::class)->playerLeft($this->p['b']);
        $this->assertNotNull(app(VoteBox::class)->running(), 'others leaving changes nothing');
        app(VoteBox::class)->playerLeft($this->p['a']);
        $this->assertNull(app(VoteBox::class)->running());

        Chat::resolve('/kick c', 'b');
        app(VoteBox::class)->playerLeft($this->p['c']);
        $this->assertNull(app(VoteBox::class)->running());
    }

    public function test_kick_vote_kicks_and_staff_are_protected()
    {
        Chat::resolve('/kick boss', 'a');
        $this->assertSame(format_colors('{#server}>> {#vote}Player {#highlite}Alice{#vote} fruitlessly tried to kick-vote {#logina}admin {#highlite}Boss{#vote}!'), $this->broadcasts()[0]);
        $this->assertNull(app(VoteBox::class)->running());

        Chat::resolve('/kick c', 'a');
        app(VoteBox::class)->pass();
        $this->assertSame([['c']], $this->sent('Kick'));
    }

    public function test_replay_and_ladder_ask_the_jukebox()
    {
        app(VoteBox::class)->start($this->p['a'], 'replay', 'Replay Track after Finish');
        app(VoteBox::class)->pass();
        $this->assertSame('U1', app(\Funseco\Jukebox\Queue::class)->entries()[0]['uid'], 'queued first');

        app(VoteBox::class)->start($this->p['b'], 'ladder', 'Restart Track for Ladder');
        app(VoteBox::class)->pass();
        $this->assertSame([[]], $this->sent('NextChallenge'), 'skip to the queued copy');
    }

    public function test_without_jukebox_replay_chooses_and_ladder_restarts()
    {
        Event::forget(ReplayRequested::class);
        app(VoteBox::class)->start($this->p['a'], 'replay', 'Replay Track after Finish');
        app(VoteBox::class)->pass();
        app(VoteBox::class)->start($this->p['b'], 'ladder', 'Restart Track for Ladder');
        app(VoteBox::class)->pass();

        $this->assertSame([[$this->map->file_name]], $this->sent('ChooseNextChallenge'));
        $this->assertSame([[]], $this->sent('ChallengeRestart'));
        $this->assertSame([], $this->sent('NextChallenge'));
    }

    public function test_only_replay_votes_count_towards_replays_in_a_row()
    {
        // same track again after a ladder restart or an admin restart: /replay still allowed
        app(VoteBox::class)->mapEnded(restart: true);
        app(VoteBox::class)->mapStarted($this->map);
        app(VoteBox::class)->mapEnded();
        app(VoteBox::class)->mapStarted($this->map);
        $this->assertSame(0, app(VoteBox::class)->replaysInARow());

        app(VoteBox::class)->start($this->p['a'], 'replay', 'Replay Track after Finish');
        app(VoteBox::class)->pass();
        app(VoteBox::class)->mapEnded();
        app(VoteBox::class)->mapStarted($this->map);

        Chat::resolve('/replay', 'b');
        $this->assertSame([format_colors('{#server}> {#vote}No more than {#highlite}1{#vote} replay per track allowed!')], $this->toLogin('b'));
    }

    public function test_restart_keeps_the_running_vote()
    {
        Chat::resolve('/skip', 'a');
        app(VoteBox::class)->mapEnded(restart: true);
        app(VoteBox::class)->mapStarted($this->map);

        $this->assertNotNull(app(VoteBox::class)->running());
    }

    public function test_kick_vote_refused_privately_while_a_vote_runs()
    {
        Chat::resolve('/skip', 'a');
        Chat::resolve('/kick boss', 'b');

        $this->assertSame([format_colors('{#server}> {#error}A vote is already in progress. Wait for this to complete.')], $this->toLogin('b'));
        $this->assertCount(2, $this->broadcasts(), 'the start and the starter\'s vote, no public "fruitlessly tried" line');
    }

    public function test_requested_vote_reports_back_when_passed()
    {
        Event::fake([VotePassed::class]);
        event(new VoteRequested('add', $this->p['a'], ['desc' => 'add Speed', 'tmx' => 123]));
        app(VoteBox::class)->pass();

        Event::assertDispatched(VotePassed::class, fn ($e) => $e->type === 'add' && $e->payload['tmx'] === 123 && $e->requester->login === 'a');
    }

    public function test_admin_pass_and_cancel()
    {
        Chat::resolve('/admin pass', 'boss');
        $this->assertContains(format_colors('{#server}> {#error}There is no vote right now!'), $this->toLogin('boss'));

        Chat::resolve('/skip', 'a');
        Chat::resolve('/admin pass', 'boss');
        $this->assertSame([[]], $this->sent('NextChallenge'));
        $this->assertContains(format_colors('{#server}>> {#admin}God$z$s {#highlite}Boss$z$s{#admin} passes vote!'), $this->broadcasts());

        Chat::resolve('/skip', 'b');
        $before = count($this->broadcasts());
        Chat::resolve('/admin cancel', 'boss');
        $this->assertCount($before + 1, $this->broadcasts(), 'only the admin line, like XAseco');
        $this->assertNull(app(VoteBox::class)->running());
        $this->assertSame([[]], $this->sent('CancelVote'), 'server callvote cancelled too');
    }

    public function test_cancel_only_by_starter()
    {
        Chat::resolve('/skip', 'a');
        Chat::resolve('/cancel', 'b');
        $this->assertSame([format_colors('{#server}> {#error}You didn\'t start the current vote!')], $this->toLogin('b'));

        Chat::resolve('/cancel', 'a');
        $this->assertSame(format_colors('{#server}>> {#vote}Vote to {#highlite}Skip this Track{#vote} cancelled by {#highlite}Alice{#vote}!'), $this->lastBroadcast());
    }

    public function test_vote_panel_yes_button_votes()
    {
        Chat::resolve('/skip', 'a');
        $this->assertStringContainsString('Yes - F5', $this->sent('SendDisplayManialinkPageToLogin')[0][1]);

        \Funseco\Core\Services\ManiaLink\Facades\ManiaLink::dispatchAnswer($this->p['b'], \Funseco\Core\Services\ManiaLink\Facades\ManiaLink::getAction('vote_yes'));

        $this->assertSame(['a', 'b'], app(VoteBox::class)->running()['voters']);
    }

    public function test_required_votes_minimums()
    {
        CurrentPlayers::clear();
        CurrentPlayers::add($this->p['a']);
        $this->assertSame(1, app(VoteBox::class)->required(0.4));
        CurrentPlayers::add($this->p['b']);
        $this->assertSame(2, app(VoteBox::class)->required(0.4), '2-3 players need at least 2');
    }
}

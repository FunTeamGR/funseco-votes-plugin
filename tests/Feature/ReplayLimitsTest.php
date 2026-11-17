<?php
namespace Funseco\Votes\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Funseco\Core\Testing\FakesServer;
use Funseco\Votes\Tests\TestCase;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Callbacks\BillUpdated as BillCallback;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\CurrentSpectators;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Chat\Facades\Chat;
use Funseco\Core\Services\ManiaLink\ManiaLinkService;
use Funseco\Core\Services\Replays\ReplayCounter;
use Funseco\Core\SharedKernel\Events\ExtendRequested;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\ReplayRequested;
use Funseco\Pay2Play\Pay\Pay2Play;
use Funseco\Votes\VoteBox;

/** Voted max (votes.replays_in_a_row) and the paid max on top (pay2play.replay.extra_max). */
class ReplayLimitsTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private Player $alice;
    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        state()->flush();
        state()->forever('funseco:server_united', true);
        state()->forever('funseco:player_united:alice', true);
        Carbon::setTestNow('2026-10-29 12:00:00');
        $this->responses['SendBill'] = 55;
        $this->responses['Pay'] = 77;
        $this->responses['GetNextChallengeInfo'] = ['FileName' => 'Challenges/Other.Challenge.Gbx'];
        $this->responses['GetCurrentGameInfo'] = ['GameMode' => 1, 'TimeAttackLimit' => 300000];
        config([
            'pay2play.replay.enabled' => true, 'pay2play.replay.cost' => 120, 'pay2play.replay.extend' => false,
            'pay2play.skip.enabled' => false,
            'votes.replays_in_a_row' => 2, 'votes.types.replay.per_track' => null, 'pay2play.replay.extra_max' => 2,
        ]);

        CurrentPlayers::clear();
        CurrentSpectators::clear();
        $this->alice = Player::factory()->create(['login' => 'alice', 'nickname' => 'Alice']);
        CurrentPlayers::add($this->alice);
        CurrentPlayers::add(Player::factory()->create(['login' => 'bob', 'nickname' => 'Bob']));
        $this->map = Map::factory()->create(['uid' => 'm1', 'file_name' => 'Challenges/Speed.Challenge.Gbx']);
        CurrentMap::set($this->map);
        ServerInfo::setGameMode(1);
        $this->startMap($this->map);
        Event::forget(ReplayRequested::class);   // no jukebox: the server queues the replay
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function startMap(Map $map): void
    {
        app(VoteBox::class)->mapStarted($map);
        app(Pay2Play::class)->mapStarted(new MapStarted($map, false));
    }

    private function endMap(bool $restart = false): void
    {
        app(VoteBox::class)->mapEnded($restart);
        app(Pay2Play::class)->mapEnded(new MapEnded($this->map, \Mockery::mock(\Funseco\Core\Servers\TmForever\Values\ChallengeInfo::class), new \Funseco\Core\Servers\TmForever\Values\PlayerRankingsCollection(), false, $restart));
    }

    private function counter(): ReplayCounter
    {
        return app(ReplayCounter::class);
    }

    /** A passed /replay vote; $played: the track then runs again (the jukebox allows one queued replay at a time). */
    private function votedReplay(bool $played = true): void
    {
        Chat::resolve('/replay', 'alice');
        app(VoteBox::class)->pass();
        if ($played) {
            $this->endMap();
            $this->startMap($this->map);
        }
    }

    private function click(): void
    {
        $links = app(ManiaLinkService::class);
        $links->dispatchAnswer($this->alice, $links->getAction('pay2play_replay'));
    }

    private function bill(int $id = 55): void
    {
        event(new BillCallback($id, 4, 'Payed', 3));
    }

    private function toAlice(): array
    {
        return array_values(array_map(fn ($a) => $a[0], array_filter($this->sent('ChatSendServerMessageToLogin'), fn ($a) => $a[1] === 'alice')));
    }

    public function test_voted_cap_counts_extends_and_replays_together(): void
    {
        Event::listen(ExtendRequested::class, fn (ExtendRequested $e) => $e->addedSeconds = 300);
        $this->votedReplay(played: false);   // extends
        $this->assertSame(1, $this->counter()->voted());

        Event::forget(ExtendRequested::class);
        $this->votedReplay(played: false);   // nobody extends: a real replay
        $this->assertSame(2, $this->counter()->voted());

        Chat::resolve('/replay', 'bob');
        $this->assertNull(app(VoteBox::class)->running());
        $this->assertSame([format_colors('{#server}> {#pink}No more than {#highlite}2{#pink} replays per track allowed!')],
            array_values(array_map(fn ($a) => $a[0], array_filter($this->sent('ChatSendServerMessageToLogin'), fn ($a) => $a[1] === 'bob'))));
    }

    public function test_zero_voted_max_is_unlimited(): void
    {
        config(['votes.replays_in_a_row' => 0]);
        foreach (range(1, 4) as $i) {
            $this->votedReplay();
        }
        $this->assertSame(4, $this->counter()->voted());
        $this->assertFalse($this->counter()->votedMaxed());
    }

    public function test_paid_cap_is_independent_of_the_voted_cap(): void
    {
        $this->votedReplay();
        $this->votedReplay();
        $this->assertTrue($this->counter()->votedMaxed());

        $this->click();
        $this->assertCount(1, $this->sent('SendBill'), 'voted cap hit, paid still allowed');
        $this->bill();
        $this->assertSame(1, $this->counter()->paid());
        $this->assertFalse($this->counter()->paidMaxed());
    }

    public function test_paid_does_not_consume_voted_and_total_is_the_sum(): void
    {
        $this->responses['SendBill'] = 55;
        $this->click();
        $this->bill();
        $this->assertSame(0, $this->counter()->voted());
        $this->assertSame(1, $this->counter()->paid());

        Chat::resolve('/replay', 'bob');
        $this->assertNotNull(app(VoteBox::class)->running(), 'a vote is still allowed after a paid replay');
    }

    public function test_paid_cap_blocks_the_click_with_a_message(): void
    {
        config(['pay2play.replay.extra_max' => 0]);
        $this->click();
        $this->assertSame([], $this->sent('SendBill'));
        $this->assertStringContainsString('maximum of paid', $this->toAlice()[0]);
    }

    public function test_paid_cap_is_rechecked_after_payment_and_refunded(): void
    {
        config(['pay2play.replay.extra_max' => 1]);
        $this->click();
        $this->assertCount(1, $this->sent('SendBill'));
        $this->counter()->addPaid();   // the cap fills while the bill is open
        $this->bill();

        $this->assertSame([['alice', 120, 'Refund: paid replay limit reached']], $this->sent('Pay'));
        $this->assertSame(1, $this->counter()->paid(), 'refused payment is not counted');
        $this->assertStringContainsString('refunded', $this->toAlice()[0]);
        $this->assertSame([], $this->sent('ChooseNextChallenge'));
    }

    public function test_counters_reset_on_a_real_map_change(): void
    {
        $this->votedReplay();
        $this->counter()->addPaid();
        $this->endMap();
        $this->startMap(Map::factory()->create(['uid' => 'm2', 'file_name' => 'Challenges/Two.Challenge.Gbx']));

        $this->assertSame(0, $this->counter()->voted());
        $this->assertSame(0, $this->counter()->paid());
    }

    public function test_counters_survive_a_replay_and_an_instant_restart(): void
    {
        $this->votedReplay(played: false);
        $this->counter()->addPaid();
        $this->endMap();
        $this->startMap($this->map);   // the queued replay of the same track
        $this->assertSame(1, $this->counter()->voted());
        $this->assertSame(1, $this->counter()->paid());

        $this->endMap(restart: true);
        $this->startMap($this->map);
        $this->assertSame(1, $this->counter()->voted());
        $this->assertSame(1, $this->counter()->paid());

        $this->endMap();
        $this->startMap($this->map);   // same track again but nothing queued it: a fresh count
        $this->assertSame(0, $this->counter()->voted());
        $this->assertSame(0, $this->counter()->paid());
    }

    private function fillCapAndPay(): void
    {
        config(['pay2play.replay.extra_max' => 1]);
        $this->click();
        $this->counter()->addPaid();
        $this->bill();
    }

    public function test_failed_refund_is_logged_and_not_claimed(): void
    {
        $this->app->instance(\Funseco\Core\SharedKernel\Billing\Billing::class, new class(app(\Funseco\Core\SharedKernel\Billing\Billing::class)) implements \Funseco\Core\SharedKernel\Billing\Billing {
            public function __construct(private \Funseco\Core\SharedKernel\Billing\Billing $real)
            {
            }

            public function charge(string $payerLogin, int $coppers, string $label, ?string $toLogin = null): \React\Promise\PromiseInterface
            {
                return $this->real->charge($payerLogin, $coppers, $label, $toLogin);
            }

            public function pay(string $toLogin, int $coppers, string $label): \React\Promise\PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException('boom'));
            }

            public function refund(string $login, int $coppers, string $label): \React\Promise\PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException('boom'));
            }
        });
        \Illuminate\Support\Facades\Log::spy();
        $this->fillCapAndPay();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'alice') && str_contains($m, '120') && str_contains($m, 'BillId 55') && str_contains($m, 'boom'))->once();
        $all = implode('|', $this->toAlice());
        $this->assertStringContainsString('being refunded', $all);
        $this->assertStringNotContainsString('are refunded', $all);
        $this->assertStringContainsString('refund of 120 coppers failed', $all);
    }

    public function test_errored_payout_result_is_logged(): void
    {
        $this->responses['Pay'] = 0;   // no BillId: the payout errors
        \Illuminate\Support\Facades\Log::spy();
        $this->fillCapAndPay();
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once();
    }

    public function test_map_start_without_a_map_end_resets(): void
    {
        $this->votedReplay(played: false);
        $this->counter()->addPaid();
        $this->startMap(Map::factory()->create(['uid' => 'm2', 'file_name' => 'Challenges/Two.Challenge.Gbx']));
        $this->assertSame(0, $this->counter()->voted());
        $this->assertSame(0, $this->counter()->paid());
    }

    public function test_either_plugin_may_hear_the_start_first(): void
    {
        $m2 = Map::factory()->create(['uid' => 'm2', 'file_name' => 'Challenges/Two.Challenge.Gbx']);
        foreach ([[true, false], [false, true]] as [$votesFirst]) {
            $this->counter()->addVoted();
            $this->counter()->addPaid();
            $this->endMap();
            $map = $votesFirst ? $m2 : $this->map;
            $hear = [
                fn () => app(VoteBox::class)->mapStarted($map),
                fn () => app(Pay2Play::class)->mapStarted(new MapStarted($map, false)),
            ];
            $votesFirst ? null : $hear = array_reverse($hear);
            $hear[0]();
            $this->counter()->addPaid();   // bought between the two hearings: must survive
            $hear[1]();
            $this->assertSame(0, $this->counter()->voted());
            $this->assertSame(1, $this->counter()->paid(), 'second hearing is a no-op');
            $this->counter()->addVoted();
        }
    }

    public function test_votes_alone_without_pay2play_listener(): void
    {
        $this->votedReplay(played: false);
        $this->endMap();
        app(VoteBox::class)->mapStarted($this->map);   // pay2play never reports
        $this->assertSame(1, $this->counter()->voted());
        app(VoteBox::class)->mapEnded();
        app(VoteBox::class)->mapStarted(Map::factory()->create(['uid' => 'm3']));
        $this->assertSame(0, $this->counter()->voted());
    }

    public function test_ladder_with_the_jukebox_keeps_the_counters(): void
    {
        Event::listen(ReplayRequested::class, fn (ReplayRequested $e) => $e->queued = true);
        $this->counter()->addVoted();
        $this->counter()->addPaid();
        Chat::resolve('/ladder', 'alice');
        app(VoteBox::class)->pass();
        $this->assertCount(1, $this->sent('NextChallenge'));
        $this->endMap();
        $this->startMap($this->map);
        $this->assertSame(1, $this->counter()->voted());
        $this->assertSame(1, $this->counter()->paid());
    }

    public function test_paid_extend_counts_toward_the_paid_cap(): void
    {
        config(['pay2play.replay.extend' => true, 'pay2play.replay.extra_max' => 1]);
        Event::listen(ExtendRequested::class, fn (ExtendRequested $e) => $e->addedSeconds = 300);
        $this->click();
        $this->bill();
        $this->assertSame(1, $this->counter()->paid());
        $this->assertSame(0, $this->counter()->voted());
        $this->responses['SendBill'] = 56;
        $this->click();
        $this->assertCount(1, $this->sent('SendBill'), 'cap reached by the extend');
        $this->endMap();
        $this->startMap($this->map);
        $this->assertSame(0, $this->counter()->paid(), 'an extend does not keep the count for the next start');
    }

    public function test_replay_refused_by_the_jukebox_is_not_counted(): void
    {
        Event::listen(ReplayRequested::class, fn (ReplayRequested $e) => $e->queued = false);
        Chat::resolve('/replay', 'alice');
        app(VoteBox::class)->pass();
        $this->assertSame(0, $this->counter()->voted());
        $this->assertSame([], $this->sent('ChooseNextChallenge'));
    }
}

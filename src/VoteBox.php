<?php
namespace Funseco\Votes;

use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\CurrentSpectators;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Replays\ReplayCounter;
use Funseco\Core\SharedKernel\Events\ExtendRequested;
use Funseco\Core\SharedKernel\Events\ReplayRequested;
use Funseco\Core\SharedKernel\Events\VoteCancelled;
use Funseco\Core\SharedKernel\Events\VotePassed;
use Funseco\Core\SharedKernel\TimeLimit;

/**
 * The one running chat vote (XAseco plugin.rasp_votes): start, /y, pass, expiry, cancel.
 * Passing runs the vote's action and fires VotePassed so other plugins (jukebox /add) can act.
 */
class VoteBox
{
    /** Game modes counted in seconds; the others in rounds. */
    private const TIMED_MODES = [1, 3, 4];   // Time Attack, Laps, Stunts

    private ?array $vote = null;
    /** @var array<string, int> votes started per type on this track */
    private array $started = [];
    private bool $scoreboard = false;

    public function running(): ?array
    {
        return $this->vote;
    }

    /** Why $requester can't start a $type vote now (told to them), or null when they can. */
    public function refusal(Player $requester, string $type): ?string
    {
        $limit = config("votes.types.$type.per_track");

        return match (true) {
            !config('votes.enabled', true) => Text::get('no_vote'),
            $this->scoreboard && config('votes.rules.blocked_during_scoreboard', true) => Text::get('no_sb_vote'),
            !config('votes.rules.spectators_can_start') && CurrentSpectators::has($requester->login) => Text::get('no_spectators'),
            $this->vote !== null => Text::get('already'),
            $limit !== null && ($this->started[$type] ?? 0) >= $limit => $limit === 0
                ? Text::get('not_allowed', ucfirst($type))
                : Text::get('limit', $limit, '/'.$type, $limit === 1 ? '' : 's'),
            default => null,
        };
    }

    /** True when started (the start message is out). */
    public function start(Player $requester, string $type, string $desc, ?Player $target = null, array $payload = []): bool
    {
        if ($message = $this->refusal($requester, $type)) {
            Text::tell($requester, $message);
            return false;
        }

        $this->started[$type] = ($this->started[$type] ?? 0) + 1;
        $this->vote = [
            'type'     => $type,
            'desc'     => $desc,
            'login'    => $requester->login,
            'nick'     => (string) $requester->nickname,
            'required' => $this->required((float) config("votes.types.$type.ratio", 0.5)),
            'voters'   => [],
            'target'   => $target?->login,
            'extend'   => $type === 'replay' && $this->wouldExtend(),
            'payload'  => $payload + ['target' => $target?->login],
            'started'  => now()->timestamp,
            'rounds'   => 0,
            'reminded' => 0,
        ];

        Text::broadcast(Text::get('start', Text::stripColors($requester->nickname), $desc, $this->vote['required']));
        app(VotePanel::class)->showToAll($this->vote['login']);

        if (config('votes.rules.auto_vote_starter', true)) {
            $this->yes($requester);
        }

        return true;
    }

    public function yes(Player $player): void
    {
        if (!$this->vote) {
            Text::tell($player, Text::get('no_vote_now'));
            return;
        }
        if (!config('votes.rules.spectators_can_vote') && CurrentSpectators::has($player->login) && !$player->allowed('cancel')) {
            Text::tell($player, Text::get('no_spectators'));
            return;
        }
        if (in_array($player->login, $this->vote['voters'], true)) {
            Text::tell($player, Text::get('already_voted'));
            return;
        }

        $this->vote['voters'][] = $player->login;
        $this->vote['required']--;
        app(VotePanel::class)->hide($player);

        if ($this->vote['required'] > 0) {
            $n = $this->vote['required'];
            Text::broadcast(Text::get('y', $n, $n === 1 ? '' : 's', $this->vote['desc']));
            return;
        }
        $this->pass();
    }

    /** Pass now (enough votes, or an admin's /admin pass). */
    public function pass(): void
    {
        $vote = $this->vote;
        if (!$vote) {
            return;
        }
        $this->vote = null;
        app(VotePanel::class)->hideFromAll();
        Text::broadcast(Text::get('pass', $vote['desc']));

        $requester = CurrentPlayers::get($vote['login']) ?? Player::where('login', $vote['login'])->first() ?? new Player(['login' => $vote['login'], 'nickname' => $vote['nick']]);
        match ($vote['type']) {
            'endround' => \Server::query()->ForceEndRound(),
            'ladder'   => $this->ladder($requester),
            'replay'   => $this->replay($requester, $vote['extend']),
            'skip'     => \Server::query()->NextChallenge(),
            'kick'     => \Server::query()->Kick($vote['target']),
            'ignore'   => \Server::query()->Ignore($vote['target']),
            default    => null,   // e.g. 'add': its requester acts on VotePassed
        };
        event(new VotePassed($vote['type'], $requester, $vote['payload']));
    }

    /**
     * End the running vote without passing. $how: 'cancel' (map change, player left, admin) or
     * 'cancelled'/'expired' with $by for the "Vote to X cancelled by Y" line.
     */
    public function cancel(string $how = 'cancel', ?string $by = null, ?Player $player = null): void
    {
        $vote = $this->vote;
        if (!$vote) {
            return;
        }
        $this->vote = null;
        app(VotePanel::class)->hideFromAll();
        match ($how) {
            'quiet'  => null,   // the caller announces it (/admin cancel)
            'cancel' => Text::broadcast(Text::get('cancel')),
            default  => Text::broadcast(Text::get('end', $vote['desc'], $how, (string) $by)),
        };
        event(new VoteCancelled($vote['type'], $vote['payload'], $player));
    }

    /** /cancel: the starter, or anyone with the 'cancel' ability. */
    public function cancelBy(Player $player): void
    {
        if (!$this->vote) {
            Text::tell($player, Text::get('no_vote_progress'));
            return;
        }
        if ($this->vote['login'] !== $player->login && !$player->allowed('cancel')) {
            Text::tell($player, Text::get('not_starter'));
            return;
        }
        $this->cancel('cancelled', Text::stripColors($player->nickname), $player);
    }

    /** Every second: expiry and "N more votes needed" reminders in timed modes. */
    public function tick(): void
    {
        if (!$this->vote || !in_array(ServerInfo::getGameMode(), self::TIMED_MODES, true)) {
            return;
        }
        $elapsed = now()->timestamp - $this->vote['started'];
        $limit = (int) config("votes.types.{$this->vote['type']}.expire_seconds", 90);
        if ($limit > 0 && $elapsed >= $limit) {
            $this->cancel('expired', 'Server');
            return;
        }
        $interval = max(1, (int) config('votes.reminder_interval', 30));
        if (intdiv($elapsed, $interval) > $this->vote['reminded']) {
            $this->vote['reminded'] = intdiv($elapsed, $interval);
            $n = $this->vote['required'];
            Text::broadcast(Text::get('y', $n, $n === 1 ? '' : 's', $this->vote['desc']));
        }
    }

    public function roundEnded(): void
    {
        if (!$this->vote || in_array(ServerInfo::getGameMode(), self::TIMED_MODES, true)) {
            return;
        }
        if (++$this->vote['rounds'] >= (int) config("votes.types.{$this->vote['type']}.expire_rounds", 2)) {
            $this->cancel('expired', 'Server');
        }
    }

    /** XAseco suppresses end of race on an instant restart: the vote and the counters carry on. */
    public function mapEnded(bool $restart = false): void
    {
        app(ReplayCounter::class)->mapEnded($restart);
        if ($restart) {
            return;
        }
        $this->cancel();
        $this->started = [];
        $this->scoreboard = true;
    }

    public function mapStarted(Map $map): void
    {
        $this->scoreboard = false;
        // XAseco replays_counter: only a track replayed by a passed /replay vote (or a paid one) keeps its count.
        app(ReplayCounter::class)->mapStarted((string) $map->uid);
    }

    /** Voted replays and extends on this track (the counter is shared with pay2play). */
    public function replaysInARow(): int
    {
        return app(ReplayCounter::class)->voted();
    }

    /** A kick/ignore target or the vote starter left: the vote can't go on. */
    public function playerLeft(Player $player): void
    {
        if ($this->vote && in_array($player->login, [$this->vote['login'], $this->vote['target']], true)) {
            $this->cancel();
        }
    }

    /** XAseco required_votes(): share of active players, at least 1, at least 2 with 2-3 players. */
    public function required(float $ratio): int
    {
        $active = CurrentPlayers::all()->filter(fn ($p) => config('votes.rules.spectators_can_vote') || !CurrentSpectators::has($p->login))->count();
        $votes = (int) ceil($active * $ratio);
        if ($votes === 0) {
            return 1;
        }

        return ($active >= 2 && $active <= 3 && $votes === 1) ? 2 : $votes;
    }

    /** A passed /replay vote started now would add round time (FlexiTime can still be extended). */
    public function wouldExtend(): bool
    {
        return app(TimeLimit::class)->extendable();
    }

    /** $extend: decided when the vote started; falls back to a plain replay if nobody extends. */
    private function replay(Player $requester, bool $extend = false): void
    {
        if ($extend) {
            event($extension = new ExtendRequested($requester));
            if ($extension->addedSeconds !== null) {
                app(ReplayCounter::class)->addVoted();
                Text::broadcast(Text::get('extended', sprintf('%d:%02d', intdiv($extension->addedSeconds, 60), $extension->addedSeconds % 60)));
                return;
            }
        }

        $request = new ReplayRequested($requester, 'Replay');
        event($request);
        if ($request->queued === false) {
            Text::broadcast(Text::get('already_replayed'));
            return;
        }
        if ($request->queued === null) {
            \Server::query()->ChooseNextChallenge((string) CurrentMap::get()?->file_name);   // no jukebox
        }
        $counter = app(ReplayCounter::class);
        $counter->addVoted();
        $counter->keepForReplay(CurrentMap::get()?->uid);
    }

    /** Restart for ladder: queue the track again (jukebox) and skip to it; restart without the jukebox. */
    private function ladder(Player $requester): void
    {
        $request = new ReplayRequested($requester, 'Ladder');
        event($request);
        if ($request->queued === true) {
            app(ReplayCounter::class)->keepForReplay(CurrentMap::get()?->uid);   // the same track comes again
        }
        $request->queued === null ? \Server::query()->ChallengeRestart() : \Server::query()->NextChallenge();
    }
}

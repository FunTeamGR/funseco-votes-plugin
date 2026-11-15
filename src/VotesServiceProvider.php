<?php
namespace Funseco\Votes;

use Funseco\Core\SharedKernel\PublishesPluginConfig;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Funseco\Core\SharedKernel\Settings\Setting;
use Funseco\Core\SharedKernel\Settings\SettingsRegistry;
use Funseco\Core\Services\Auth\Permissions;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\ManiaLink\ManiaLinkService;
use Funseco\Core\SharedKernel\Events\EverySecondEvent;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\PlayerDisconnected;
use Funseco\Core\SharedKernel\Events\RoundEnded;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Core\SharedKernel\Events\VoteCancelRequested;
use Funseco\Core\SharedKernel\Events\VotePassRequested;
use Funseco\Core\SharedKernel\Events\VoteRequested;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Votes\Commands as C;

class VotesServiceProvider extends ServiceProvider
{
    use PublishesPluginConfig;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/votes.php', 'votes');
        $this->publishPluginConfig(__DIR__.'/..');

        $this->mergeConfigFrom(__DIR__.'/../config/panels.php', 'panels.vote');

        // disable_server_callvotes is applied once at startup; everything else is read when a vote runs.
        $this->app->make(SettingsRegistry::class)->declare('votes', 'votes', [
            Setting::bool('enabled', 'Chat votes enabled'),
            Setting::bool('disable_server_callvotes', 'Switch off server callvotes at startup', restart: true),
            Setting::enum('explain', 'Explain chat voting on join', ['all', 'player', 'off']),
            Setting::bool('rules.spectators_can_start', 'Spectators can start votes'),
            Setting::bool('rules.spectators_can_vote', 'Spectators can vote'),
            Setting::bool('rules.blocked_during_scoreboard', 'Block votes during scoreboard'),
            Setting::bool('rules.protect_staff', 'Staff cannot be kicked or ignored by vote'),
            Setting::bool('rules.auto_vote_starter', 'Starter counts as voted yes'),
            Setting::int('replays_in_a_row', 'Replays in a row', min: 0),
            Setting::int('reminder_interval', 'Vote reminder interval', min: 1, unit: 's'),
            Setting::float('types.endround.ratio', 'Vote endround: share of players needed', min: 0, max: 1),
            Setting::int('types.endround.expire_seconds', 'Vote endround: expires after', min: 0, unit: 's'),
            Setting::int('types.endround.expire_rounds', 'Vote endround: expires after (round modes)', min: 0, unit: 'rounds'),
            Setting::float('types.ladder.ratio', 'Vote ladder: share of players needed', min: 0, max: 1),
            Setting::int('types.ladder.expire_seconds', 'Vote ladder: expires after', min: 0, unit: 's'),
            Setting::int('types.ladder.expire_rounds', 'Vote ladder: expires after (round modes)', min: 0, unit: 'rounds'),
            Setting::float('types.replay.ratio', 'Vote replay: share of players needed', min: 0, max: 1),
            Setting::int('types.replay.expire_seconds', 'Vote replay: expires after', min: 0, unit: 's'),
            Setting::int('types.replay.expire_rounds', 'Vote replay: expires after (round modes)', min: 0, unit: 'rounds'),
            Setting::float('types.skip.ratio', 'Vote skip: share of players needed', min: 0, max: 1),
            Setting::int('types.skip.expire_seconds', 'Vote skip: expires after', min: 0, unit: 's'),
            Setting::int('types.skip.expire_rounds', 'Vote skip: expires after (round modes)', min: 0, unit: 'rounds'),
            Setting::float('types.kick.ratio', 'Vote kick: share of players needed', min: 0, max: 1),
            Setting::int('types.kick.expire_seconds', 'Vote kick: expires after', min: 0, unit: 's'),
            Setting::int('types.kick.expire_rounds', 'Vote kick: expires after (round modes)', min: 0, unit: 'rounds'),
            Setting::float('types.ignore.ratio', 'Vote ignore: share of players needed', min: 0, max: 1),
            Setting::int('types.ignore.expire_seconds', 'Vote ignore: expires after', min: 0, unit: 's'),
            Setting::int('types.ignore.expire_rounds', 'Vote ignore: expires after (round modes)', min: 0, unit: 'rounds'),
            Setting::float('types.add.ratio', 'Vote add: share of players needed', min: 0, max: 1),
            Setting::int('types.add.expire_seconds', 'Vote add: expires after', min: 0, unit: 's'),
            Setting::int('types.add.expire_rounds', 'Vote add: expires after (round modes)', min: 0, unit: 'rounds'),
        ]);

        $this->app->singleton(VoteBox::class);
        $this->app->singleton(VotePanel::class);
    }

    public function boot(): void
    {
        app(ManiaLinkService::class)->addAction('vote_panel');
        app(ManiaLinkService::class)->addActions(['vote_yes', 'vote_no'], VotePanel::class);

        // /votepanel: the player's own template; picking one previews it for two seconds.
        app(\Funseco\Core\Services\Looks\PlayerLooks::class)->registerPanels('vote', VotePanel::templates());
        Event::listen(\Funseco\Core\SharedKernel\Events\PanelChanged::class, function (\Funseco\Core\SharedKernel\Events\PanelChanged $e) {
            if ($e->kind === 'vote' && $e->panel !== '') {
                app(VotePanel::class)->preview($e->player, $e->panel !== app(\Funseco\Core\Services\Looks\PlayerLooks::class)->defaultPanel('vote'));
            }
        });

        foreach (['endround', 'ladder', 'replay', 'skip'] as $type) {
            \Chat::register(new C\StartVoteCommand($type));
        }
        foreach (['kick', 'ignore'] as $type) {
            \Chat::register(new C\TargetVoteCommand($type));
        }
        foreach ([C\YesCommand::class, C\CancelCommand::class, C\HelpVoteCommand::class] as $command) {
            \Chat::register($command);
        }

        $box = fn () => app(VoteBox::class);

        // XAseco: chat votes replace the server's callvotes.
        Event::listen(StartupEvent::class, function () use ($box) {
            if (config('votes.enabled') && config('votes.disable_server_callvotes')) {
                \Server::query()->SetCallVoteRatio(1.0);
                \Server::query()->SetCallVoteRatios([['Command' => '*', 'Ratio' => -1.0]]);
            }
            if ($map = CurrentMap::get()) {
                $box()->mapStarted($map);
            }
        });
        Event::listen(MapStarted::class, fn (MapStarted $e) => $box()->mapStarted($e->map));
        Event::listen(MapEnded::class, fn (MapEnded $e) => $box()->mapEnded($e->restart));
        Event::listen(RoundEnded::class, fn () => $box()->roundEnded());
        Event::listen(EverySecondEvent::class, fn () => $box()->tick());
        Event::listen(PlayerDisconnected::class, fn (PlayerDisconnected $e) => $box()->playerLeft($e->player));

        Event::listen(PlayerConnected::class, function (PlayerConnected $e) {
            match (config('votes.explain')) {
                'all'    => Text::broadcast(Text::get('explain')),
                'player' => Text::tell($e->player, format_colors(str_replace('{#server}>> ', '{#server}> ', config('votes.messages.explain')))),
                default  => null,
            };
        });

        // Other plugins' votes (jukebox /add) and the admin pass/cancel commands.
        Event::listen(VoteRequested::class, fn (VoteRequested $e) => $box()->start($e->requester, $e->type, (string) ($e->payload['desc'] ?? $e->type), null, $e->payload));
        Event::listen(VotePassRequested::class, function (VotePassRequested $e) use ($box) {
            if (!$box()->running()) {
                Text::tell($e->admin, Text::get('admin_no_vote'));
                return;
            }
            Text::broadcast(Text::get('admin_passes', app(Permissions::class)->title($e->admin), $e->admin->nickname));
            $box()->pass();
        });
        Event::listen(VoteCancelRequested::class, fn (VoteCancelRequested $e) => $box()->cancel('quiet', null, $e->admin));
    }
}

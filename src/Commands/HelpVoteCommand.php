<?php
namespace Funseco\Votes\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\UI\Window;

class HelpVoteCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'helpvote';
    protected string $description = 'Displays info about the chat-based votes';
    protected array $aliases = ['votehelp'];

    public function handle(Player $player, array $args): void
    {
        $percent = fn (string $type) => (config("votes.types.$type.ratio") * 100).'%';
        $lines = [['Ratio', '{#black}Command', '']];
        foreach (['endround' => 'Starts a vote to end current round', 'ladder' => 'Starts a vote to restart track for ladder',
                  'replay' => 'Starts a vote to play this track again', 'skip' => 'Starts a vote to skip this track',
                  'ignore' => 'Starts a vote to ignore a player', 'kick' => 'Starts a vote to kick a player'] as $type => $text) {
            if (config("votes.types.$type.per_track") !== 0) {
                $lines[] = [$percent($type), '{#black}/'.$type, $text];
            }
        }
        $lines[] = ['', '{#black}/cancel', 'Cancels your current vote'];
        $lines[] = [];
        $lines[] = ['Players can vote with {#black}/y$g until the required number of votes'];
        $lines[] = ['is reached, or the vote expires.'];

        Window::show($player, '{#vote}Chat-based votes$g are available for these actions:', $lines, [1.0, 0.1, 0.2, 0.7], ['Icons64x64_1', 'TrackInfo', -0.01]);
    }
}

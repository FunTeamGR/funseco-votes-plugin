<?php
namespace Funseco\Votes\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Votes\VoteBox;

class YesCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'y';
    protected string $description = 'Votes Yes for a TMX track or chat-based vote';

    public function handle(Player $player, array $args): void
    {
        app(VoteBox::class)->yes($player);
    }
}

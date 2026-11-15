<?php
namespace Funseco\Votes\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Votes\VoteBox;

class CancelCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'cancel';
    protected string $description = 'Cancels your current vote';

    public function handle(Player $player, array $args): void
    {
        app(VoteBox::class)->cancelBy($player);
    }
}

<?php

namespace App\Command;

use App\Service\EstateguruNotifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class EstateguruCrawlerCommand extends Command
{
    protected static $defaultName = 'lamasfoker:estateguru-crawl';

    private EstateguruNotifier $estateguruNotifier;

    public function __construct(
        EstateguruNotifier $estateguruNotifier,
        string $name = null
    ) {
        $this->estateguruNotifier = $estateguruNotifier;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setDescription('Crawl from estateguru desiderable loans');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->estateguruNotifier->notify();
        return Command::SUCCESS;
    }
}

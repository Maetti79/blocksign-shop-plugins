<?php

declare(strict_types=1);

namespace Plugin\blocksign_esign\Commands;

use BlockSign\ESign\EvidenceArchive;
use BlockSign\ESign\SignatureWorkflow;
use JTL\Plugin\Helper;
use JTL\Plugin\State;
use JTL\Shop;
use Plugin\blocksign_esign\Orders;
use Plugin\blocksign_esign\Settings;
use Plugin\blocksign_esign\Worker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

require_once __DIR__ . '/../src/load.php';

final class Process extends Command
{
    public function __construct()
    {
        parent::__construct('process');
        $this->setDescription('Sends BlockSign invitations and checks pending confirmations.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $plugin = Helper::getPluginById('blocksign_esign');
            $settings = Settings::load(\PFAD_ROOT);
            if (!$plugin || $plugin->getState() !== State::ACTIVATED || !$settings) {
                $output->writeln('<error>Activate and configure BlockSign eSign in JTL-Shop first.</error>');
                return self::FAILURE;
            }
            $client = Worker::client(getenv('BLOCKSIGN_ESIGN_API_KEY') ?: '');
            $worker = new Worker(new Orders(Shop::Container()->getDB()), $settings);
            $worker->run(new SignatureWorkflow($client), new EvidenceArchive($client));
            return self::SUCCESS;
        } catch (Throwable $error) {
            $output->writeln('<error>BlockSign processing is unavailable. Check the plugin configuration.</error>');
            return self::FAILURE;
        }
    }
}

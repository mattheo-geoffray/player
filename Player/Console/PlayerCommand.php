<?php

/*
 * This file is part of the Blackfire Player package.
 *
 * (c) Fabien Potencier <fabien@blackfire.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Blackfire\Player\Console;

use Blackfire\Client as BlackfireClient;
use Blackfire\ClientConfiguration as BlackfireClientConfiguration;
use Blackfire\Player\Player;
use Blackfire\Player\Loader\YamlLoader;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class PlayerCommand extends Command
{
    public function __construct()
    {

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('run')
            ->setDefinition([
                new InputArgument('file', InputArgument::REQUIRED, 'The YAML file defining the scenarios'),
                new InputOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'The number of client to create', 1),
                new InputOption('endpoint', '', InputOption::VALUE_REQUIRED, 'Override the scenario endpoint', null),
                new InputOption('output', 'o', InputOption::VALUE_REQUIRED, 'Saves the extracted values', null),
                new InputOption('blackfire', '', InputOption::VALUE_REQUIRED, 'Enabled Blackfire and use the specified environment', null),
                new InputOption('variables', '', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Override a variable value', null),
            ])
            ->setDescription('Runs a scenario YAML file')
            ->setHelp(<<<EOF
Read https://blackfire.io/docs/player/cli to learn about all supported options.
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!is_file($file = $input->getArgument('file'))) {
            throw new \InvalidArgumentException(sprintf('File "%s" does not exist.'));
        }

        $logger = new ConsoleLogger($output);

        $clients = [$this->createClient()];
        for ($i = 1; $i < $input->getOption('concurrency'); ++$i) {
            $clients[] = $this->createClient();
        }

        $player = new Player($clients);
        $player->setLogger($logger);

        if ($env = $input->getOption('blackfire')) {
            $blackfireConfig = new BlackfireClientConfiguration();
            $blackfireConfig->setEnv($env);
            $blackfire = new BlackfireClient($blackfireConfig);

            $player->addExtension(new \Blackfire\Player\Extension\BlackfireExtension($blackfire, $logger));
        }

        $loader = new YamlLoader();
        $scenarios = $loader->load(file_get_contents($file));

        $variables = array();
        foreach ($input->getOption('variables') as $variable) {
            list($key, $value) = explode('=', $variable, 2);
            $variables[$key] = $value;
        }

        foreach ($scenarios as $scenario) {
            if ($input->getOption('endpoint')) {
                $scenario->endpoint($input->getOption('endpoint'));
            }

            foreach ($variables as $key => $value) {
                $scenario->value($key, $value);
            }
        }

        $results = $player->runMulti($scenarios);

        if ($output = $input->getOption('output')) {
            $values = [];
            foreach ($results as $result) {
                $values[] = $result->getValues()->all();
            }

            file_put_contents($output, json_encode($values, JSON_PRETTY_PRINT));
        }

        // any scenario with an error?
        foreach ($results as $result) {
            if ($result->isErrored()) {
                return 1;
            }
        }
    }

    private function createClient()
    {
        return new GuzzleClient([
            'cookies' => true,
        ]);
    }
}
<?php

namespace Restruct\Silverstripe\WebpImages\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/*
 * Version-specific entry point for PurgeWebpSidecarsTask.
 *
 * BuildTask changed shape between Silverstripe 5 and 6 in ways one class body cannot satisfy:
 *  - SS5: `abstract public function run($request)`, untyped `protected $title` / `$description`.
 *  - SS6: `run(InputInterface, PolyOutput): int` is concrete and `execute()` is the abstract hook;
 *    `protected string $title` and `protected static string $description` are typed.
 *
 * Two task classes with a file-level `return` guard do not work for a BuildTask: the class
 * manifest still lists the guarded class, and TaskRunner reflects every BuildTask descendant,
 * which throws for a class that was never declared and breaks the whole task list. So the task
 * class is declared once and takes its version-specific METHODS from this trait, declared
 * conditionally (the manifest does not descend into `if` blocks; Composer's PSR-4 autoloader
 * finds the trait by file name). Same pattern as restruct/silverstripe-svg-images.
 *
 * PolyOutput exists only on Silverstripe 6, so its presence is the version switch. The `use`
 * imports above are inert on SS5: an import never autoloads.
 */
if (class_exists(PolyOutput::class)) {
    /**
     * Silverstripe 6: sake tasks:webp-images-purge-sidecars, or dev/tasks/webp-images-purge-sidecars
     */
    trait PurgeWebpSidecarsTaskEntryPoint
    {
        /**
         * SS6 declares getDescription() static (PolyCommand::getDescription())
         */
        public static function getDescription(): string
        {
            return _t(
                static::class . '.description',
                'Removes the .jpg.webp / .png.webp files left in public assets by wedevelopnl/silverstripe-webp-images 2.x. Dry run unless --confirm is given.'
            );
        }

        public function getOptions(): array
        {
            return [
                new InputOption('confirm', 'c', InputOption::VALUE_NONE, 'Actually delete the files (without this flag, only lists what would be deleted)'),
                # no task-level --verbose: the console Application defines -v globally, and a second
                # definition makes sake refuse the task ("An option named "verbose" already exists")
            ];
        }

        /**
         * How to ask for a real run, in this major's syntax (used in the dry-run messages)
         */
        protected function confirmHint(): string
        {
            return '--confirm';
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            $this->purgeSidecars(
                (bool) $input->getOption('confirm'),
                $output->isVerbose(),
                function (string $line) use ($output): void {
                    # PolyOutput renders the <info>/<comment> tags for both ANSI and HTML
                    $output->writeln($line);
                }
            );

            return Command::SUCCESS;
        }
    }
} else {
    /**
     * Silverstripe 5: dev/tasks/webp-images-purge-sidecars (browser or sake)
     */
    trait PurgeWebpSidecarsTaskEntryPoint
    {
        /**
         * SS5 declares getDescription() as an instance method
         *
         * @return string
         */
        public function getDescription()
        {
            return 'Removes the .jpg.webp / .png.webp files left in public assets by wedevelopnl/silverstripe-webp-images 2.x. Dry run unless confirm=1 is given.';
        }

        /**
         * How to ask for a real run, in this major's syntax (used in the dry-run messages)
         */
        protected function confirmHint(): string
        {
            return 'confirm=1';
        }

        /**
         * @param \SilverStripe\Control\HTTPRequest $request
         * @return void
         */
        public function run($request)
        {
            $cli = Director::is_cli();

            $this->purgeSidecars(
                $request->getVar('confirm') === '1',
                $request->getVar('verbose') === '1',
                function (string $line) use ($cli): void {
                    # lines carry symfony/console style tags for SS6's PolyOutput; SS5 has no
                    # formatter for them, so strip them (and escape for the browser)
                    $text = strip_tags($line);
                    echo $cli ? $text . PHP_EOL : '<p>' . Convert::raw2xml($text) . "</p>\n";
                }
            );
        }
    }
}

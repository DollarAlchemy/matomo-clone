<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CoreConsole\Commands;

use Piwik\Http;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugin\Manager;
use Piwik\Unzip;

class BuildTracker extends ConsoleCommand
{
    const YUI_COMPRESSOR_URL = 'https://github.com/yui/yuicompressor/releases/download/v2.4.8/yuicompressor-2.4.8.zip';
    const CLOSURE_COMPILER_URL = 'https://repo1.maven.org/maven2/com/google/javascript/closure-compiler/v20230502/closure-compiler-v20230502.jar';

    protected function configure()
    {
        $this->setName('development:build-tracker-js');
        $this->setDescription('Minifies tracker JavaScript for Matomo core or a single plugin.');
        $this->addRequiredValueOption('plugin', null, 'The plugin to minify. If not supplied, minifies core tracker JS.');
    }

    public function isEnabled()
    {
        return \Piwik\Development::isEnabled();
    }

    protected function doExecute(): int
    {
        $plugin = $this->getInput()->getOption('plugin');
        if ($plugin && !Manager::getInstance()->isPluginInFilesystem($plugin)) {
            throw new \InvalidArgumentException("Invalid plugin '$plugin'");
        }

        $this->installYuiCompressorIfNeeded();
        $this->installClosureCompilerIfNeeded();
        $this->compress($plugin);

        return self::SUCCESS;
    }

    private function compress($plugin)
    {
        $output = $this->getOutput();
        $output->writeln("Minifying...");

        $jsPath = PIWIK_INCLUDE_PATH . '/js';
        if (!$plugin) {
            $command = "cd $jsPath && sed '/<DEBUG>/,/<\\/DEBUG>/d' < piwik.js | sed 's/eval/replacedEvilString/' | java -jar yuicompressor-2.4.8.jar --type js --line-break 1000 | sed 's/replacedEvilString/eval/' | sed 's/^[/][*]/\\/*!/' > piwik.min.js && cp piwik.min.js ../piwik.js && cp piwik.min.js ../matomo.js";
            if ($output->isVerbose()) {
                $output->writeln("Command: $command");
            }
            passthru($command);

            $command = "cd $jsPath && sed '/<DEBUG>/,/<\\/DEBUG>/d' < piwik.js | sed 's/eval/replacedEvilString/' | java -jar closure-compiler-v20230502.jar --language_in ECMASCRIPT3 --language_out ECMASCRIPT3 --compilation_level ADVANCED_OPTIMIZATIONS --externs piwik.externs.js | sed 's/replacedEvilString/eval/' | sed 's/^[/][*]/\\/*!/' > piwik.experimental.min.js";
            if ($output->isVerbose()) {
                $output->writeln("Command (experimental): $command");
            }
            passthru($command);

            return;
        }

        $pluginTrackerJs = PIWIK_INCLUDE_PATH . '/plugins/' . $plugin . '/tracker.js';
        $pluginTrackerMinJs = PIWIK_INCLUDE_PATH . '/plugins/' . $plugin . '/tracker.min.js';
        $pluginTrackerExperimentalMinJs = PIWIK_INCLUDE_PATH . '/plugins/' . $plugin . '/tracker.experimental.min.js';

        $command = "cd $jsPath && cat $pluginTrackerJs | java -jar yuicompressor-2.4.8.jar --type js --line-break 1000 | sed 's/^[/][*]/\\/*!/' > $pluginTrackerMinJs";
        if ($output->isVerbose()) {
            $output->writeln("Command: $command");
        }
        passthru($command);

        $command = "cd $jsPath && cat $pluginTrackerJs | java -jar closure-compiler-v20230502.jar --language_in ECMASCRIPT3 --language_out ECMASCRIPT3 --compilation_level SIMPLE_OPTIMIZATIONS | sed 's/^[/][*]/\\/*!/' > $pluginTrackerExperimentalMinJs";
        if ($output->isVerbose()) {
            $output->writeln("Command (experimental): $command");
        }
        passthru($command);

        $output->writeln("Done.");
    }

    private function installYuiCompressorIfNeeded()
    {
        $zipPath = PIWIK_INCLUDE_PATH . '/js/yuicompressor-2.4.8.zip';
        $jarPath = PIWIK_INCLUDE_PATH . '/js/yuicompressor-2.4.8.jar';
        if (is_file($jarPath)) {
            return;
        }

        $this->getOutput()->writeln("Downloading YUI compressor...");
        Http::fetchRemoteFile(self::YUI_COMPRESSOR_URL, $zipPath);

        $unzip = Unzip::factory('PclZip', $zipPath);
        $unzip->extract(PIWIK_INCLUDE_PATH . '/js/');
    }

    private function installClosureCompilerIfNeeded()
    {
        $jarPath = PIWIK_INCLUDE_PATH . '/js/closure-compiler-v20230502.jar';
        if (is_file($jarPath)) {
            return;
        }

        $this->getOutput()->writeln("Downloading Closure Compiler...");
        Http::fetchRemoteFile(self::CLOSURE_COMPILER_URL, $jarPath);
    }
}

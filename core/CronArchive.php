<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik;

use Exception;
use Piwik\ArchiveProcessor\Loader;
use Piwik\ArchiveProcessor\Parameters;
use Piwik\ArchiveProcessor\Rules;
use Piwik\CliMulti\Process;
use Piwik\Container\StaticContainer;
use Piwik\CronArchive\ArchiveFilter;
use Piwik\CronArchive\FixedSiteIds;
use Piwik\CronArchive\Performance\Logger;
use Piwik\Archive\ArchiveInvalidator;
use Piwik\CliMulti\RequestParser;
use Piwik\CronArchive\QueueConsumer;
use Piwik\CronArchive\SharedSiteIds;
use Piwik\DataAccess\ArchiveSelector;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\DataAccess\Model;
use Piwik\DataAccess\RawLogDao;
use Piwik\Metrics\Formatter;
use Piwik\Period\Factory;
use Piwik\Period\Factory as PeriodFactory;
use Piwik\CronArchive\SegmentArchiving;
use Piwik\Period\Range;
use Piwik\Plugins\CoreAdminHome\API as CoreAdminHomeAPI;
use Piwik\Plugins\SitesManager\API as APISitesManager;
use Piwik\Plugins\UsersManager\API as APIUsersManager;
use Piwik\Plugins\UsersManager\UserPreferences;
use Psr\Log\LoggerInterface;

/**
 * ./console core:archive runs as a cron and is a useful tool for general maintenance,
 * and pre-process reports for a Fast dashboard rendering.
 */
class CronArchive
{
    // the url can be set here before the init, and it will be used instead of --url=
    const CRON_INVALIDATION_TIME_OPTION_NAME = 'CronArchive.lastInvalidationTime';

    public static $url = false;

    const TABLES_WITH_INVALIDATED_ARCHIVES = 'CronArchive.getTablesWithInvalidatedArchives';
    const TABLES_WITH_INVALIDATED_ARCHIVES_TTL = 3600;

    // Max parallel requests for a same site's segments
    const MAX_CONCURRENT_API_REQUESTS = 3;

    // force-timeout-for-periods default (1 hour)
    const SECONDS_DELAY_BETWEEN_PERIOD_ARCHIVES = 3600;

    // Flag to know when the archive cron is calling the API
    const APPEND_TO_API_REQUEST = '&trigger=archivephp';

    // Flag used to record timestamp in Option::
    const OPTION_ARCHIVING_FINISHED_TS = "LastCompletedFullArchiving";

    // Name of option used to store starting timestamp
    const OPTION_ARCHIVING_STARTED_TS = "LastFullArchivingStartTime";

    // Show only first N characters from Piwik API output in case of errors
    const TRUNCATE_ERROR_MESSAGE_SUMMARY = 6000;

    // By default, we only process the current week/month/year at most once an hour
    private $todayArchiveTimeToLive;

    private $allWebsites = array();

    /**
     * @var FixedSiteIds|SharedSiteIds
     */
    private $websiteIdArchiveList;
    private $requests = 0;
    private $archiveAndRespectTTL = true;
    public $shouldArchiveAllSites = false;

    private $idSitesNotUsingTracker = [];

    /**
     * @var Model
     */
    private $model;

    private $lastSuccessRunTimestamp = false;
    private $errors = array();

    private $apiToInvalidateArchivedReport;

    const NO_ERROR = "no error";

    public $testmode = false;

    /**
     * The list of IDs for sites for whom archiving should be initiated. If supplied, only these
     * sites will be archived.
     *
     * @var int[]
     */
    public $shouldArchiveSpecifiedSites = array();

    public $shouldSkipSpecifiedSites = [];

    /**
     * If true, xhprof will be initiated for the archiving run. Only for development/testing.
     *
     * @var bool
     */
    public $shouldStartProfiler = false;

    /**
     * Given options will be forwarded to the PHP command if the archiver is executed via CLI.
     * @var string
     */
    public $phpCliConfigurationOptions = '';

    /**
     * If HTTP requests are used to initiate archiving, this controls whether invalid SSL certificates should
     * be accepted or not by each request.
     *
     * @var bool
     */
    public $acceptInvalidSSLCertificate = false;

    /**
     * If set to true, scheduled tasks will not be run.
     *
     * @var bool
     */
    public $disableScheduledTasks = false;

    /**
     * Forces CronArchive to invalidate data for the last [$dateLastForced] years when it notices a segment that
     * was recently created or updated. By default this is 7.
     *
     * @var int|false
     */
    public $dateLastForced = SegmentArchiving::DEFAULT_BEGINNING_OF_TIME_LAST_N_YEARS;

    /**
     * The number of concurrent requests to issue per website. Defaults to {@link MAX_CONCURRENT_API_REQUESTS}.
     *
     * Used when archiving a site's segments concurrently.
     *
     * @var int|false
     */
    public $concurrentRequestsPerWebsite = false;

    /**
     * The number of concurrent archivers to run at once max.
     *
     * @var int|false
     */
    public $maxConcurrentArchivers = false;

    /**
     * If enabled, segments will be only archived for yesterday, but not today. If the segment was created recently,
     * then it will still be archived for today and the setting will be ignored for this segment.
     * @var bool
     */
    public $skipSegmentsToday = false;

    private $archivingStartingTime;

    private $formatter;

    private $lastDbReset = false;

    /**
     * @var SegmentArchiving
     */
    private $segmentArchiving;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Only used when archiving using HTTP requests.
     *
     * @var string
     */
    private $urlToPiwik = null;

    /**
     * @var ArchiveInvalidator
     */
    private $invalidator;

    /**
     * @var bool
     */
    private $isArchiveProfilingEnabled = false;

    /**
     * @var array
     */
    private $periodIdsToLabels;

    private $processNewSegmentsFrom;

    /**
     * @var ArchiveFilter
     */
    private $archiveFilter;

    /**
     * @var RequestParser
     */
    private $cliMultiRequestParser;

    /**
     * Constructor.
     *
     * @param string|null $processNewSegmentsFrom When to archive new segments from. See [General] process_new_segments_from
     *                                            for possible values.
     * @param LoggerInterface|null $logger
     */
    public function __construct($processNewSegmentsFrom = null, LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: StaticContainer::get('Psr\Log\LoggerInterface');
        $this->formatter = new Formatter();

        $this->processNewSegmentsFrom = $processNewSegmentsFrom ?: StaticContainer::get('ini.General.process_new_segments_from');

        $this->invalidator = StaticContainer::get('Piwik\Archive\ArchiveInvalidator');

        $this->isArchiveProfilingEnabled = Config::getInstance()->Debug['archiving_profile'] == 1;

        $this->model = StaticContainer::get(Model::class);

        $this->periodIdsToLabels = array_flip(Piwik::$idPeriods);

        $this->rawLogDao = new RawLogDao();

        $this->cliMultiRequestParser = new RequestParser($this->makeCliMulti()->supportsAsync());
    }

    private function isMaintenanceModeEnabled()
    {
        return Config::getInstance()->General['maintenance_mode'] == 1;
    }

    /**
     * Initializes and runs the cron archiver.
     */
    public function main()
    {
        if ($this->isMaintenanceModeEnabled()) {
            $this->logger->info("Archiving won't run because maintenance mode is enabled");
            return;
        }

        $self = $this;
        Access::doAsSuperUser(function () use ($self) {
            $self->init();
            $self->run();
            $self->runScheduledTasks();
            $self->end();
        });
    }

    public function init()
    {
        $this->segmentArchiving = new SegmentArchiving($this->processNewSegmentsFrom, $this->dateLastForced);

        /**
         * This event is triggered during initializing archiving.
         *
         * @param CronArchive $this
         */
        Piwik::postEvent('CronArchive.init.start', array($this));

        SettingsServer::setMaxExecutionTime(0);

        $this->archivingStartingTime = time();

        // Note: the order of methods call matters here.
        $this->initStateFromParameters();

        $this->logInitInfo();
        $this->logArchiveTimeoutInfo();

        $idSitesNotUsingTracker = Loader::getSitesNotUsingTracker();
        if (!empty($idSitesNotUsingTracker)) {
            $this->logger->info("- The following websites do not use the tracker: " . implode(',', $this->idSitesNotUsingTracker));
        }

        // record archiving start time
        Option::set(self::OPTION_ARCHIVING_STARTED_TS, time());

        $allWebsites = APISitesManager::getInstance()->getAllSitesId();
        $websitesIds = $this->initWebsiteIds($allWebsites);
        $this->filterWebsiteIds($websitesIds, $allWebsites);
        $this->allWebsites = $websitesIds;
        $this->websiteIdArchiveList = $this->makeWebsiteIdArchiveList($websitesIds);

        if ($this->archiveFilter) {
            $this->archiveFilter->logFilterInfo($this->logger);
        }

        if ($this->skipSegmentsToday) {
            $this->logger->info('Will skip segments archiving for today unless they were created recently');
        }

        /**
         * This event is triggered after a CronArchive instance is initialized.
         *
         * @param array $websiteIds The list of website IDs this CronArchive instance is processing.
         *                          This will be the entire list of IDs regardless of whether some have
         *                          already been processed.
         */
        Piwik::postEvent('CronArchive.init.finish', [$this->allWebsites]);
    }

    /**
     * Main function, runs archiving on all websites with new activity
     */
    public function run()
    {
        $pid = Common::getProcessId();

        $timer = new Timer;

        $this->logSection("START");
        $this->logger->info("Starting Matomo reports archiving...");

        $numArchivesFinished = 0;

        if ($this->hasReachedMaxConcurrentArchivers()) {
            $this->logger->info("Reached maximum concurrent archivers allowed ({$this->maxConcurrentArchivers}), aborting run.");
            return;
        }

        $countOfProcesses = $this->getMaxConcurrentApiRequests();

        $queueConsumer = new QueueConsumer($this->logger, $this->websiteIdArchiveList, $countOfProcesses, $pid,
            $this->model, $this->segmentArchiving, $this, $this->cliMultiRequestParser, $this->archiveFilter);

        while (true) {
            if ($this->isMaintenanceModeEnabled()) {
                $this->logger->info("Archiving will stop now because maintenance mode is enabled");
                return;
            }

            if (!Process::isMethodDisabled('getmypid') && !Process::isMethodDisabled('ignore_user_abort')) {
                // see https://github.com/matomo-org/wp-matomo/issues/163
                flush();
            }

            $archivesToProcess = $queueConsumer->getNextArchivesToProcess();
            if ($archivesToProcess === null) {
                break;
            }

            if (empty($archivesToProcess)) {
                continue;
            }

            $successCount = $this->launchArchivingFor($archivesToProcess);
            $numArchivesFinished += $successCount;
        }

        $this->disconnectDb();

        $this->logger->info("Done archiving!");

        $this->logSection("SUMMARY");
        $this->logger->info("Processed $numArchivesFinished archives.");
        $this->logger->info("Total API requests: {$this->requests}");

        $this->logger->info("done: " .
            $this->requests . " req, " . round($timer->getTimeMs()) . " ms, " .
            (empty($this->errors)
                ? self::NO_ERROR
                : (count($this->errors) . " errors."))
        );

        $this->logger->info($timer->__toString());
    }

    private function launchArchivingFor($archives)
    {
        $urls = [];
        $archivesBeingQueried = [];
        foreach ($archives as $index => $archive) {
            list($url, $segment, $plugin) = $this->generateUrlToArchiveFromArchiveInfo($archive);
            if (empty($url)) {
                // can happen if, for example, a segment was deleted after an archive was invalidated
                // in this case, we can just delete the archive entirely.
                $this->deleteInvalidatedArchives($archive);
                continue;
            }

            $idSite = $archive['idsite'];
            $dateStr = $archive['period'] == Range::PERIOD_ID ? ($archive['date1'] . ',' . $archive['date2']) : $archive['date1'];
            $period = PeriodFactory::build($this->periodIdsToLabels[$archive['period']], $dateStr);
            $params = new Parameters(new Site($idSite), $period, new Segment($segment, [$idSite], $period->getDateStart(), $period->getDateEnd()));

            if (!empty($plugin)) {
                $params->setRequestedPlugin($plugin);
                $params->onlyArchiveRequestedPlugin();
            }

            $loader = new Loader($params);
            if ($loader->canSkipThisArchive()) {
                $this->logger->info("Found no visits for site ID = {idSite}, {period} ({date1},{date2}), site is using the tracker so skipping archiving...", [
                    'idSite' => $idSite,
                    'period' => $this->periodIdsToLabels[$archive['period']],
                    'date1' => $archive['date1'],
                    'date2' => $archive['date2'],
                ]);

                // site is using the tracker, but there are no visits for this period, so just delete the archive and move on
                $this->deleteInvalidatedArchives($archive);
                continue;
            }

            $this->logger->debug("Starting archiving for {url}", ['url' => $url]);

            $urls[] = $url;
            $archivesBeingQueried[] = $archive;
        }

        if (empty($urls)) {
            return 0; // all URLs had no visits and were using the tracker
        }

        $cliMulti = $this->makeCliMulti();
        $cliMulti->timeRequests();

        $responses = $cliMulti->request($urls);
        
        $this->disconnectDb();
        
        $timers = $cliMulti->getTimers();
        $successCount = 0;

        foreach ($urls as $index => $url) {
            $content = array_key_exists($index, $responses) ? $responses[$index] : null;
            $this->checkResponse($content, $url);

            $stats = json_decode($content, $assoc = true);
            if (!is_array($stats)) {
                $this->logger->info(var_export($content, true));

                $this->logError("Error unserializing the following response from $url: '" . $content . "'");
                continue;
            }

            $visitsForPeriod = $this->getVisitsFromApiResponse($stats);

            $this->logArchiveJobFinished($url, $timers[$index], $visitsForPeriod, $archivesBeingQueried[$index]['plugin'], $archivesBeingQueried[$index]['report']);

            // TODO: do in ArchiveWriter
            $this->deleteInvalidatedArchives($archivesBeingQueried[$index]);

            ++$successCount;
        }

        $this->requests += count($urls);

        return $successCount;
    }

    private function deleteInvalidatedArchives($archive)
    {
        $idArchives = $this->model->getInvalidatedArchiveIdsAsOldOrOlderThan($archive);
        if (!empty($idArchives)) {
            $date = Date::factory($archive['date1']);
            $this->model->deleteArchiveIds(ArchiveTableCreator::getNumericTable($date), ArchiveTableCreator::getBlobTable($date), $idArchives);
        }

        $this->model->deleteInvalidations([$archive]);
    }

    private function generateUrlToArchiveFromArchiveInfo($archive)
    {
        $plugin = $archive['plugin'];
        $report = $archive['report'];
        $period = $this->periodIdsToLabels[$archive['period']];

        if ($period == 'range') {
            $date = $archive['date1'] . ',' . $archive['date2'];
        } else {
            $date = $archive['date1'];
        }

        $idSite = $archive['idsite'];

        $segment = isset($archive['segment']) ? $archive['segment'] : '';

        $url = $this->getVisitsRequestUrl($idSite, $period, $date, $segment, $plugin);
        $url = $this->makeRequestUrl($url);

        if (!empty($segment)) {
            $shouldSkipToday = !$this->wasSegmentChangedRecently($segment,
                $this->segmentArchiving->getAllSegments());

            if ($shouldSkipToday) {
                $url .= '&skipArchiveSegmentToday=1';
            }
        }

        if (!empty($plugin)) {
            $url .= "&pluginOnly=1";
        }

        if (!empty($report)) {
            $url .= "&requestedReport=" . urlencode($report);
        }

        return [$url, $segment, $plugin];
    }

    private function logArchiveJobFinished($url, $timer, $visits, $plugin = null, $report = null)
    {
        $params = UrlHelper::getArrayFromQueryString($url);
        $visits = (int) $visits;

        $this->logger->info("Archived website id {$params['idSite']}, period = {$params['period']}, date = "
            . "{$params['date']}, segment = '" . (isset($params['segment']) ? $params['segment'] : '') . "', "
            . ($plugin ? "plugin = $plugin, " : "") . ($report ? "report = $report, " : "") . "$visits visits found. $timer");
    }

    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * End of the script
     */
    public function end()
    {
        /**
         * This event is triggered after archiving.
         *
         * @param CronArchive $this
         */
        Piwik::postEvent('CronArchive.end', array($this));

        if (empty($this->errors)) {
            // No error -> Logs the successful script execution until completion
            Option::set(self::OPTION_ARCHIVING_FINISHED_TS, time());
            return;
        }

        $this->logSection("SUMMARY OF ERRORS");
        foreach ($this->errors as $error) {
            // do not logError since errors are already in stderr
            $this->logger->info("Error: " . $error);
        }

        $summary = count($this->errors) . " total errors during this script execution, please investigate and try and fix these errors.";
        $this->logFatalError($summary);
    }

    public function logFatalError($m)
    {
        $this->logError($m);

        throw new Exception($m);
    }

    public function runScheduledTasks()
    {
        $this->logSection("SCHEDULED TASKS");

        if ($this->disableScheduledTasks) {
            $this->logger->info("Scheduled tasks are disabled with --disable-scheduled-tasks");
            return;
        }

        $this->disconnectDb();

        // TODO: this is a HACK to get the purgeOutdatedArchives task to work when run below. without
        //       it, the task will not run because we no longer run the tasks through CliMulti.
        //       harder to implement alternatives include:
        //       - moving CronArchive logic to DI and setting a flag in the class when the whole process
        //         runs
        //       - setting a new DI environment for core:archive which CoreAdminHome can use to conditionally
        //         enable/disable the task
        $_GET['trigger'] = 'archivephp';
        CoreAdminHomeAPI::getInstance()->runScheduledTasks();

        $this->logSection("");
    }

    private function disconnectDb()
    {
        $twoHoursInSeconds = 60 * 60 * 2;

        if (time() > ($this->lastDbReset + $twoHoursInSeconds)) {
            // we aim to through DB connections away only after 2 hours
            $this->lastDbReset = time();
            Db::destroyDatabaseObject();
            Tracker::disconnectCachedDbConnection();
        }
    }

    /**
     * Returns base URL to process reports for the $idSite on a given $period
     *
     * @param string $idSite
     * @param string $period
     * @param string $date
     * @param bool|false $segment
     * @return string
     */
    private function getVisitsRequestUrl($idSite, $period, $date, $segment = false, $plugin = null)
    {
        $request = "?module=API&method=CoreAdminHome.archiveReports&idSite=$idSite&period=$period&date=" . $date . "&format=json";
        if ($segment) {
            $request .= '&segment=' . urlencode($segment);
        }
        if (!empty($plugin)) {
            $request .= "&plugin=" . $plugin;
        }
        return $request;
    }

    /**
     * Logs a section in the output
     *
     * @param string $title
     */
    private function logSection($title = "")
    {
        $this->logger->info("---------------------------");
        if (!empty($title)) {
            $this->logger->info($title);
        }
    }

    public function logError($m)
    {
        if (!defined('PIWIK_ARCHIVE_NO_TRUNCATE')) {
            $m = str_replace(array("\n", "\t"), " ", $m);
            if (Common::mb_strlen($m) > self::TRUNCATE_ERROR_MESSAGE_SUMMARY) {
                $numCharactersKeepFromEnd = 100;
                $m = Common::mb_substr($m, 0, self::TRUNCATE_ERROR_MESSAGE_SUMMARY - $numCharactersKeepFromEnd)
                     . ' ... ' .
                    Common::mb_substr($m, -1 * $numCharactersKeepFromEnd);
            }
        }
        $this->errors[] = $m;
        $this->logger->error($m);
    }

    private function logNetworkError($url, $response)
    {
        $message = "Got invalid response from API request: $url. ";
        if (empty($response)) {
            $message .= "The response was empty. This usually means a server error. A solution to this error is generally to increase the value of 'memory_limit' in your php.ini file. ";

            if($this->makeCliMulti()->supportsAsync()) {
                $message .= " For more information and the error message please check in your PHP CLI error log file. As this core:archive command triggers PHP processes over the CLI, you can find where PHP CLI logs are stored by running this command: php -i | grep error_log";
            } else {
                $message .= " For more information and the error message please check your web server's error Log file. As this core:archive command triggers PHP processes over HTTP, you can find the error message in your Matomo's web server error logs. ";
            }
        } else {
            $message .= "Response was '$response'";
        }

        $this->logError($message);
        return false;
    }

    private function checkResponse($response, $url)
    {
        if (empty($response)
            || stripos($response, 'error') !== false
        ) {
            return $this->logNetworkError($url, $response);
        }
        return true;
    }

    /**
     * Initializes the various parameters to the script, based on input parameters.
     *
     */
    private function initStateFromParameters()
    {
        $this->todayArchiveTimeToLive = Rules::getTodayArchiveTimeToLive();
        $this->lastSuccessRunTimestamp = $this->getLastSuccessRunTimestamp();
    }

    public function filterWebsiteIds(&$websiteIds, $allWebsites)
    {
        // Keep only the websites that do exist
        $websiteIds = array_intersect($websiteIds, $allWebsites);

        if (!empty($this->shouldSkipSpecifiedSites)) {
            $websiteIds = array_intersect($websiteIds, $this->shouldSkipSpecifiedSites);
        }

        /**
         * Triggered by the **core:archive** console command so plugins can modify the priority of
         * websites that the archiving process will be launched for.
         *
         * Plugins can use this hook to add websites to archive, remove websites to archive, or change
         * the order in which websites will be archived.
         *
         * @param array $websiteIds The list of website IDs to launch the archiving process for.
         */
        Piwik::postEvent('CronArchive.filterWebsiteIds', array(&$websiteIds));
    }

    /**
     * @internal
     * @param $api
     */
    public function setApiToInvalidateArchivedReport($api)
    {
        $this->apiToInvalidateArchivedReport = $api;
    }

    private function getApiToInvalidateArchivedReport()
    {
        if ($this->apiToInvalidateArchivedReport) {
            return $this->apiToInvalidateArchivedReport;
        }

        return CoreAdminHomeAPI::getInstance();
    }

    public function invalidateArchivedReportsForSitesThatNeedToBeArchivedAgain($idSiteToInvalidate)
    {
        if ($this->model->isInvalidationsScheduledForSite($idSiteToInvalidate)) {
            $this->logger->debug("Invalidations currently exist for idSite $idSiteToInvalidate, skipping invalidating for now...");
            return;
        }

        $this->logger->info("Checking for queued invalidations...");

        // invalidate remembered site/day pairs
        $sitesPerDays = $this->invalidator->getRememberedArchivedReportsThatShouldBeInvalidated();
        krsort($sitesPerDays); // for tests

        foreach ($sitesPerDays as $date => $siteIds) {
            //Concurrent transaction logic will end up with duplicates set.  Adding array_unique to the siteIds.
            $siteIds = array_unique($siteIds);

            $period = Factory::build('day', $date);

            $siteIdsToInvalidate = [];
            foreach ($siteIds as $idSite) {
                if ($idSite != $idSiteToInvalidate) {
                    continue;
                }

                $params = new Parameters(new Site($idSite), $period, new Segment('', [$idSite], $period->getDateStart(), $period->getDateEnd()));
                if ($this->isThereExistingValidPeriod($params)) {
                    $this->logger->info('  Found usable archive for date range {date} for site {idSite}, skipping invalidation for now.', ['date' => $date, 'idSite' => $idSite]);
                    continue;
                }

                $siteIdsToInvalidate[] = $idSite;
            }

            $listSiteIds = implode(',', $siteIdsToInvalidate);

            try {
                $this->logger->info('  Will invalidate archived reports for ' . $date . ' for following websites ids: ' . $listSiteIds);
                $this->getApiToInvalidateArchivedReport()->invalidateArchivedReports($siteIdsToInvalidate, $date);
            } catch (Exception $e) {
                $message = $e->getMessage();
                if (\Piwik_ShouldPrintBackTraceWithMessage()) {
                    $message .= "\n" . $e->getTraceAsString();
                }
                $this->logger->info('  Failed to invalidate archived reports: ' . $message);
            }
        }

        // invalidate today if needed for all websites
        $this->invalidateRecentDate('today', $idSiteToInvalidate);

        // invalidate yesterday archive if the time of the latest valid archive is earlier than today
        // (means the day has changed and there might be more visits that weren't processed)
        $this->invalidateRecentDate('yesterday', $idSiteToInvalidate);

        // invalidate range archives
        $dates = $this->getCustomDateRangeToPreProcess($idSiteToInvalidate);

        foreach ($dates as $date) {
            try {
                $period = PeriodFactory::build('range', $date);
            } catch (\Exception $ex) {
                $this->logger->debug("  Found invalid range date in [General] archiving_custom_ranges: {date}", ['date' => $date]);
                continue;
            }

            $params = new Parameters(new Site($idSiteToInvalidate), $period, new Segment('', [$idSiteToInvalidate], $period->getDateStart(), $period->getDateEnd()));
            if ($this->isThereExistingValidPeriod($params)) {
                $this->logger->info('  Found usable archive for custom date range {date} for site {idSite}, skipping archiving.', ['date' => $date, 'idSite' => $idSiteToInvalidate]);
                continue;
            }

            $this->logger->info('  Invalidating custom date range ({date}) for site {idSite}', ['idSite' => $idSiteToInvalidate, 'date' => $date]);

            $this->getApiToInvalidateArchivedReport()->invalidateArchivedReports($idSiteToInvalidate, [$date], 'range', $segment = null, $cascadeDown = false, $_forceInvalidateNonexistant = true);
        }

        // for new segments, invalidate past dates
        $segmentDatesToInvalidate = $this->segmentArchiving->getSegmentArchivesToInvalidateForNewSegments($idSiteToInvalidate);

        foreach ($segmentDatesToInvalidate as $info) {
            $this->logger->info('  Segment "{segment}" was created or changed recently and will therefore archive today (for site ID = {idSite})', [
                'segment' => $info['segment'],
                'idSite' => $idSiteToInvalidate,
            ]);

            $earliestDate = $info['date'];

            $allDates = PeriodFactory::build('range', $earliestDate . ',today')->getSubperiods();
            $allDates = array_map(function (Period $p) {
                return $p->getDateStart()->toString();
            }, $allDates);
            $allDates = implode(',', $allDates);

            $this->getApiToInvalidateArchivedReport()->invalidateArchivedReports($idSiteToInvalidate, $allDates, $period = false, $info['segment']);
        }

        $this->setInvalidationTime();

        $this->logger->info("Done invalidating");
    }

    private function invalidateRecentDate($dateStr, $idSite)
    {
        $isYesterday = $dateStr == 'yesterday';

        $date = Date::factory($dateStr);
        $period = PeriodFactory::build('day', $date);

        $params = new Parameters(new Site($idSite), $period, new Segment('', [$idSite], $period->getDateStart(), $period->getDateEnd()));
        if ($this->isThereExistingValidPeriod($params, $isYesterday)) {
            $this->logger->debug("  Found existing valid archive for $dateStr, skipping invalidation...");
            return;
        }

        $loader = new Loader($params);
        if ($loader->canSkipThisArchive()) {
            $this->logger->debug("  " . ucfirst($dateStr) . " archive can be skipped due to no visits for idSite = $idSite, skipping invalidation...");
            return;
        }

        $this->logger->info("  Will invalidate archived reports for $dateStr in site ID = {idSite}'s timezone ({date}).", [
            'idSite' => $idSite,
            'date' => $date->getDatetime(),
        ]);

        $this->getApiToInvalidateArchivedReport()->invalidateArchivedReports($idSite, $date->toString(), 'day');
    }

    public function isThereExistingValidPeriod(Parameters $params, $isYesterday = false)
    {
        $today = Date::factoryInTimezone('today', Site::getTimezoneFor($params->getSite()->getId()));

        $isPeriodIncludesToday = $params->getPeriod()->isDateInPeriod($today);
        $minArchiveProcessedTime = $isPeriodIncludesToday ? Date::now()->subSeconds(Rules::getPeriodArchiveTimeToLiveDefault($params->getPeriod()->getLabel())) : null;

        // empty plugins param since we only check for an 'all' archive
        list($idArchive, $visits, $visitsConverted, $ignore, $tsArchived) = ArchiveSelector::getArchiveIdAndVisits($params, $minArchiveProcessedTime, $includeInvalidated = $isPeriodIncludesToday);

        // day has changed since the archive was created, we need to reprocess it
        if ($isYesterday
            && !empty($idArchive)
            && Date::factory($tsArchived)->toString() != $today->toString()
        ) {
            return false;
        }

        return !empty($idArchive);
    }

    private function setInvalidationTime()
    {
        $cache = Cache::getTransientCache();

        Option::set(self::CRON_INVALIDATION_TIME_OPTION_NAME, time());

        $cacheKey = 'CronArchive.getLastInvalidationTime';

        $cache->delete($cacheKey);
    }

    public static function getLastInvalidationTime()
    {
        $cache = Cache::getTransientCache();

        $cacheKey = 'CronArchive.getLastInvalidationTime';
        $result = $cache->fetch($cacheKey);
        if ($result !== false) {
            return $result;
        }

        Option::clearCachedOption(self::CRON_INVALIDATION_TIME_OPTION_NAME);
        $result = Option::get(self::CRON_INVALIDATION_TIME_OPTION_NAME);

        if (empty($result)) {
            Option::clearCachedOption(self::OPTION_ARCHIVING_FINISHED_TS);
            $result = Option::get(self::OPTION_ARCHIVING_FINISHED_TS);
        }

        $cache->save($cacheKey, $result, self::TABLES_WITH_INVALIDATED_ARCHIVES_TTL);

        return $result;
    }

    /**
     *  Returns the list of sites to loop over and archive.
     *  @return array
     */
    private function initWebsiteIds($allWebsites)
    {
        if (count($this->shouldArchiveSpecifiedSites) > 0) {
            $this->logger->info("- Will process " . count($this->shouldArchiveSpecifiedSites) . " websites (--force-idsites)");

            return $this->shouldArchiveSpecifiedSites;
        }

        return $allWebsites;
    }

    private function logInitInfo()
    {
        $this->logSection("INIT");
        $this->logger->info("Running Matomo " . Version::VERSION . " as Super User");
    }

    private function logArchiveTimeoutInfo()
    {
        $this->logSection("NOTES");

        // Recommend to disable browser archiving when using this script
        if (Rules::isBrowserTriggerEnabled()) {
            $this->logger->info("- If you execute this script at least once per hour (or more often) in a crontab, you may disable 'Browser trigger archiving' in Matomo UI > Settings > General Settings.");
            $this->logger->info("  See the doc at: https://matomo.org/docs/setup-auto-archiving/");
        }

        $cliMulti = new CliMulti($this->logger);
        $supportsAsync = $cliMulti->supportsAsync();
        $this->logger->info("- " . ($supportsAsync ? 'Async process archiving supported, using CliMulti.' : 'Async process archiving not supported, using curl requests.'));

        $this->logger->info("- Reports for today will be processed at most every " . $this->todayArchiveTimeToLive
            . " seconds. You can change this value in Matomo UI > Settings > General Settings.");

        foreach (array('week', 'month', 'year', 'range') as $period) {
            $ttl = Rules::getPeriodArchiveTimeToLiveDefault($period);

            if (!empty($ttl) && $ttl !== $this->todayArchiveTimeToLive) {
                $this->logger->info("- Reports for the current $period will be processed at most every " . $ttl
                    . " seconds. You can change this value in config/config.ini.php by editing 'time_before_" . $period . "_archive_considered_outdated' in the '[General]' section.");
            }
        }

        // Try and not request older data we know is already archived
        if ($this->lastSuccessRunTimestamp !== false) {
            $dateLast = time() - $this->lastSuccessRunTimestamp;
            $this->logger->info("- Archiving was last executed without error "
                . $this->formatter->getPrettyTimeFromSeconds($dateLast, true) . " ago");
        }
    }

    private function getVisitsFromApiResponse($stats)
    {
        if (empty($stats['nb_visits'])) {
            return 0;
        }

        return (int) $stats['nb_visits'];
    }

    /**
     * @return int
     */
    private function getMaxConcurrentApiRequests()
    {
        if (false !== $this->concurrentRequestsPerWebsite) {
            return $this->concurrentRequestsPerWebsite;
        }

        return self::MAX_CONCURRENT_API_REQUESTS;
    }

    /**
     * @return false|string
     */
    private function getLastSuccessRunTimestamp()
    {
        $timestamp = Option::get(self::OPTION_ARCHIVING_FINISHED_TS);
        return $this->sanitiseTimestamp($timestamp);
    }

    private function sanitiseTimestamp($timestamp)
    {
        $now = time();
        return ($timestamp < $now) ? $timestamp : $now;
    }

    /**
     * @param $idSite
     * @return array of date strings
     */
    private function getCustomDateRangeToPreProcess($idSite)
    {
        static $cache = null;
        if (is_null($cache)) {
            $cache = $this->loadCustomDateRangeToPreProcess();
        }

        if (empty($cache[$idSite])) {
            $cache[$idSite] = array();
        }

        $customRanges = array_filter(Config::getInstance()->General['archiving_custom_ranges']);

        if (!empty($customRanges)) {
            $cache[$idSite] = array_merge($cache[$idSite], $customRanges);
        }

        $dates = array_unique($cache[$idSite]);
        return $dates;
    }

    /**
     * @return array
     */
    private function loadCustomDateRangeToPreProcess()
    {
        $customDateRangesToProcessForSites = array();

        // For all users who have selected this website to load by default,
        // we load the default period/date that will be loaded for this user
        // and make sure it's pre-archived
        $allUsersPreferences = APIUsersManager::getInstance()->getAllUsersPreferences(array(
            APIUsersManager::PREFERENCE_DEFAULT_REPORT_DATE,
            APIUsersManager::PREFERENCE_DEFAULT_REPORT
        ));

        foreach ($allUsersPreferences as $userLogin => $userPreferences) {
            if (!isset($userPreferences[APIUsersManager::PREFERENCE_DEFAULT_REPORT_DATE])) {
                continue;
            }

            $defaultDate = $userPreferences[APIUsersManager::PREFERENCE_DEFAULT_REPORT_DATE];
            $preference = new UserPreferences();
            $period = $preference->getDefaultPeriod($defaultDate);
            if ($period != 'range') {
                continue;
            }

            if (isset($userPreferences[APIUsersManager::PREFERENCE_DEFAULT_REPORT])
                && is_numeric($userPreferences[APIUsersManager::PREFERENCE_DEFAULT_REPORT])) {
                // If user selected one particular website ID
                $idSites = array($userPreferences[APIUsersManager::PREFERENCE_DEFAULT_REPORT]);
            } else {
                // If user selected "All websites" or some other random value, we pre-process all websites that they have access to
                $idSites = APISitesManager::getInstance()->getSitesIdWithAtLeastViewAccess($userLogin);
            }

            foreach ($idSites as $idSite) {
                $customDateRangesToProcessForSites[$idSite][] = $defaultDate;
            }
        }

        return $customDateRangesToProcessForSites;
    }

    /**
     * @param $url
     * @return string
     */
    private function makeRequestUrl($url)
    {
        $url = $url . self::APPEND_TO_API_REQUEST;

        if ($this->shouldStartProfiler) {
            $url .= "&xhprof=2";
        }

        if ($this->testmode) {
            $url .= "&testmode=1";
        }

        /**
         * @ignore
         */
        Piwik::postEvent('CronArchive.alterArchivingRequestUrl', [&$url]);

        return $url;
    }

    /**
     * @return CliMulti
     */
    private function makeCliMulti()
    {
        /** @var CliMulti $cliMulti */
        $cliMulti = new CliMulti($this->logger);
        $cliMulti->setUrlToPiwik($this->urlToPiwik);
        $cliMulti->setPhpCliConfigurationOptions($this->phpCliConfigurationOptions);
        $cliMulti->setAcceptInvalidSSLCertificate($this->acceptInvalidSSLCertificate);
        $cliMulti->setConcurrentProcessesLimit($this->getMaxConcurrentApiRequests());
        $cliMulti->runAsSuperUser();
        $cliMulti->onProcessFinish(function ($pid) {
            $this->printPerformanceStatsForProcess($pid);
        });
        return $cliMulti;
    }

    public function setUrlToPiwik($url)
    {
        $this->urlToPiwik = $url;
    }

    private function printPerformanceStatsForProcess($childPid)
    {
        if (!$this->isArchiveProfilingEnabled) {
            return;
        }

        $data = Logger::getMeasurementsFor(getmypid(), $childPid);
        if (empty($data)) {
            return;
        }

        $message = "";
        foreach ($data as $request => $measurements) {
            $message .= "PERFORMANCE FOR " . $request . "\n  ";
            $message .= implode("\n  ", $measurements) . "\n";
        }
        $this->logger->info($message);
    }

    private function hasReachedMaxConcurrentArchivers()
    {
        $cliMulti = $this->makeCliMulti();
        if ($this->maxConcurrentArchivers && $cliMulti->supportsAsync()) {
            $numRunning = 0;
            $processes = Process::getListOfRunningProcesses();
            $instanceId = SettingsPiwik::getPiwikInstanceId();

            foreach ($processes as $process) {
                if (strpos($process, 'console core:archive') !== false &&
                    (!$instanceId
                        || strpos($process, '--matomo-domain=' . $instanceId) !== false
                        || strpos($process, '--matomo-domain="' . $instanceId . '"') !== false
                        || strpos($process, '--matomo-domain=\'' . $instanceId . "'") !== false
                        || strpos($process, '--piwik-domain=' . $instanceId) !== false
                        || strpos($process, '--piwik-domain="' . $instanceId . '"') !== false
                        || strpos($process, '--piwik-domain=\'' . $instanceId . "'") !== false)) {
                    $numRunning++;
                }
            }
            if ($this->maxConcurrentArchivers < $numRunning) {
                $this->logger->info(sprintf("Archiving will stop now because %s archivers are already running and max %s are supposed to run at once.", $numRunning, $this->maxConcurrentArchivers));
                return true;
            } else {
                $this->logger->info(sprintf("%s out of %s archivers running currently", $numRunning, $this->maxConcurrentArchivers));
            }
        }
        return false;
    }

    protected function wasSegmentChangedRecently($definition, $allSegments)
    {
        foreach ($allSegments as $segment) {
            if ($segment['definition'] === $definition) {
                $twentyFourHoursAgo = Date::now()->subHour(24);
                $segmentDate = $segment['ts_created'];
                if (!empty($segment['ts_last_edit'])) {
                    $segmentDate = $segment['ts_last_edit'];
                }
                return Date::factory($segmentDate)->isLater($twentyFourHoursAgo);
            }
        }

        return false;
    }

    /**
     * @param ArchiveFilter $archiveFilter
     */
    public function setArchiveFilter(ArchiveFilter $archiveFilter): void
    {
        $this->archiveFilter = $archiveFilter;
    }

    private function makeWebsiteIdArchiveList(array $websitesIds)
    {
        if ($this->shouldArchiveAllSites) {
            $this->logger->info("- Will process all " . count($websitesIds) . " websites");
            return new FixedSiteIds($websitesIds);
        }

        if (!empty($this->shouldArchiveSpecifiedSites)) {
            $this->logger->info("- Will process specified sites: " . implode(', ', $websitesIds));
            return new FixedSiteIds($websitesIds);
        }

        return new SharedSiteIds($websitesIds, SharedSiteIds::OPTION_ALL_WEBSITES);
    }
}

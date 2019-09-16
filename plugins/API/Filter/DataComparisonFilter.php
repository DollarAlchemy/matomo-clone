<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\API\Filter;

use Piwik\API\Request;
use Piwik\Common;
use Piwik\Config;
use Piwik\DataTable;
use Piwik\DataTable\DataTableInterface;
use Piwik\DataTable\Simple;
use Piwik\Metrics;
use Piwik\Period;
use Piwik\Period\Factory;
use Piwik\Plugin\Report;
use Piwik\Segment;
use Piwik\Segment\SegmentExpression;
use Piwik\Site;

/**
 * Handles the API portion of the data comparison feature.
 *
 * If the `compareSegments`/`comparePeriods`/`compareDates` parameters are supplied this class will fetch
 * the data to compare with and store this data next to each row in the root report.
 *
 * Additionally, `..._change` columns will be added that show the percentage change for a column. This is only
 * done when comparing periods, since segments are subsets of visits, so it doesn't make sense to consider
 * differences between them as "changes".
 *
 * ### Comparing multiple periods
 *
 * It is possible to compare multiple periods with other multiple periods. For example, it
 * is possible to compare period=day, date=2018-02-03,2018-02-13 with period=day, date=2018-03-01,2018-03-15.
 * When done, this filter will compare the first period in the first set w/ the first period in the second set,
 * etc. So in the previous example, 2018-02-03 will be compared with 2018-03-01, 2018-02-04 with 2018-03-02, etc.
 *
 * ### Metadata
 *
 * This filter adds the following metadata to DataTables:
 *
 * - 'compareSegments': The list of segments being compared. The first entry will always be the value of the `segment` query param.
 * - 'comparePeriods': The list of labels of periods being compared. The first entry will always be the value of the
 *                     `period` query param.
 * - 'compareDates': The list of dates being compared. The first entry will always be the value of the `date` query param.
 * - 'comparisonSeries': Prettified labels for every comparison series in order.
 *
 * This filter adds the following metadata to rows in the comparison DataTables:
 *
 * - 'compareSegment': The segment of the data for the comparison row.
 * - 'compareSegmentPretty': The prettified label for the segment.
 * - 'comparePeriod': The period label for the data in the comparison row. This does not have to match a value in the
 *                    `comparePeriods` query parameter if comparing multiple periods.
 * - 'compareDate': The date for the period in the data in the comparison row. This does not have to match a value in the
 *                    `compareDates` query parameter if comparing multiple periods.
 * - 'comparePeriodPretty': The prettified label for the period.
 * - 'compareSeriesPretty': Prettified label for the comparison data represented by the row. This will match an entry
 *                          in the DataTable's `comparisonSeries` metadata.
 */
class DataComparisonFilter
{
    /**
     * @var array
     */
    private $request;

    /**
     * @var int
     */
    private $segmentCompareLimit;

    /**
     * @var int
     */
    private $periodCompareLimit;

    /**
     * @var array
     */
    private $columnMappings;

    /**
     * @var string
     */
    private $segmentName;

    /**
     * @var string[]
     */
    private $compareSegments;

    /**
     * @var string[]
     */
    private $compareDates;

    /**
     * @var string[]
     */
    private $comparePeriods;

    /**
     * @var int[]
     */
    private $compareSegmentIndices;

    /**
     * @var int[]
     */
    private $comparePeriodIndices;

    /**
     * @var bool
     */
    private $isRequestMultiplePeriod;

    public function __construct($request, Report $report = null)
    {
        $this->request = $request;

        $generalConfig = Config::getInstance()->General;
        $this->segmentCompareLimit = (int) $generalConfig['data_comparison_segment_limit'];
        $this->checkComparisonLimit($this->segmentCompareLimit, 'data_comparison_segment_limit');

        $this->periodCompareLimit = (int) $generalConfig['data_comparison_period_limit'];
        $this->checkComparisonLimit($this->periodCompareLimit, 'data_comparison_period_limit');

        $this->segmentName = $this->getSegmentNameFromReport($report);

        $this->compareSegments = Common::getRequestVar('compareSegments', $default = [], $type = 'array', $this->request);
        $this->compareSegments = Common::unsanitizeInputValues($this->compareSegments);
        if (count($this->compareSegments) > $this->segmentCompareLimit) {
            throw new \Exception("The maximum number of segments that can be compared simultaneously is {$this->segmentCompareLimit}.");
        }

        $this->compareDates = Common::getRequestVar('compareDates', $default = [], $type = 'array', $this->request);
        $this->compareDates = array_values($this->compareDates);

        $this->comparePeriods = Common::getRequestVar('comparePeriods', $default = [], $type = 'array', $this->request);
        $this->comparePeriods = array_values($this->comparePeriods);

        if (count($this->compareDates) !== count($this->comparePeriods)) {
            throw new \InvalidArgumentException("compareDates query parameter length must match comparePeriods query parameter length.");
        }

        if (count($this->compareDates) > $this->periodCompareLimit) {
            throw new \Exception("The maximum number of periods that can be compared simultaneously is {$this->periodCompareLimit}.");
        }

        if (empty($this->compareSegments)
            && empty($this->comparePeriods)
        ) {
            return;
        }

        $this->checkMultiplePeriodCompare();

        // add base compare against segment and date
        array_unshift($this->compareSegments, isset($this->request['segment']) ? $this->request['segment'] : '');
        array_unshift($this->compareDates, isset($this->request['date']) ? $this->request['date'] : '');
        array_unshift($this->comparePeriods, isset($this->request['period']) ? $this->request['period'] : '');

        // map segments/periods to their indexes in the query parameter arrays for comparisonIdSubtable matching
        $this->compareSegmentIndices = array_flip($this->compareSegments);
        foreach ($this->comparePeriods as $index => $period) {
            $date = $this->compareDates[$index];
            $this->comparePeriodIndices[$period][$date] = $index;
        }
    }

    /**
     * @param DataTable\DataTableInterface $table
     */
    public function compare(DataTable\DataTableInterface $table)
    {
        if (empty($this->compareSegments)
            && empty($this->comparePeriods)
        ) {
            return;
        }

        $method = Common::getRequestVar('method', $default = null, $type = 'string', $this->request);
        if ($method == 'Live') {
            throw new \Exception("Data comparison is not enabled for the Live API.");
        }

        // optimization, if empty, single table, don't need to make extra queries
        if ($table->getRowsCount() == 0) {
            return;
        }

        $this->columnMappings = $this->getColumnMappings();

        $comparisonSeries = [];

        // fetch data first
        $reportsToCompare = self::getReportsToCompare($this->compareSegments, $this->comparePeriods, $this->compareDates);
        foreach ($reportsToCompare as $index => $modifiedParams) {
            $compareMetadata = $this->getMetadataFromModifiedParams($modifiedParams);
            $comparisonSeries[] = $compareMetadata['compareSeriesPretty'];

            $compareTable = $this->requestReport($method, $modifiedParams);
            $this->compareTables($compareMetadata, $table, $compareTable);
        }

        // calculate changes (including processed metric changes)
        // NOTE: it doesn't make to sense to calculate these values for segments, since segments are subsets of all visits, where periods are
        //       time periods (so things can change from one to another).
        if (count($this->comparePeriods) > 1) {
            $this->compareChangePercents($table);
        }

        // format comparison table metrics
        $this->formatComparisonTables($table);

        // add comparison parameters as metadata
        $table->filter(function (DataTable $singleTable) use ($comparisonSeries) {
            if (isset($this->compareSegments)) {
                $singleTable->setMetadata('compareSegments', $this->compareSegments);
            }

            if (isset($this->comparePeriods)) {
                $singleTable->setMetadata('comparePeriods', $this->comparePeriods);
            }

            if (isset($this->compareDates)) {
                $singleTable->setMetadata('compareDates', $this->compareDates);
            }

            $singleTable->setMetadata('comparisonSeries', $comparisonSeries);
        });
    }

    public static function getReportsToCompare($compareSegments, $comparePeriods, $compareDates)
    {
        $permutations = [];

        // NOTE: the order of these loops determines the order of the rows in the comparison table. ie,
        // if we loop over dates then segments, then we'll see comparison rows change segments before changing
        // periods. this is because this loop determines in what order we fetch report data.
        foreach ($compareDates as $index => $date) {
            foreach ($compareSegments as $segment) {
                $period = $comparePeriods[$index];

                $params = [];
                $params['segment'] = $segment;

                if (!empty($period)
                    && !empty($date)
                ) {
                    $params['date'] = $date;
                    $params['period'] = $period;
                }

                $permutations[] = $params;
            }
        }

        return $permutations;
    }

    /**
     * @param $paramsToModify
     * @return DataTable
     */
    private function requestReport($method, $paramsToModify)
    {
        $params = array_merge(
            [
                'filter_limit' => -1,
                'filter_offset' => 0,
                'filter_sort_column' => '',
                'filter_truncate' => -1,
                'compare' => 0,
                'totals' => 1,
                'disable_queued_filters' => 1,
                'format_metrics' => 0,
            ],
            $paramsToModify
        );

        $params['keep_totals_row'] = Common::getRequestVar('keep_totals_row', 0, 'int', $this->request);
        $params['keep_totals_row_label'] = Common::getRequestVar('keep_totals_row_label', '', 'string', $this->request);

        if (!isset($params['idSite'])) {
            $params['idSite'] = Common::getRequestVar('idSite', null, 'string', $this->request);
        }
        if (!isset($params['period'])) {
            $params['period'] = Common::getRequestVar('period', null, 'string', $this->request);
        }
        if (!isset($params['date'])) {
            $params['date'] = Common::getRequestVar('date', null, 'string', $this->request);
        }

        $idSubtable = Common::getRequestVar('idSubtable', 0, 'int', $this->request);
        if ($idSubtable > 0) {
            $comparisonIdSubtables = Common::getRequestVar('comparisonIdSubtables', $default = false, 'json', $this->request);
            if (empty($comparisonIdSubtables)) {
                throw new \Exception("Comparing segments/periods with subtables only works when the comparison idSubtables are supplied as well.");
            }

            $segmentIndex = empty($paramsToModify['segment']) ? 0 : $this->compareSegmentIndices[$paramsToModify['segment']];
            $periodIndex = empty($paramsToModify['period']) ? 0 : $this->comparePeriodIndices[$paramsToModify['period']][$paramsToModify['date']];
            $seriesIndex = $periodIndex * count($this->compareSegments) + $segmentIndex;

            if (!isset($comparisonIdSubtables[$seriesIndex])) {
                throw new \Exception("Invalid comparisonIdSubtables parameter: no idSubtable found for segment $segmentIndex and period $periodIndex");
            }

            $comparisonIdSubtable = $comparisonIdSubtables[$seriesIndex];
            if ($comparisonIdSubtable === -1) { // no subtable in comparison row
                $table = new DataTable();
                $table->setMetadata('site', new Site($params['idSite']));
                $table->setMetadata('period', Period\Factory::build($params['period'], $params['date']));
                return $table;
            }

            $params['idSubtable'] = $comparisonIdSubtable;
        }

        return Request::processRequest($method, $params);
    }

    private function formatComparisonTables(DataTableInterface $tableOrMap)
    {
        $tableOrMap->filter(function (DataTable $table) {
            foreach ($table->getRowsWithTotalsRow() as $row) {
                /** @var DataTable $comparisonTable */
                $comparisonTable = $row->getComparisons();
                if (!empty($comparisonTable)) { // sanity check
                    $columnMappings = $this->columnMappings;
                    $comparisonTable->filter(DataTable\Filter\ReplaceColumnNames::class, [$columnMappings]);
                }

                $subtable = $row->getSubtable();
                if ($subtable) {
                    $this->formatComparisonTables($subtable);
                }
            }
        });
    }

    private function compareRow(DataTable $table, $compareMetadata, DataTable\Row $row, DataTable\Row $compareRow = null, DataTable $rootTable = null)
    {
        $comparisonDataTable = $row->getComparisons();
        if (empty($comparisonDataTable)) {
            $comparisonDataTable = new DataTable();
            $comparisonDataTable->setMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME,
                $table->getMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME));
            $row->setComparisons($comparisonDataTable);
        }

        $this->addIndividualChildPrettifiedMetadata($compareMetadata, $rootTable);

        $columns = [];
        if ($compareRow) {
            foreach ($compareRow as $name => $value) {
                if (!is_numeric($value)
                    || $name == 'label'
                ) {
                    continue;
                }

                $columns[$name] = $value;
            }
        } else {
            foreach ($row as $name => $value) {
                if (!is_numeric($value)
                    || $name == 'label'
                ) {
                    continue;
                }

                $columns[$name] = 0;
            }
        }

        $newRow = new DataTable\Row([
            DataTable\Row::COLUMNS => $columns,
            DataTable\Row::METADATA => $compareMetadata,
        ]);

        // set subtable
        $newRow->setMetadata('idsubdatatable_in_db', -1);
        if ($compareRow) {
            $subtableId = $compareRow->getMetadata('idsubdatatable_in_db') ?: $compareRow->getIdSubDataTable();
            if ($subtableId) {
                $newRow->setMetadata('idsubdatatable_in_db', $subtableId);
            }
        }

        // add segment metadatas
        if ($row->getMetadata('segment')) {
            $newSegment = $row->getMetadata('segment');
            if ($newRow->getMetadata('compareSegment')) {
                $newSegment = Segment::combine($newRow->getMetadata('compareSegment'), SegmentExpression::AND_DELIMITER, $newSegment);
            }
            $newRow->setMetadata('segment', $newSegment);
        } else if ($this->segmentName
            && $row->getMetadata('segmentValue') !== false
        ) {
            $segmentValue = $row->getMetadata('segmentValue');
            $newRow->setMetadata('segment', sprintf('%s==%s', $this->segmentName, urlencode($segmentValue)));
        }

        $comparisonDataTable->addRow($newRow);

        // recurse on subtable if there
        $subtable = $row->getSubtable();
        if ($subtable
            && $compareRow
        ) {
            $this->compareTable($compareMetadata, $subtable, $rootTable, $compareRow->getSubtable());
        }
    }

    private function compareTables($compareMetadata, DataTableInterface $tables, DataTableInterface $compareTables = null)
    {
        if ($tables instanceof DataTable) {
            $this->compareTable($compareMetadata, $tables, $compareTables, $compareTables);
        } else if ($tables instanceof DataTable\Map) {
            $childTablesArray = array_values($tables->getDataTables());
            $compareTablesArray = isset($compareTables) ? array_values($compareTables->getDataTables()) : [];

            $isDatePeriod = $tables->getKeyName() == 'date';

            foreach ($childTablesArray as $index => $childTable) {
                $compareChildTable = isset($compareTablesArray[$index]) ? $compareTablesArray[$index] : null;
                $this->compareTables($compareMetadata, $childTable, $compareChildTable);
            }

            // in case one of the compared periods has more periods than the main one, we want to fill the result with empty datatables
            // so the comparison data is still present. this allows us to see that data in an evolution report.
            if ($isDatePeriod) {
                $lastTable = end($childTablesArray);

                /** @var Period $lastPeriod */
                $lastPeriod = $lastTable->getMetadata('period');
                $periodType = $lastPeriod->getLabel();

                for ($i = count($childTablesArray); $i < count($compareTablesArray); ++$i) {
                    $periodChangeCount = $i - count($childTablesArray) + 1;
                    $newPeriod = Period\Factory::build($periodType, $lastPeriod->getDateStart()->addPeriod($periodChangeCount, $periodType));

                    // create an empty table for the main request
                    $newTable = new DataTable();
                    $newTable->setAllTableMetadata($lastTable->getAllTableMetadata());
                    $newTable->setMetadata('period', $newPeriod);

                    if ($newPeriod->getLabel() === 'week' || $newPeriod->getLabel() === 'range') {
                        $periodLabel = $newPeriod->getRangeString();
                    } else {
                        $periodLabel = $newPeriod->getPrettyString();
                    }

                    $tables->addTable($newTable, $periodLabel);

                    // compare with the empty table
                    $compareTable = $compareTablesArray[$i];
                    $this->compareTables($compareMetadata, $newTable, $compareTable);
                }
            }
        } else {
            throw new \Exception("Unexpected DataTable type: " . get_class($tables));
        }
    }

    private function compareTable($compareMetadata, DataTable $table, DataTable $rootCompareTable = null, DataTable $compareTable = null)
    {
        // if there are no rows in the table because the metrics are 0, add one so we can still set comparison values
        if ($table->getRowsCount() == 0) {
            $table->addRow(new DataTable\Row());
        }

        foreach ($table->getRows() as $row) {
            $label = $row->getColumn('label');

            $compareRow = null;
            if ($compareTable instanceof Simple) {
                $compareRow = $compareTable->getFirstRow() ?: null;
            } else if ($compareTable instanceof DataTable) {
                $compareRow = $compareTable->getRowFromLabel($label) ?: null;
            }

            $this->compareRow($table, $compareMetadata, $row, $compareRow, $rootCompareTable);
        }

        $totalsRow = $table->getTotalsRow();
        if (!empty($totalsRow)) {
            $compareRow = $compareTable ? $compareTable->getTotalsRow() : null;
            $this->compareRow($table, $compareMetadata, $totalsRow, $compareRow, $rootCompareTable);
        }

        if ($compareTable) {
            $totals = $compareTable->getMetadata('totals');
            if (!empty($totals)) {
                $totals = $this->replaceIndexesInTotals($totals);
                $comparisonTotalsEntry = array_merge($compareMetadata, [
                    'totals' => $totals,
                ]);

                $allTotalsTables = $table->getMetadata('comparisonTotals');
                $allTotalsTables[] = $comparisonTotalsEntry;
                $table->setMetadata('comparisonTotals', $allTotalsTables);
            }
        }
    }

    private function getColumnMappings()
    {
        $allMappings = Metrics::getMappingFromIdToName();

        $mappings = [];
        foreach ($allMappings as $index => $name) {
            $mappings[$index] = $name;
            $mappings[$index . '_change'] = $name . '_change';
        }
        return $mappings;
    }

    private function checkComparisonLimit($n, $configName)
    {
        if ($n <= 1) {
            throw new \Exception("The [General] $configName INI config option must be greater than 1.");
        }
    }

    private function addIndividualChildPrettifiedMetadata(array &$metadata, DataTable $parentTable = null)
    {
        if ($parentTable
            && $this->isRequestMultiplePeriod()
        ) {
            /** @var Period $period */
            $period = $parentTable->getMetadata('period');
            if (empty($period)) {
                return;
            }

            $prettyPeriod = $period->getLocalizedLongString();
            $metadata['comparePeriodPretty'] = ucfirst($prettyPeriod);

            $metadata['comparePeriod'] = $period->getLabel();
            $metadata['compareDate'] = $period->getDateStart()->toString();
        }
    }

    private function getMetadataFromModifiedParams($modifiedParams)
    {
        $metadata = [];

        $period = isset($modifiedParams['period']) ? $modifiedParams['period'] : reset($this->comparePeriods);
        $date = isset($modifiedParams['date']) ? $modifiedParams['date'] : reset($this->compareDates);
        $segment = isset($modifiedParams['segment']) ? $modifiedParams['segment'] : reset($this->compareSegments);

        $metadata['compareSegment'] = $segment;

        $segmentObj = new Segment($segment, []);
        $metadata['compareSegmentPretty'] = $segmentObj->getPrettySegmentName(false);

        $metadata['comparePeriod'] = $period;
        $metadata['compareDate'] = $date;

        $prettyPeriod = Factory::build($period, $date)->getLocalizedLongString();
        $metadata['comparePeriodPretty'] = ucfirst($prettyPeriod);

        // set compareSeriesPretty
        $segmentPretty = isset($metadata['compareSegmentPretty']) ? $metadata['compareSegmentPretty'] : '';

        $periodPretty = Factory::build($period, $date)->getLocalizedLongString();
        $periodPretty = ucfirst($periodPretty);

        $metadata['compareSeriesPretty'] = $this->getComparisonSeriesLabelSuffixFromParts($periodPretty, $segmentPretty);

        return $metadata;
    }

    private function getComparisonSeriesLabelSuffixFromParts($periodPretty, $segmentPretty)
    {
        $comparisonLabels = [
            $periodPretty,
            $segmentPretty,
        ];
        $comparisonLabels = array_filter($comparisonLabels);

        return '(' . implode(') (', $comparisonLabels) . ')';
    }

    private function replaceIndexesInTotals($totals)
    {
        foreach ($totals as $index => $value) {
            if (isset($this->columnMappings[$index])) {
                $name = $this->columnMappings[$index];
                $totals[$name] = $totals[$index];
                unset($totals[$index]);
            }
        }
        return $totals;
    }

    private function getSegmentNameFromReport(Report $report = null)
    {
        if (empty($report)) {
            return null;
        }

        $dimension = $report->getDimension();
        if (empty($dimension)) {
            return null;
        }

        $segments = $dimension->getSegments();
        if (empty($segments)) {
            return null;
        }

        /** @var \Piwik\Plugin\Segment $segment */
        $segment     = reset($segments);
        $segmentName = $segment->getSegment();
        return $segmentName;
    }

    private function checkMultiplePeriodCompare()
    {
        if ($this->isRequestMultiplePeriod()) {
            foreach ($this->comparePeriods as $index => $period) {
                if (!Period::isMultiplePeriod($this->compareDates[$index], $period)) {
                    throw new \Exception("Cannot compare: original request is multiple period and cannot be compared with single periods.");
                }
            }
        } else {
            foreach ($this->comparePeriods as $index => $period) {
                if (Period::isMultiplePeriod($this->compareDates[$index], $period)) {
                    throw new \Exception("Cannot compare: original request is single period and cannot be compared with multiple periods.");
                }
            }
        }
    }

    private function isRequestMultiplePeriod()
    {
        if ($this->isRequestMultiplePeriod === null) {
            $period = Common::getRequestVar('period', $default = null, 'string', $this->request);
            $date = Common::getRequestVar('date', $default = null, 'string', $this->request);

            $this->isRequestMultiplePeriod = Period::isMultiplePeriod($date, $period);
        }
        return $this->isRequestMultiplePeriod;
    }

    private function compareChangePercents(DataTableInterface $result)
    {
        $segmentCount = count($this->compareSegments);

        $result->filter(function (DataTable $table) use ($segmentCount) {
            foreach ($table->getRowsWithTotalsRow() as $row) {
                $comparisons = $row->getComparisons();
                if (empty($comparisons)) {
                    continue;
                }

                /** @var DataTable\Row[] $rows */
                $rows = array_values($comparisons->getRows());
                foreach ($rows as $index => $compareRow) {
                    if ($index < $segmentCount) {
                        continue; // do not calculate for first period
                    }

                    list($periodIndex, $segmentIndex) = self::getIndividualComparisonRowIndices($table, $index, $segmentCount);

                    $otherPeriodRowIndex = $segmentIndex;
                    $otherPeriodRow = $comparisons[$otherPeriodRowIndex];

                    foreach ($compareRow->getColumns() as $name => $value) {
                        $valueToCompare = $otherPeriodRow ? $otherPeriodRow->getColumn($name) : 0;
                        $valueToCompare = $valueToCompare ?: 0;

                        $change = DataTable\Filter\CalculateEvolutionFilter::calculate($value, $valueToCompare, $precision = 1, $appendPercent = false);

                        if ($change >= 0) {
                            $change = '+' . $change;
                        }
                        $change .= '%';

                        $compareRow->addColumn($name . '_change', $change);
                    }
                }
            }
        });
    }

    /**
     * Returns the period and segment indices for a given comparison index.
     *
     * @param DataTable $table
     * @param $comparisonRowIndex
     * @param null $segmentCount
     * @return array
     */
    public static function getIndividualComparisonRowIndices(DataTable $table, $comparisonRowIndex, $segmentCount = null)
    {
        $segmentCount = $segmentCount ?: count($table->getMetadata('compareSegments'));
        $segmentIndex = $comparisonRowIndex % $segmentCount;
        $periodIndex = floor($comparisonRowIndex / $segmentCount);
        return [$periodIndex, $segmentIndex];
    }

    /**
     * Returns the series index for a comparison based on the period and segment indices.
     *
     * @param DataTable $table
     * @param int $periodIndex
     * @param int $segmentIndex
     * @param int|null $segmentCount
     * @return int
     */
    public static function getComparisonSeriesIndex(DataTable $table, $periodIndex, $segmentIndex, $segmentCount = null)
    {
        $segmentCount = $segmentCount ?: count($table->getMetadata('compareSegments'));
        return $periodIndex * $segmentCount + $segmentIndex;
    }
}
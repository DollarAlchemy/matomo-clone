<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Ecommerce\Columns;

use Piwik\Columns\DimensionSegmentFactory;
use Piwik\Columns\Discriminator;
use Piwik\Common;
use Piwik\Piwik;
use Piwik\Plugin\Segment;
use Piwik\Segment\SegmentsList;
use Piwik\Tracker\Action;
use Piwik\Tracker\GoalManager;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visitor;

class Revenue extends BaseConversion
{
    protected $columnName = 'revenue';
    protected $columnType = 'float default NULL';
    protected $type = self::TYPE_MONEY;
    protected $category = 'Goals_Ecommerce';
    protected $nameSingular = 'Ecommerce_OrderValue';

    public function getDbDiscriminator()
    {
        return new Discriminator($this->dbTableName, 'idgoal', GoalManager::IDGOAL_ORDER);
    }


    public function configureSegments(SegmentsList $segmentsList, DimensionSegmentFactory $dimensionSegmentFactory)
    {
        //new Segment revenue on order
        $segment = new Segment();
        $segment->setCategory($this->category);
        $segment->setName(Piwik::translate('Ecommerce_OrderRevenue'));
        $segment->setSegment('revenueOrder');
        $segment->setSqlSegment('log_conversion.idvisit');
        $segment->setSqlFilter(function ($valueToMatch, $sqlField, $matchType) {
            $table = Common::prefixTable($this->dbTableName);
            $sql = " SELECT {$sqlField} from {$table} WHERE (log_conversion.revenue {$matchType} ?) ";
            return [
              'SQL'  => $sql,
              'bind' => (float)$valueToMatch,
            ];
        });
        $segmentsList->addSegment($dimensionSegmentFactory->createSegment($segment));

        //new Segment revenue left in cart
        $segment = new Segment();
        $segment->setCategory($this->category);
        $segment->setName(Piwik::translate('Ecommerce_RevenueLeftInCart'));
        $segment->setSegment('revenueAbandonedCart');
        $segment->setSqlSegment('log_conversion.idvisit');
        $segment->setSqlFilter(function ($valueToMatch, $sqlField , $matchType) {
            $table = Common::prefixTable($this->dbTableName);
            $sql = " SELECT {$sqlField} from {$table} WHERE (idgoal = -1 and log_conversion.revenue {$matchType} ?) ";
            return [
              'SQL'  => $sql,
              'bind' =>(float)$valueToMatch,
            ];
        });
        $segmentsList->addSegment($dimensionSegmentFactory->createSegment($segment));

    }


    /**
     * @param Request $request
     * @param Visitor $visitor
     * @param Action|null $action
     * @param GoalManager $goalManager
     *
     * @return mixed|false
     */
    public function onGoalConversion(Request $request, Visitor $visitor, $action, GoalManager $goalManager)
    {
        $defaultRevenue = $goalManager->getGoalColumn('revenue');
        $revenue        = $request->getGoalRevenue($defaultRevenue);

        return $this->roundRevenueIfNeeded($revenue);
    }

    /**
     * @param Request $request
     * @param Visitor $visitor
     * @param Action|null $action
     * @param GoalManager $goalManager
     *
     * @return mixed|false
     */
    public function onEcommerceOrderConversion(Request $request, Visitor $visitor, $action, GoalManager $goalManager)
    {
        $defaultRevenue = 0;
        $revenue = $request->getGoalRevenue($defaultRevenue);

        return $this->roundRevenueIfNeeded($revenue);
    }

    /**
     * @param Request $request
     * @param Visitor $visitor
     * @param Action|null $action
     * @param GoalManager $goalManager
     *
     * @return mixed|false
     */
    public function onEcommerceCartUpdateConversion(Request $request, Visitor $visitor, $action, GoalManager $goalManager)
    {
        return $this->onEcommerceOrderConversion($request, $visitor, $action, $goalManager);
    }
}
<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\Events\Reports;

use Piwik\Piwik;
use Piwik\Plugins\Events\Columns\EventName;

/**
 * Report metadata class for the Events.getNameFromCategoryId class.
 */
class GetNameFromCategoryId extends Base
{
    protected function init()
    {
        $this->categoryId = 'Events_Events';
        $this->processedMetrics = false;

        $this->dimension     = new EventName();
        $this->name          = Piwik::translate('Events_EventNames');
        $this->isSubtableReport = true;
    }
}

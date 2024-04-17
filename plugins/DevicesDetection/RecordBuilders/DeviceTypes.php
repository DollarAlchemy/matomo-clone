<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\DevicesDetection\RecordBuilders;

use Piwik\Plugins\DevicesDetection\Archiver;

class DeviceTypes extends Base
{
    public function __construct()
    {
        parent::__construct(Archiver::DEVICE_TYPE_RECORD_NAME, Archiver::DEVICE_TYPE_FIELD, true);
    }
}

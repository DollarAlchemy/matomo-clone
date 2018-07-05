<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Access\Capability;

use Piwik\Access\Capability;

// TO BE IGNORED
class AnalyticsWrite extends Capability
{
    const ID = 'analytics_write';

    public function getName()
    {
        return 'Analytics View';
    }

    public function getId()
    {
        return self::ID;
    }

    public function getDescription()
    {
        return 'Lets you view ...';
    }

    public function getHelpUrl()
    {
        return 'https://matomo.org/faq/general/faq_70/';
    }

}

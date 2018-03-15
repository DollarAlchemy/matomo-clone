<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik;

use Piwik\Plugin\Manager;

class CacheId
{
    public static function languageAware($cacheId)
    {
        return $cacheId . '-' . Translate::getLanguageLoaded();
    }

    public static function pluginAware($cacheId)
    {
        $pluginManager = Manager::getInstance();
        $pluginNames   = $pluginManager->getLoadedPluginsName();
        $cacheId       = $cacheId . '-' . md5(implode('', $pluginNames));
        $cacheId       = self::languageAware($cacheId);

        return $cacheId;
    }

    public static function siteAware($cacheId, array $idSites = null)
    {
        if ($idSites === null) {
            $idSites = self::getIdSiteList('idSite');
            $cacheId .= self::idSiteListCacheKey($idSites);

            $idSites = self::getIdSiteList('idSites');
            $cacheId .= self::idSiteListCacheKey($idSites);
        } else {
            $cacheId .= self::idSiteListCacheKey($idSites);
        }

        return $cacheId;
    }

    private static function getIdSiteList($queryParamName)
    {
        $idSiteParam = Common::getRequestVar($queryParamName, false);
        if ($idSiteParam === false) {
            return [];
        }

        $idSiteParam = explode(',', $idSiteParam);
        $idSiteParam = array_map('intval', $idSiteParam);
        $idSiteParam = array_unique($idSiteParam);
        sort($idSiteParam);
        return $idSiteParam;
    }

    private static function idSiteListCacheKey($idSites)
    {
        if (empty($idSites)) {
            return '';
        }

        if (count($idSites) <= 5) {
            return '-' . implode('_', $idSites); // we keep the cache key readable when possible
        } else {
            return '-' . md5(implode('_', $idSites)); // we need to shorten it
        }
    }

    /**
     * Temporarily overwrites the idSite parameter so all code executed by `$callback()`
     * will use cache keys for that idSite.
     *
     * Useful when you need to change the idSite context for a chunk of code. For example,
     * if we are archiving for more than one site in sequence, we don't want to use
     * the same caches for both archiving executions.
     *
     * @param string|int $idSite
     * @param callable $callback
     * @return mixed returns result of $callback
     */
    public static function overwriteIdSiteForCache($idSite, $callback)
    {
        // temporarily set the idSite query parameter so archiving will end up using
        // the correct site aware caches
        $originalGetIdSite = isset($_GET['idSite']) ? $_GET['idSite'] : null;
        $originalPostIdSite = isset($_POST['idSite']) ? $_POST['idSite'] : null;

        $originalGetIdSites = isset($_GET['idSites']) ? $_GET['idSites'] : null;
        $originalPostIdSites = isset($_POST['idSites']) ? $_POST['idSites'] : null;

        try {
            $_GET['idSite'] = $_POST['idSite'] = $idSite;

            // idSites is a deprecated query param that is still in use. since it is deprecated and new
            // supported code shouldn't rely on it, we can (more) safely unset it here, since we are just
            // calling downstream matomo code. we unset it because we don't want it interfering w/
            // code in $callback().
            unset($_GET['idSites']);
            unset($_POST['idSites']);

            return $callback();
        } finally {
            if ($originalGetIdSite !== null) {
                $_GET['idSite'] = $originalGetIdSite;
            }

            if ($originalPostIdSite !== null) {
                $_POST['idSite'] = $originalPostIdSite;
            }

            if ($originalGetIdSites !== null) {
                $_GET['idSites'] = $originalGetIdSites;
            }

            if ($originalPostIdSites !== null) {
                $_GET['idSites'] = $originalPostIdSites;
            }
        }
    }
}

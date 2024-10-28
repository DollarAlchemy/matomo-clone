<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Login\Security;

use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\IP;
use Piwik\Piwik;
use Piwik\Plugins\GeoIp2\LocationProvider\GeoIp2;
use Piwik\Plugins\Login\Emails\LoginFromDifferentCountryEmail;
use Piwik\Plugins\Login\Model;
use Piwik\Plugins\UserCountry\LocationProvider;
use Piwik\Plugins\Login\UserSettings;

class LoginFromDifferentCountryDetection
{
    /**
     * @var Model
     */
    private $model;

    /**
     * @var array|null
     */
    private $location = null;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function isEnabled(): bool
    {
        // we need at least one GeoIP provider that is not the default or disabled one
        $geoIPWorking = $this->isGeoIPWorking();

        // we need the user to have the option enabled - defaults to true if not set
        $notificationEnabled = $this->isUserNotificationEnabled();

        return $geoIPWorking && $notificationEnabled;
    }

    private function isGeoIPWorking(): bool
    {
        $provider = LocationProvider::getCurrentProvider();

        return $provider instanceof GeoIp2
            && $provider->isAvailable()
            && $provider->isWorking()
            && true === $provider->getSupportedLocationInfo()[LocationProvider::COUNTRY_CODE_KEY];
    }

    private function isUserNotificationEnabled(): bool
    {
        // we need the user to have the option enabled - defaults to true if not set
        // NOTE: it seems the first read in a new session is always using the default value
        $userSettings = new UserSettings();
        return $userSettings->enableLoginCountryChangeNotification->getValue();
    }

    private function getLocation(): array
    {
        if (null === $this->location) {
            // since we checked whether the current provider is GeoIP,
            // we can directly use it here
            $provider = LocationProvider::getCurrentProvider();
            $this->location = $provider->getLocation([
                'ip' => IP::getIpFromHeader(),
                'disable_fallbacks' => true,
            ]) ?: [LocationProvider::COUNTRY_CODE_KEY => ''];
        }

        return $this->location;
    }

    private function getCurrentLoginCountry(): string
    {
        return $this->getLocation()[LocationProvider::COUNTRY_CODE_KEY] ?? '';
    }

    public function check(string $login): void
    {
        $lastLoginCountry = $this->model->getLastLoginCountry($login);
        $currentLoginCountry = $this->getCurrentLoginCountry();

        $isDifferentCountries = $currentLoginCountry && $currentLoginCountry !== $lastLoginCountry;

        if ($isDifferentCountries) {
            if (!empty($lastLoginCountry)) {
                // send email only if we had previous value
                $this->sendLoginFromDifferentCountryEmailToUser(
                    $login,
                    $currentLoginCountry,
                    IP::getIpFromHeader()
                );
            }

            // store new country
            $this->model->setLastLoginCountry($login, $currentLoginCountry);
        }
    }

    private function sendLoginFromDifferentCountryEmailToUser(string $login, string $countryCode, string $ip): void
    {
        // create from DI container so plugins can modify email contents if they want
        $email = StaticContainer::getContainer()->make(LoginFromDifferentCountryEmail::class, [
            'login' => $login,
            'email' => Piwik::getCurrentUserEmail(),
            'country' => $country ? Piwik::translate('Intl_Country_' . strtoupper($countryCode)) : '',
            'ip' => $ip,
        ]);
        $email->safeSend();
    }
}

<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Login\Emails;

use Piwik\Date;
use Piwik\Intl\Data\Provider\DateTimeFormatProvider;
use Piwik\Mail;
use Piwik\Piwik;
use Piwik\Plugin\Manager as PluginManager;
use Piwik\Url;
use Piwik\View;

class LoginFromDifferentCountryEmail extends Mail
{
    /**
     * @var string
     */
    private $login;

    /**
     * @var string
     */
    private $email;

    /**
     * @var string
     */
    private $country;

    /**
     * @var string
     */
    private $ip;


    public function __construct($login, $email, $country, $ip)
    {
        parent::__construct();

        $this->login = $login;
        $this->email = $email;
        $this->country = $country;
        $this->ip = $ip;

        $this->setUpEmail();
    }

    private function setUpEmail()
    {
        $this->setDefaultFromPiwik();
        $this->addTo($this->email);
        $this->setSubject($this->getDefaultSubject());
        $this->addReplyTo($this->getFrom(), $this->getFromName());
        $this->setWrappedHtmlBody($this->getDefaultBodyView());
    }

    private function getDefaultSubject()
    {
        return Piwik::translate('Login_LoginFromDifferentCountryEmailSubject');
    }

    private function getDateAndTimeFormatted(): string
    {
        return Date::now()->getLocalized(DateTimeFormatProvider::DATETIME_FORMAT_LONG);
    }

    private function getPasswordResetLink(): string
    {
        return Url::getCurrentUrlWithoutQueryString()
            . '?module=' . Piwik::getLoginPluginName()
            . '&showResetForm=1';
    }

    private function getEnable2FALink(): string
    {
        if (PluginManager::getInstance()->isPluginActivated('TwoFactorAuth')) {
            return Url::getCurrentUrlWithoutQueryString() . '?module=UsersManager&action=userSecurity';
        } else {
            return '';
        }
    }

    private function getDefaultBodyView()
    {
        $view = new View('@Login/_loginFromDifferentCountryEmail.twig');
        $view->login = $this->login;
        $view->country = $this->country;
        $view->ip = $this->ip;
        $view->dateTime = $this->getDateAndTimeFormatted();
        $view->resetPasswordLink = $this->getPasswordResetLink();
        $view->enable2FALink = $this->getEnable2FALink();

        return $view->render();
    }
}

<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\CorePluginsAdmin;

use Exception;
use Piwik\API\Request;
use Piwik\Common;
use Piwik\Exception\MissingFilePermissionException;
use Piwik\Filechecks;
use Piwik\Filesystem;
use Piwik\Nonce;
use Piwik\Notification;
use Piwik\Piwik;
use Piwik\Plugin;
use Piwik\Plugins\Marketplace\Marketplace;
use Piwik\Plugins\Marketplace\Plugins;
use Piwik\Settings\Manager as SettingsManager;
use Piwik\SettingsPiwik;
use Piwik\Translation\Translator;
use Piwik\Url;
use Piwik\Version;
use Piwik\View;

class Controller extends Plugin\ControllerAdmin
{
    const UPDATE_NONCE = 'CorePluginsAdmin.updatePlugin';
    const INSTALL_NONCE = 'CorePluginsAdmin.installPlugin';
    const ACTIVATE_NONCE = 'CorePluginsAdmin.activatePlugin';
    const DEACTIVATE_NONCE = 'CorePluginsAdmin.deactivatePlugin';
    const UNINSTALL_NONCE = 'CorePluginsAdmin.uninstallPlugin';

    /**
     * @var Translator
     */
    private $translator;

    /**
     * @var PluginInstaller
     */
    private $pluginInstaller;

    /**
     * @var Plugins
     */
    private $marketplacePlugins;

    public function __construct(Translator $translator, PluginInstaller $pluginInstaller, Plugins $marketplacePlugins)
    {
        $this->translator = $translator;
        $this->pluginInstaller = $pluginInstaller;
        $this->marketplacePlugins = $marketplacePlugins;

        parent::__construct();
    }

    private function createUpdateOrInstallView($template, $nonceName)
    {
        $pluginName = $this->initPluginModification($nonceName);
        $this->dieIfPluginsAdminIsDisabled();

        $view = $this->configureView('@CorePluginsAdmin/' . $template);

        $view->plugin = array('name' => $pluginName);

        try {
            $this->pluginInstaller->installOrUpdatePluginFromMarketplace($pluginName);

        } catch (\Exception $e) {

            $notification = new Notification($e->getMessage());
            $notification->context = Notification::CONTEXT_ERROR;
            Notification\Manager::notify('CorePluginsAdmin_InstallPlugin', $notification);

            $this->redirectAfterModification(true);
            return;
        }

        $view->plugin = $this->marketplacePlugins->getPluginInfo($pluginName);

        return $view;
    }

    public function updatePlugin()
    {
        $view = $this->createUpdateOrInstallView('updatePlugin', static::UPDATE_NONCE);
        return $view->render();
    }

    public function installPlugin()
    {
        $view = $this->createUpdateOrInstallView('installPlugin', static::INSTALL_NONCE);
        $view->nonce = Nonce::getNonce(static::ACTIVATE_NONCE);

        return $view->render();
    }

    public function uploadPlugin()
    {
        static::dieIfPluginsAdminIsDisabled();
        Piwik::checkUserHasSuperUserAccess();

        $nonce = Common::getRequestVar('nonce', null, 'string');

        if (!Nonce::verifyNonce(static::INSTALL_NONCE, $nonce)) {
            throw new \Exception($this->translator->translate('General_ExceptionNonceMismatch'));
        }

        Nonce::discardNonce(static::INSTALL_NONCE);

        if (empty($_FILES['pluginZip'])) {
            throw new \Exception('You did not specify a ZIP file.');
        }

        if (!empty($_FILES['pluginZip']['error'])) {
            throw new \Exception('Something went wrong during the plugin file upload. Please try again.');
        }

        $file = $_FILES['pluginZip']['tmp_name'];
        if (!file_exists($file)) {
            throw new \Exception('Something went wrong during the plugin file upload. Please try again.');
        }

        $view = $this->configureView('@CorePluginsAdmin/uploadPlugin');

        $pluginMetadata  = $this->pluginInstaller->installOrUpdatePluginFromFile($file);

        $view->nonce = Nonce::getNonce(static::ACTIVATE_NONCE);
        $view->plugin = array(
            'name'        => $pluginMetadata->name,
            'version'     => $pluginMetadata->version,
            'isTheme'     => !empty($pluginMetadata->theme),
            'isActivated' => \Piwik\Plugin\Manager::getInstance()->isPluginActivated($pluginMetadata->name)
        );

        return $view->render();
    }

    /**
     * @deprecated
     */
    public function browsePlugins()
    {
        $this->redirectToIndex('Marketplace', 'overview');
    }

    /**
     * @deprecated
     */
    public function browseThemes()
    {
        $this->redirectToIndex('Marketplace', 'overview', null, null, null, array('show' => 'themes'));
    }

    /**
     * @deprecated
     */
    public function userBrowsePlugins()
    {
        $this->redirectToIndex('Marketplace', 'overview', null, null, null, array('mode' => 'user'));
    }

    private function dieIfPluginsAdminIsDisabled()
    {
        if (!CorePluginsAdmin::isPluginsAdminEnabled()) {
            throw new \Exception('Enabling, disabling and uninstalling plugins has been disabled by Piwik admins.
            Please contact your Piwik admins with your request so they can assist you.');
        }
    }

    private function createPluginsOrThemesView($template, $themesOnly)
    {
        Piwik::checkUserHasSuperUserAccess();

        $view = $this->configureView('@CorePluginsAdmin/' . $template);

        $view->updateNonce = Nonce::getNonce(static::UPDATE_NONCE);
        $view->activateNonce = Nonce::getNonce(static::ACTIVATE_NONCE);
        $view->uninstallNonce = Nonce::getNonce(static::UNINSTALL_NONCE);
        $view->deactivateNonce = Nonce::getNonce(static::DEACTIVATE_NONCE);
        $view->installNonce = Nonce::getNonce(static::INSTALL_NONCE);
        $view->pluginsInfo = $this->getPluginsInfo($themesOnly);

        $users = Request::processRequest('UsersManager.getUsers');
        $view->otherUsersCount = count($users) - 1;
        $view->themeEnabled = \Piwik\Plugin\Manager::getInstance()->getThemeEnabled()->getPluginName();

        $view->pluginNamesHavingSettings = $this->getPluginNamesHavingSettingsForCurrentUser();
        $view->isMarketplaceEnabled = Marketplace::isMarketplaceEnabled();
        $view->isPluginsAdminEnabled = CorePluginsAdmin::isPluginsAdminEnabled();
        $view->isMultiServerEnvironment = SettingsPiwik::isMultiServerEnvironment();

        $view->pluginsHavingUpdate    = array();
        $view->marketplacePluginNames = array();

        if (Marketplace::isMarketplaceEnabled()) {
            try {
                $view->marketplacePluginNames = $this->marketplacePlugins->getAvailablePluginNames($themesOnly);

                $pluginsHavingUpdate = $this->marketplacePlugins->getPluginsHavingUpdate(true);
                $themesHavingUpdate  = $this->marketplacePlugins->getPluginsHavingUpdate(false);
                $view->pluginsHavingUpdate    = $pluginsHavingUpdate + $themesHavingUpdate;
            } catch(Exception $e) {
                // curl exec connection error (ie. server not connected to internet)
            }
        }

        return $view;
    }

    public function plugins()
    {
        $view = $this->createPluginsOrThemesView('plugins', $themesOnly = false);
        return $view->render();
    }

    public function themes()
    {
        $view = $this->createPluginsOrThemesView('themes', $themesOnly = true);
        return $view->render();
    }

    protected function configureView($template)
    {
        Piwik::checkUserIsNotAnonymous();

        $view = new View($template);
        $this->setBasicVariablesView($view);

        // If user can manage plugins+themes, display a warning if config not writable
        if (CorePluginsAdmin::isPluginsAdminEnabled()) {
            $this->displayWarningIfConfigFileNotWritable();
        }

        $view->errorMessage = '';

        return $view;
    }

    protected function getPluginsInfo($themesOnly = false)
    {
        $pluginManager = \Piwik\Plugin\Manager::getInstance();
        $plugins = $pluginManager->loadAllPluginsAndGetTheirInfo();

        foreach ($plugins as $pluginName => &$plugin) {

            $plugin['isCorePlugin'] = $pluginManager->isPluginBundledWithCore($pluginName);

            if (!empty($plugin['info']['description'])) {
                $plugin['info']['description'] = $this->translator->translate($plugin['info']['description']);
            }

            if (!isset($plugin['info'])) {

                $suffix = $this->translator->translate('CorePluginsAdmin_PluginNotWorkingAlternative');
                // If the plugin has been renamed, we do not show message to ask user to update plugin
                list($pluginNameRenamed, $methodName) = Request::getRenamedModuleAndAction($pluginName, 'index');
                if ($pluginName != $pluginNameRenamed) {
                    $suffix = "You may uninstall the plugin or manually delete the files in piwik/plugins/$pluginName/";
                }

                $description = '<strong><em>'
                    . $this->translator->translate('CorePluginsAdmin_PluginNotCompatibleWith', array($pluginName, self::getPiwikVersion()))
                    . '</strong><br/>'
                    . $suffix
                    . '</em>';
                $plugin['info'] = array(
                    'description' => $description,
                    'version'     => $this->translator->translate('General_Unknown'),
                    'theme'       => false,
                );
            }
        }

        $pluginsFiltered = $this->keepPluginsOrThemes($themesOnly, $plugins);
        return $pluginsFiltered;
    }

    protected function keepPluginsOrThemes($themesOnly, $plugins)
    {
        $pluginsFiltered = array();
        foreach ($plugins as $name => $thisPlugin) {

            $isTheme = false;
            if (!empty($thisPlugin['info']['theme'])) {
                $isTheme = (bool)$thisPlugin['info']['theme'];
            }
            if (($themesOnly && $isTheme)
                || (!$themesOnly && !$isTheme)
            ) {
                $pluginsFiltered[$name] = $thisPlugin;
            }
        }
        return $pluginsFiltered;
    }

    public function safemode($lastError = array())
    {
        $this->tryToRepairPiwik();

        if (empty($lastError)) {
            $lastError = array(
                'message' => Common::getRequestVar('error_message', null, 'string'),
                'file'    => Common::getRequestVar('error_file', null, 'string'),
                'line'    => Common::getRequestVar('error_line', null, 'integer')
            );
        }

        $outputFormat = Common::getRequestVar('format', 'html', 'string');
        $outputFormat = strtolower($outputFormat);

        if (!empty($outputFormat) && 'html' !== $outputFormat) {

            $errorMessage = $lastError['message'];

            if (Piwik::isUserIsAnonymous()) {
                $errorMessage = 'A fatal error occurred.';
            }

            $response = new \Piwik\API\ResponseBuilder($outputFormat);
            $message  = $response->getResponseException(new Exception($errorMessage));

            return $message;
        }

        if (Common::isPhpCliMode()) { // TODO: I can't find how this will ever get called / safeMode is never set for Console
            throw new Exception("Error: " . var_export($lastError, true));
        }

        $view = new View('@CorePluginsAdmin/safemode');
        $view->lastError   = $lastError;
        $view->isSuperUser = Piwik::hasUserSuperUserAccess();
        $view->isAnonymousUser = Piwik::isUserIsAnonymous();
        $view->plugins         = Plugin\Manager::getInstance()->loadAllPluginsAndGetTheirInfo();
        $view->deactivateNonce = Nonce::getNonce(static::DEACTIVATE_NONCE);
        $view->uninstallNonce  = Nonce::getNonce(static::UNINSTALL_NONCE);
        $view->emailSuperUser  = implode(',', Piwik::getAllSuperUserAccessEmailAddresses());
        $view->piwikVersion    = Version::VERSION;
        $view->showVersion     = !Common::getRequestVar('tests_hide_piwik_version', 0);
        $view->pluginCausesIssue = '';

        if (!empty($lastError['file'])) {
            preg_match('/piwik\/plugins\/(.*)\//', $lastError['file'], $matches);

            if (!empty($matches[1])) {
                $view->pluginCausesIssue = $matches[1];
            }
        }

        return $view->render();
    }

    public function activate($redirectAfter = true)
    {
        $pluginName = $this->initPluginModification(static::ACTIVATE_NONCE);
        $this->dieIfPluginsAdminIsDisabled();

        \Piwik\Plugin\Manager::getInstance()->activatePlugin($pluginName);

        if ($redirectAfter) {
            $plugin = \Piwik\Plugin\Manager::getInstance()->loadPlugin($pluginName);

            $actionToRedirect = 'plugins';
            if ($plugin->isTheme()) {
                $actionToRedirect = 'themes';
            }

            $message = $this->translator->translate('CorePluginsAdmin_SuccessfullyActicated', array($pluginName));
            if (SettingsManager::hasSystemPluginSettingsForCurrentUser($pluginName)) {
                $target   = sprintf('<a href="index.php%s#%s">',
                    Url::getCurrentQueryStringWithParametersModified(array('module' => 'CoreAdminHome', 'action' => 'adminPluginSettings')),
                    $pluginName);
                $message .= ' ' . $this->translator->translate('CorePluginsAdmin_ChangeSettingsPossible', array($target, '</a>'));
            }

            $notification = new Notification($message);
            $notification->raw     = true;
            $notification->title   = $this->translator->translate('General_WellDone');
            $notification->context = Notification::CONTEXT_SUCCESS;
            Notification\Manager::notify('CorePluginsAdmin_PluginActivated', $notification);

            $this->redirectToIndex('CorePluginsAdmin', $actionToRedirect);
        }
    }

    public function deactivate($redirectAfter = true)
    {
        $pluginName = $this->initPluginModification(static::DEACTIVATE_NONCE);
        $this->dieIfPluginsAdminIsDisabled();

        \Piwik\Plugin\Manager::getInstance()->deactivatePlugin($pluginName);
        $this->redirectAfterModification($redirectAfter);
    }

    public function uninstall($redirectAfter = true)
    {
        $pluginName = $this->initPluginModification(static::UNINSTALL_NONCE);
        $this->dieIfPluginsAdminIsDisabled();

        $uninstalled = \Piwik\Plugin\Manager::getInstance()->uninstallPlugin($pluginName);

        if (!$uninstalled) {
            $path = Filesystem::getPathToPiwikRoot() . '/plugins/' . $pluginName . '/';
            $messagePermissions = Filechecks::getErrorMessageMissingPermissions($path);

            $messageIntro = $this->translator->translate("Warning: \"%s\" could not be uninstalled. Piwik did not have enough permission to delete the files in $path. ",
                $pluginName);
            $exitMessage  = $messageIntro . "<br/><br/>" . $messagePermissions;
            $exitMessage .= "<br> Or manually delete this directory (using FTP or SSH access)";

            $ex = new MissingFilePermissionException($exitMessage);
            $ex->setIsHtmlMessage();

            throw $ex;
        }

        $this->redirectAfterModification($redirectAfter);
    }

    protected function initPluginModification($nonceName)
    {
        Piwik::checkUserHasSuperUserAccess();

        $nonce = Common::getRequestVar('nonce', null, 'string');

        if (!Nonce::verifyNonce($nonceName, $nonce)) {
            throw new \Exception($this->translator->translate('General_ExceptionNonceMismatch'));
        }

        Nonce::discardNonce($nonceName);

        $pluginName = Common::getRequestVar('pluginName', null, 'string');

        return $pluginName;
    }

    protected function redirectAfterModification($redirectAfter)
    {
        if ($redirectAfter) {
            Url::redirectToReferrer();
        }
    }

    private function getPluginNamesHavingSettingsForCurrentUser()
    {
        return SettingsManager::getPluginNamesHavingSystemSettings();
    }

    private function tryToRepairPiwik()
    {
        // in case any opcaches etc were not cleared after an update for instance. Might prevent from getting the
        // error again
        try {
            Filesystem::deleteAllCacheOnUpdate();
        } catch (Exception $e) {}
    }

}

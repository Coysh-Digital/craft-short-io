<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\controllers;

use coyshdigital\shortio\helpers\Sections;
use coyshdigital\shortio\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * The plugin settings screen.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class SettingsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Admins only, but allow viewing where admin changes are disabled.
        $this->requireAdmin(false);

        return parent::beforeAction($action);
    }

    /**
     * @return Response
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $overrides = array_keys(Craft::$app->getConfig()->getConfigFromFile('short-io'));
        $readOnly = !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        return $this->renderTemplate('short-io/settings/index.twig', [
            'plugin' => $plugin,
            'settings' => $settings,
            'overrides' => $overrides,
            'readOnly' => $readOnly,
            'domainSuggestions' => $plugin->domains->getSuggestions(),
            'sectionOptions' => Sections::options(),
            'sectionsOverridden' => $settings->isSectionsOverridden(),
        ]);
    }

    /**
     * @return Response|null
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $settings = Craft::$app->getRequest()->getBodyParam('settings', []);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            Craft::$app->getSession()->setError(Craft::t('short-io', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $plugin->getSettings(),
            ]);

            return null;
        }

        // The key or domain may have changed, so the cached list is now suspect.
        $plugin->domains->clearCache();

        Craft::$app->getSession()->setNotice(Craft::t('short-io', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Drops the cached domain list and reloads.
     *
     * @return Response
     */
    public function actionRefreshDomains(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        Plugin::getInstance()->domains->clearCache();
        Plugin::getInstance()->domains->getAll(true);

        Craft::$app->getSession()->setNotice(Craft::t('short-io', 'Domains refreshed.'));

        return $this->redirect('short-io/settings');
    }
}

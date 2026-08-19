<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio;

use coyshdigital\shortio\models\Settings;
use coyshdigital\shortio\services\Client;
use coyshdigital\shortio\services\Domains;
use coyshdigital\shortio\services\Links;
use coyshdigital\shortio\services\Qr;
use coyshdigital\shortio\services\Stats;
use coyshdigital\shortio\variables\ShortIoVariable;
use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Short.io plugin.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Client $client
 * @property-read Domains $domains
 * @property-read Links $links
 * @property-read Qr $qr
 * @property-read Stats $stats
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Plugin extends BasePlugin
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission for seeing short links and their statistics.
     */
    public const PERMISSION_VIEW_LINKS = 'short-io:viewLinks';

    /**
     * @var string The permission for creating, renaming and removing short links.
     */
    public const PERMISSION_MANAGE_LINKS = 'short-io:manageLinks';

    // Static Properties
    // =========================================================================

    /**
     * @var Plugin|null
     */
    public static ?Plugin $plugin = null;

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'coyshdigital\\shortio\\console\\controllers';
        }

        $this->_registerCpRoutes();
        $this->_registerPermissions();
        $this->_registerVariable();

        // Late, so the sections list and the Twig environment both exist, and so
        // that applying project config never triggers an outbound HTTP call.
        Craft::$app->onInit(function() {
            if (!Craft::$app->getIsInstalled() || Craft::$app->getProjectConfig()->getIsApplyingExternalChanges()) {
                return;
            }

            $this->_registerEntryEvents();
        });

        Craft::info(
            Craft::t('short-io', '{name} plugin loaded', ['name' => $this->name]),
            __METHOD__
        );
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser();

        if (!$user->getIsAdmin() && !$user->checkPermission(self::PERMISSION_VIEW_LINKS)) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['subnav'] = [];

        if ($user->getIsAdmin() || $user->checkPermission(self::PERMISSION_VIEW_LINKS)) {
            $item['subnav']['links'] = [
                'label' => Craft::t('short-io', 'Links'),
                'url' => 'short-io/links',
            ];
        }

        if ($user->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('short-io', 'Settings'),
                'url' => 'short-io/settings',
            ];
        }

        return $item;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        // Defer to the dedicated settings controller.
        return Craft::$app->getResponse()->redirect('short-io/settings');
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers the plugin's control panel routes.
     *
     * @return void
     */
    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['short-io'] = 'short-io/links/index';
                $event->rules['short-io/links'] = 'short-io/links/index';
                $event->rules['short-io/settings'] = 'short-io/settings/index';
                $event->rules['short-io/qr/<linkId:(?:link|lnk)_[\w-]+>'] = 'short-io/qr/download';
            }
        );
    }

    /**
     * Registers the plugin's user permissions.
     *
     * @return void
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('short-io', 'Short.io'),
                    'permissions' => [
                        self::PERMISSION_VIEW_LINKS => [
                            'label' => Craft::t('short-io', 'View short links'),
                            'nested' => [
                                self::PERMISSION_MANAGE_LINKS => [
                                    'label' => Craft::t('short-io', 'Create, rename and remove short links'),
                                    'info' => Craft::t('short-io', 'Changes are made on Short.io straight away.'),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Registers the craft.shortIo Twig variable.
     *
     * @return void
     */
    private function _registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('shortIo', ShortIoVariable::class);
            }
        );
    }

    /**
     * Wires the entry lifecycle up to the Links service.
     *
     * @return void
     */
    private function _registerEntryEvents(): void
    {
        Event::on(Entry::class, Element::EVENT_BEFORE_SAVE, function($event) {
            $this->links->handleBeforeSave($event);
        });

        Event::on(Entry::class, Element::EVENT_AFTER_SAVE, function($event) {
            $this->links->handleAfterSave($event);
        });

        Event::on(Entry::class, Element::EVENT_AFTER_DELETE, function($event) {
            $this->links->handleAfterDelete($event);
        });

        Event::on(Entry::class, Element::EVENT_AFTER_RESTORE, function($event) {
            $this->links->handleAfterRestore($event);
        });

        Event::on(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function($event) {
            $this->links->handleDefineSidebarHtml($event);
        });
    }
}

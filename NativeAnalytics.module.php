<?php namespace ProcessWire;

class NativeAnalytics extends WireData implements Module, ConfigurableModule {

    const VERSION = '1.0.19';
    const HITS_TABLE = 'pwna_hits';
    const DAILY_TABLE = 'pwna_daily';
    const SESSIONS_TABLE = 'pwna_sessions';
    const EVENTS_TABLE = 'pwna_events';

    protected $defaults = [
        'trackingEnabled' => 1,
        'respectDnt' => 1,
        'requireConsent' => 0,
        'consentCookieName' => 'pwna_consent',
        'rawRetentionDays' => 90,
        'realtimeWindowMinutes' => 5,
        'ignoreQueryString' => 1,
        'excludeRoles' => ['superuser'],
        'excludePaths' => "/processwire/\n/admin/\n/404/",
        'searchQueryVars' => 'q,s,search',
        'dashboardDefaultRange' => '30d',
        'hashSalt' => '',
        'eventTrackingEnabled' => 1,
        'trackingStorageMode' => 'cookie',
        'privacyWireAutoConsent' => 0,
        'privacyWireStorageKey' => 'privacywire',
        'privacyWireGroups' => 'statistics,marketing',
        'privacyWireConsentCookieMaxAge' => 31536000,
    ];

    public static function getModuleInfo() {
        return [
            'title' => 'NativeAnalytics',
            'summary' => 'Native first-party analytics dashboard for ProcessWire with traffic, compare, exports and event tracking.',
            'version' => self::VERSION,
            'author' => 'Pyxios - Roych (www.pyxios.com)',
            'icon' => 'line-chart',
            'autoload' => true,
            'singular' => true,
            'requires' => ['ProcessWire>=3.0.173', 'PHP>=7.4', 'LazyCron'],
        ];
    }

    public function init() {
        $this->applyDefaults();
        $this->ensureSchema();

        $this->maybeHandleSpecialEndpoints();

        $this->addHookAfter('LazyCron::everyDay', $this, 'handleDailyCron');
        $this->addHookAfter('LazyCron::everyHour', $this, 'handleHourlyCron');
    }

    public function ready() {
        $this->applyDefaults();

        if(!$this->trackingEnabled) return;

        if($this->wire('config')->admin) {
            $this->addHookAfter('ProcessPageEdit::buildForm', $this, 'addPageAnalyticsBox');
            return;
        }

        if($this->shouldTrackCurrentRequest()) {
            $this->trackCurrentRequestServerSide();
        }

        if($this->shouldInjectTrackerCurrentRequest()) {
            $this->addHookAfter('Page::render', $this, 'injectTracker');
        }
    }

    protected function applyDefaults() {
        foreach($this->defaults as $key => $value) {
            if($this->get($key) === null) $this->set($key, $value);
        }
        if(!$this->hashSalt) {
            $this->hashSalt = hash('sha256', $this->wire('config')->userAuthSalt . '|' . __FILE__);
        }
    }


    public function getModuleDirName() {
        return basename(__DIR__);
    }

    public function getAssetUrl($relativePath) {
        return $this->wire('config')->urls->siteModules . $this->getModuleDirName() . '/' . ltrim($relativePath, '/');
    }

    public function getTrackEndpointUrl() {
        return $this->wire('config')->urls->root . 'pwna-track/';
    }

    public function getRealtimeEndpointUrl() {
        return $this->wire('config')->urls->root . 'pwna-realtime/';
    }

    public function install() {
        $this->ensureSchema(true);
        $this->createPermission('nativeanalytics-view', 'View NativeAnalytics');
        $this->createPermission('nativeanalytics-manage', 'Manage NativeAnalytics');
        $this->installDashboardModule();
    }

    public function uninstall() {
        $session = $this->wire('session');
        $session->setFor('NativeAnalytics', 'uninstall_mode', 'main-direct');
        try {
            if((string) $session->getFor('NativeAnalytics', 'uninstall_mode') !== 'dashboard-direct') {
                $this->uninstallDashboardModule();
            }
            $db = $this->wire('database');
            $db->exec("DROP TABLE IF EXISTS `" . self::EVENTS_TABLE . "`");
            $db->exec("DROP TABLE IF EXISTS `" . self::SESSIONS_TABLE . "`");
            $db->exec("DROP TABLE IF EXISTS `" . self::DAILY_TABLE . "`");
            $db->exec("DROP TABLE IF EXISTS `" . self::HITS_TABLE . "`");
            $this->deletePermission('nativeanalytics-view');
            $this->deletePermission('nativeanalytics-manage');
            $this->cleanupDashboardAdminPage();
            $this->cleanupModuleRegistry(['ProcessNativeAnalytics']);
            try {
                $this->wire('modules')->refresh();
            } catch(\Throwable $e) {
                $this->wire('log')->save('native-analytics', 'Modules refresh after uninstall failed: ' . $e->getMessage());
            }
        } finally {
            $session->removeFor('NativeAnalytics', 'uninstall_mode');
        }
    }

    protected function installDashboardModule() {
        $modules = $this->wire('modules');
        if($modules->isInstalled('ProcessNativeAnalytics')) return;
        try {
            if($modules->isInstallable('ProcessNativeAnalytics', true)) {
                $modules->install('ProcessNativeAnalytics');
            } else {
                $this->wire('session')->warning('NativeAnalytics Dashboard could not be installed automatically. Please install ProcessNativeAnalytics from Modules.');
            }
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Automatic dashboard install failed: ' . $e->getMessage());
            $this->wire('session')->warning('NativeAnalytics Dashboard could not be installed automatically. Please install ProcessNativeAnalytics from Modules.');
        }
    }

    protected function uninstallDashboardModule() {
        $modules = $this->wire('modules');
        if(!$modules->isInstalled('ProcessNativeAnalytics')) {
            $this->cleanupDashboardAdminPage();
            $this->cleanupModuleRegistry(['ProcessNativeAnalytics']);
            return;
        }
        try {
            $modules->uninstall('ProcessNativeAnalytics');
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Automatic dashboard uninstall failed: ' . $e->getMessage());
        }
        $this->cleanupDashboardAdminPage();
        $this->cleanupModuleRegistry(['ProcessNativeAnalytics']);
    }

    protected function cleanupDashboardAdminPage() {
        try {
            $pages = $this->wire('pages');
            $adminRoot = (int) $this->wire('config')->adminRootPageID;
            $candidates = [];

            foreach(['native-analytics', 'analytics'] as $name) {
                try {
                    $found = $pages->find('template=admin, include=all, name=' . $name . ', sort=-id');
                    foreach($found as $item) $candidates[$item->id] = $item;
                } catch(\Throwable $e) {
                }
            }

            if($adminRoot) {
                try {
                    $admin = $pages->get($adminRoot);
                    if($admin && $admin->id) {
                        foreach($admin->children('include=all, sort=-id') as $item) {
                            $processName = '';
                            try {
                                $proc = $item->get('process');
                                if(is_object($proc)) {
                                    if(isset($proc->className)) $processName = (string) $proc->className;
                                    elseif(isset($proc->name)) $processName = (string) $proc->name;
                                    else $processName = get_class($proc);
                                } else {
                                    $processName = (string) $proc;
                                }
                            } catch(\Throwable $e) {
                                $processName = '';
                            }
                            if($item->name === 'native-analytics' || ($item->name === 'analytics' && stripos($processName, 'ProcessNativeAnalytics') !== false) || stripos($processName, 'ProcessNativeAnalytics') !== false) {
                                $candidates[$item->id] = $item;
                            }
                        }
                    }
                } catch(\Throwable $e) {
                }
            }

            foreach($candidates as $item) {
                try {
                    foreach($item->children('include=all, sort=-id') as $child) {
                        $child->delete(true);
                    }
                } catch(\Throwable $e) {
                }
                try {
                    if(method_exists($item, 'setOutputFormatting')) $item->setOutputFormatting(false);
                    if(defined('ProcessWire\Page::statusSystem') && isset($item->status)) {
                        $item->status = $item->status & ~Page::statusSystem;
                    }
                    $item->delete(true);
                } catch(\Throwable $e) {
                    $this->wire('log')->save('native-analytics', 'Dashboard admin page delete failed for #' . (int) $item->id . ': ' . $e->getMessage());
                }
            }
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Dashboard admin page cleanup failed: ' . $e->getMessage());
        }
    }

    protected function cleanupModuleRegistry(array $classes) {
        try {
            $db = $this->wire('database');
            $prefix = (string) ($this->wire('config')->dbTablePrefix ?? '');
            $table = $prefix . 'modules';
            foreach($classes as $class) {
                $stmt = $db->prepare('DELETE FROM `' . $table . '` WHERE `class` = :class');
                $stmt->execute([':class' => $class]);
            }
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Module registry cleanup failed: ' . $e->getMessage());
        }
    }

    protected function ensureSchema($force = false) {
        static $done = false;
        if($done && !$force) return;
        $db = $this->wire('database');

        $db->exec("CREATE TABLE IF NOT EXISTS `" . self::HITS_TABLE . "` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `created_at` DATETIME NOT NULL,
            `created_date` DATE NOT NULL,
            `created_hour` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `page_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `page_title` VARCHAR(255) NOT NULL DEFAULT '',
            `template` VARCHAR(128) NOT NULL DEFAULT '',
            `url` VARCHAR(767) NOT NULL,
            `path` VARCHAR(767) NOT NULL,
            `path_hash` CHAR(32) NOT NULL,
            `referrer_host` VARCHAR(191) NOT NULL DEFAULT '',
            `referrer_url` VARCHAR(767) NOT NULL DEFAULT '',
            `search_term` VARCHAR(255) NOT NULL DEFAULT '',
            `utm_source` VARCHAR(191) NOT NULL DEFAULT '',
            `utm_medium` VARCHAR(191) NOT NULL DEFAULT '',
            `utm_campaign` VARCHAR(191) NOT NULL DEFAULT '',
            `device_type` VARCHAR(32) NOT NULL DEFAULT '',
            `browser` VARCHAR(64) NOT NULL DEFAULT '',
            `os` VARCHAR(64) NOT NULL DEFAULT '',
            `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
            `visitor_hash` CHAR(64) NOT NULL,
            `session_hash` CHAR(64) NOT NULL,
            `ip_hash` CHAR(64) NOT NULL,
            `is_bot` TINYINT(1) NOT NULL DEFAULT 0,
            `status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 200,
            PRIMARY KEY (`id`),
            KEY `created_at` (`created_at`),
            KEY `created_date` (`created_date`),
            KEY `page_id` (`page_id`),
            KEY `template` (`template`),
            KEY `path_hash` (`path_hash`),
            KEY `visitor_hash` (`visitor_hash`),
            KEY `session_hash` (`session_hash`),
            KEY `referrer_host` (`referrer_host`),
            KEY `search_term` (`search_term`),
            KEY `status_code` (`status_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `" . self::DAILY_TABLE . "` (
            `day` DATE NOT NULL,
            `page_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `page_title` VARCHAR(255) NOT NULL DEFAULT '',
            `template` VARCHAR(128) NOT NULL DEFAULT '',
            `path` VARCHAR(767) NOT NULL,
            `path_hash` CHAR(32) NOT NULL,
            `views` INT UNSIGNED NOT NULL DEFAULT 0,
            `uniques` INT UNSIGNED NOT NULL DEFAULT 0,
            `sessions` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`day`, `path_hash`, `page_id`),
            KEY `page_id` (`page_id`),
            KEY `template` (`template`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `" . self::SESSIONS_TABLE . "` (
            `session_hash` CHAR(64) NOT NULL,
            `visitor_hash` CHAR(64) NOT NULL,
            `first_seen_at` DATETIME NOT NULL,
            `last_seen_at` DATETIME NOT NULL,
            `page_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `page_title` VARCHAR(255) NOT NULL DEFAULT '',
            `template` VARCHAR(128) NOT NULL DEFAULT '',
            `current_url` VARCHAR(767) NOT NULL DEFAULT '',
            `current_path` VARCHAR(767) NOT NULL DEFAULT '',
            `current_path_hash` CHAR(32) NOT NULL,
            `referrer_host` VARCHAR(191) NOT NULL DEFAULT '',
            `referrer_url` VARCHAR(767) NOT NULL DEFAULT '',
            `device_type` VARCHAR(32) NOT NULL DEFAULT '',
            `browser` VARCHAR(64) NOT NULL DEFAULT '',
            `os` VARCHAR(64) NOT NULL DEFAULT '',
            `hit_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 200,
            PRIMARY KEY (`session_hash`),
            KEY `last_seen_at` (`last_seen_at`),
            KEY `page_id` (`page_id`),
            KEY `template` (`template`),
            KEY `current_path_hash` (`current_path_hash`),
            KEY `visitor_hash` (`visitor_hash`),
            KEY `status_code` (`status_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `" . self::EVENTS_TABLE . "` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `created_at` DATETIME NOT NULL,
            `created_date` DATE NOT NULL,
            `created_hour` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `page_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `page_title` VARCHAR(255) NOT NULL DEFAULT '',
            `template` VARCHAR(128) NOT NULL DEFAULT '',
            `url` VARCHAR(767) NOT NULL DEFAULT '',
            `path` VARCHAR(767) NOT NULL DEFAULT '',
            `path_hash` CHAR(32) NOT NULL,
            `event_group` VARCHAR(64) NOT NULL DEFAULT '',
            `event_name` VARCHAR(128) NOT NULL DEFAULT '',
            `event_label` VARCHAR(255) NOT NULL DEFAULT '',
            `event_target` VARCHAR(767) NOT NULL DEFAULT '',
            `extra_json` MEDIUMTEXT NULL,
            `visitor_hash` CHAR(64) NOT NULL,
            `session_hash` CHAR(64) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `created_at` (`created_at`),
            KEY `created_date` (`created_date`),
            KEY `page_id` (`page_id`),
            KEY `template` (`template`),
            KEY `path_hash` (`path_hash`),
            KEY `event_group` (`event_group`),
            KEY `event_name` (`event_name`),
            KEY `visitor_hash` (`visitor_hash`),
            KEY `session_hash` (`session_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


        $done = true;
    }

    protected function createPermission($name, $title) {
        $permissions = $this->wire('permissions');
        if($permissions->get($name)->id) return;
        $permission = new Permission();
        $permission->name = $name;
        $permission->title = $title;
        $permission->save();
    }

    protected function deletePermission($name) {
        $permission = $this->wire('permissions')->get($name);
        if($permission && $permission->id) $permission->delete();
    }

    public static function getModuleConfigInputfields(array $data) {
        $wire = wire();
        $defaults = [
            'trackingEnabled' => 1,
            'respectDnt' => 1,
            'requireConsent' => 0,
            'consentCookieName' => 'pwna_consent',
            'rawRetentionDays' => 90,
            'realtimeWindowMinutes' => 5,
            'ignoreQueryString' => 1,
            'excludeRoles' => ['superuser'],
            'excludePaths' => "/processwire/\n/admin/\n/404/",
            'searchQueryVars' => 'q,s,search',
            'dashboardDefaultRange' => '30d',
            'hashSalt' => '',
            'eventTrackingEnabled' => 1,
            'trackingStorageMode' => 'cookie',
            'privacyWireAutoConsent' => 0,
            'privacyWireStorageKey' => 'privacywire',
            'privacyWireGroups' => 'statistics,marketing',
            'privacyWireConsentCookieMaxAge' => 31536000,
            'displayDateFormat' => 'site_default',
        ];
        $data = array_replace($defaults, $data);
        $wrapper = new InputfieldWrapper();

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'trackingEnabled';
        $f->label = 'Enable tracking';
        $f->checked = !empty($data['trackingEnabled']);
        $f->description = 'Track page views, sessions, current visitors and the rest of the dashboard data. Leave this enabled for normal use.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'eventTrackingEnabled';
        $f->label = 'Enable event tracking for forms, downloads, contact links and custom CTA events';
        $f->checked = !empty($data['eventTrackingEnabled']);
        $f->showIf = 'trackingEnabled=1';
        $f->description = 'Track engagement events such as form submits, downloads, contact links, outbound links and custom CTA events. Requires global tracking to be enabled.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldSelect');
        $f->name = 'trackingStorageMode';
        $f->label = 'Visitor/session storage mode';
        $f->addOptions([
            'cookie' => 'Cookie-based visitor/session IDs',
            'cookieless' => 'Cookie-less / no browser storage IDs',
        ]);
        $f->value = in_array((string) ($data['trackingStorageMode'] ?? 'cookie'), ['cookie', 'cookieless'], true) ? (string) $data['trackingStorageMode'] : 'cookie';
        $f->showIf = 'trackingEnabled=1';
        $f->description = 'Cookie-based mode is more accurate. Cookie-less mode does not set NativeAnalytics visitor/session cookies and does not use browser storage for IDs; unique/session numbers are approximate.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'respectDnt';
        $f->label = 'Respect Do Not Track';
        $f->checked = !empty($data['respectDnt']);
        $f->showIf = 'trackingEnabled=1';
        $f->description = 'If a visitor sends the browser Do Not Track signal, analytics tracking will be skipped.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'requireConsent';
        $f->label = 'Require consent cookie';
        $f->checked = !empty($data['requireConsent']);
        $f->showIf = 'trackingEnabled=1';
        $f->description = 'Only track visitors after the selected consent cookie exists. Enable this if your site uses a cookie consent banner.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldText');
        $f->name = 'consentCookieName';
        $f->label = 'Consent cookie name';
        $f->value = $data['consentCookieName'] ?? 'pwna_consent';
        $f->showIf = 'trackingEnabled=1, requireConsent=1';
        $f->description = 'Cookie name that must be present before tracking starts when consent is required.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'privacyWireAutoConsent';
        $f->label = 'Enable PrivacyWire localStorage consent helper';
        $f->checked = !empty($data['privacyWireAutoConsent']);
        $f->showIf = 'trackingEnabled=1, requireConsent=1';
        $f->description = 'Reads PrivacyWire consent from localStorage, sets/unsets the selected NativeAnalytics consent cookie, and tracks the current page once consent is granted.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldText');
        $f->name = 'privacyWireStorageKey';
        $f->label = 'PrivacyWire localStorage key';
        $f->value = $data['privacyWireStorageKey'] ?? 'privacywire';
        $f->showIf = 'trackingEnabled=1, requireConsent=1, privacyWireAutoConsent=1';
        $f->description = 'Default PrivacyWire key is usually privacywire.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldText');
        $f->name = 'privacyWireGroups';
        $f->label = 'PrivacyWire groups that allow analytics';
        $f->value = $data['privacyWireGroups'] ?? 'statistics,marketing';
        $f->showIf = 'trackingEnabled=1, requireConsent=1, privacyWireAutoConsent=1';
        $f->description = 'Comma-separated PrivacyWire cookieGroups that should enable NativeAnalytics, for example statistics or statistics,marketing.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldInteger');
        $f->name = 'privacyWireConsentCookieMaxAge';
        $f->label = 'Consent cookie lifetime (seconds)';
        $f->value = (int) ($data['privacyWireConsentCookieMaxAge'] ?? 31536000);
        $f->min = 3600;
        $f->showIf = 'trackingEnabled=1, requireConsent=1, privacyWireAutoConsent=1';
        $f->description = 'How long the NativeAnalytics consent cookie should stay valid after PrivacyWire consent is granted. Default is one year.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldInteger');
        $f->name = 'rawRetentionDays';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Raw hits retention (days)';
        $f->value = (int) ($data['rawRetentionDays'] ?? 90);
        $f->min = 7;
        $f->description = 'How long raw hit records are kept before old entries can be purged. Aggregated daily data remains available.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldInteger');
        $f->name = 'realtimeWindowMinutes';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Current visitors window (minutes)';
        $f->value = (int) ($data['realtimeWindowMinutes'] ?? 5);
        $f->min = 1;
        $f->max = 60;
        $f->description = 'How many recent minutes should count as an active current visitor in the dashboard.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'ignoreQueryString';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Ignore query strings in stored paths';
        $f->checked = !empty($data['ignoreQueryString']);
        $f->description = 'Recommended. Store canonical page paths without URL query strings such as ?utm= or ?it= so page stats stay grouped correctly.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldAsmSelect');
        $f->name = 'excludeRoles';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Exclude logged-in roles from tracking';
        foreach($wire->roles as $role) {
            $f->addOption($role->name, $role->title ?: $role->name);
        }
        $f->value = $data['excludeRoles'] ?? ['superuser'];
        $f->description = 'Logged-in users with these roles will be ignored by analytics tracking.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldTextarea');
        $f->name = 'excludePaths';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Excluded path prefixes';
        $f->rows = 5;
        $f->value = $data['excludePaths'] ?? "/processwire/\n/admin/\n/404/";
        $f->description = 'One path prefix per line. Requests that start with any of these prefixes will not be tracked.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldText');
        $f->name = 'searchQueryVars';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Internal search query parameters';
        $f->value = $data['searchQueryVars'] ?? 'q,s,search';
        $f->description = 'Comma-separated query parameter names that should be treated as internal site search terms.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldSelect');
        $f->name = 'dashboardDefaultRange';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Default dashboard range';
        $f->addOptions(['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days']);
        $f->value = $data['dashboardDefaultRange'] ?? '30d';
        $f->description = 'Default date range shown when opening the analytics dashboard.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldSelect');
        $f->name = 'displayDateFormat';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Display date format';
        $f->description = 'Choose how dates should be shown in the analytics dashboard.';
        $f->addOptions([
            'site_default' => 'Site default',
            'l, j F Y' => 'Tuesday, 14 April 2026 [l, j F Y]',
            'j F Y' => '14 April 2026 [j F Y]',
            'd-M-Y' => '14-Apr-2026 [d-M-Y]',
            'dMy' => '14Apr26 [dMy]',
            'd/m/Y' => '14/04/2026 [d/m/Y]',
            'd.m.Y' => '14.04.2026 [d.m.Y]',
            'd/m/y' => '14/04/26 [d/m/y]',
            'd.m.y' => '14.04.26 [d.m.y]',
            'j/n/Y' => '14/4/2026 [j/n/Y]',
            'j.n.Y' => '14.4.2026 [j.n.Y]',
            'j/n/y' => '14/4/26 [j/n/y]',
            'j.n.y' => '14.4.26 [j.n.y]',
            'Y-m-d' => '2026-04-14 [Y-m-d]',
            'Y/m/d' => '2026/04/14 [Y/m/d]',
            'Y.n.j' => '2026.4.14 [Y.n.j]',
            'Y/n/j' => '2026/4/14 [Y/n/j]',
            'Y F j' => '2026 April 14 [Y F j]',
            'Y-M-j, l' => '2026-Apr-14, Tuesday [Y-M-j, l]',
            'Y-M-j' => '2026-Apr-14 [Y-M-j]',
            'YMj' => '2026Apr14 [YMj]',
            'l, F j, Y' => 'Tuesday, April 14, 2026 [l, F j, Y]',
            'F j, Y' => 'April 14, 2026 [F j, Y]',
            'M j, Y' => 'Apr 14, 2026 [M j, Y]',
            'm/d/Y' => '04/14/2026 [m/d/Y]',
            'm.d.Y' => '04.14.2026 [m.d.Y]',
            'm/d/y' => '04/14/26 [m/d/y]',
            'm.d.y' => '04.14.26 [m.d.y]',
            'n/j/Y' => '4/14/2026 [n/j/Y]',
            'n.j.Y' => '4.14.2026 [n.j.Y]',
            'n/j/y' => '4/14/26 [n/j/y]',
            'n.j.y' => '4.14.26 [n.j.y]',
        ]);
        $f->value = $data['displayDateFormat'] ?? 'site_default';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldText');
        $f->name = 'hashSalt';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Custom hash salt (optional)';
        $f->value = $data['hashSalt'] ?? '';
        $f->description = 'Advanced option. Usually best left empty. A custom salt changes how anonymous visitor and session hashes are generated for this installation.';
        $wrapper->add($f);

        return $wrapper;
    }

    public function shouldTrackCurrentRequest() {
        $config = $this->wire('config');
        $input = $this->wire('input');
        $page = $this->wire('page');

        if($config->admin || $config->ajax) return false;
        if($input->requestMethod() !== 'GET') return false;
        if((int) $input->get('pwna_event') === 1) return false;
        if($this->isRoleExcluded()) return false;
        if(!$page || !$page->id) return false;
        if($page->template && $page->template->name === 'admin') return false;
        if($this->isIgnorableRequestForPageview()) return false;
        if($this->requireConsent && !$this->hasConsentCookie()) return false;

        foreach($this->getExcludedPathPrefixes() as $prefix) {
            if(strpos($page->path(), $prefix) === 0) return false;
        }

        return true;
    }

    protected function shouldInjectTrackerCurrentRequest() {
        $config = $this->wire('config');
        $input = $this->wire('input');
        $page = $this->wire('page');

        if($config->admin || $config->ajax) return false;
        if($input->requestMethod() !== 'GET') return false;
        if((int) $input->get('pwna_event') === 1) return false;
        if($this->isRoleExcluded()) return false;
        if(!$page || !$page->id) return false;
        if($page->template && $page->template->name === 'admin') return false;
        if($this->isIgnorableRequestForPageview()) return false;

        foreach($this->getExcludedPathPrefixes() as $prefix) {
            if(strpos($page->path(), $prefix) === 0) return false;
        }

        return true;
    }

    protected function hasConsentCookie() {
        $name = trim((string) $this->consentCookieName);
        if($name === '') return true;
        return isset($_COOKIE[$name]) && (string) $_COOKIE[$name] !== '';
    }

    protected function isIgnorableRequestForPageview() {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $requestPath = $this->normalizePath((string) parse_url($requestUri, PHP_URL_PATH));
        $basename = basename($requestPath);

        if($basename === '') return false;

        if(preg_match('/\.(?:css|js|map|json|xml|txt|ico|png|jpe?g|gif|svg|webp|avif|bmp|woff2?|ttf|eot|otf|mp4|webm|mp3|wav|ogg|pdf|zip|rar|7z|gz|tar|csv|docx?|xlsx?|pptx?)$/i', $basename)) {
            return true;
        }

        if(preg_match('/^(?:favicon\.ico|robots\.txt|site\.webmanifest|browserconfig\.xml|manifest\.json|apple-touch-icon(?:-precomposed)?(?:-\d+x\d+)?\.png)$/i', $basename)) {
            return true;
        }

        $accept = isset($_SERVER['HTTP_ACCEPT']) ? mb_strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';
        if($accept !== '' && strpos($accept, 'text/html') === false && strpos($accept, 'application/xhtml+xml') === false && strpos($accept, '*/*') === false) {
            return true;
        }

        return false;
    }

    public function injectTracker(HookEvent $event) {
        $html = (string) $event->return;
        if(!$html) return;
        if(stripos($html, '</body>') === false && stripos($html, '</html>') === false) return;
        if(stripos($html, 'data-pwna-tracker="1"') !== false) return;

        $page = $event->object;
        if(!$page instanceof Page || !$page->id) return;

        $payload = [
            'trackEndpoint' => $this->getCurrentPageEventEndpointUrl(),
            'path' => $this->getRequestPathForStorage(),
            'pageId' => (int) $page->id,
            'pageTitle' => (string) $page->get('title'),
            'template' => $page->template ? (string) $page->template->name : '',
            'statusCode' => $this->detectStatusCode($page),
            'consentRequired' => (bool) $this->requireConsent,
            'consentCookieName' => (string) $this->consentCookieName,
            'respectDnt' => (bool) $this->respectDnt,
            'autoTrack' => false,
            'needsClientPageview' => (bool) ($this->requireConsent && !$this->hasConsentCookie()),
            'eventTracking' => (bool) $this->eventTrackingEnabled,
            'storageMode' => $this->getTrackingStorageMode(),
            'privacyWireAutoConsent' => (bool) $this->privacyWireAutoConsent,
            'privacyWireStorageKey' => (string) $this->privacyWireStorageKey,
            'privacyWireGroups' => $this->getPrivacyWireGroups(),
            'consentCookieMaxAge' => max(3600, (int) $this->privacyWireConsentCookieMaxAge),
        ];

        $scriptUrl = $this->getAssetUrl('assets/tracker.js') . '?v=' . rawurlencode(self::VERSION);
        $injected = "\n<script>window.PWNA_CONFIG = " . json_encode($payload) . ";</script>\n";
        $injected .= '<script src="' . $this->wire('sanitizer')->entities($scriptUrl) . '" data-pwna-tracker="1" defer></script>' . "\n";

        if(stripos($html, '</body>') !== false) {
            $event->return = preg_replace('~</body>~i', $injected . '</body>', $html, 1);
        } else {
            $event->return = $html . $injected;
        }
    }

    protected function maybeHandleSpecialEndpoints() {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if($requestUri === '') return;
        $requestPath = (string) parse_url($requestUri, PHP_URL_PATH);
        $normalized = rtrim($this->normalizePath($requestPath), '/');
        if($normalized === '/pwna-track') {
            $this->handleTrackingRequest();
        }
        if($normalized === '/pwna-realtime') {
            $this->handleRealtimeRequest();
        }
        if((int) $this->wire('input')->get('pwna_event') === 1) {
            $this->handleTrackingRequest();
        }
    }

    public function handleTrackRoute(HookEvent $event) {
        $this->handleTrackingRequest();
    }

    public function handleRealtimeRoute(HookEvent $event) {
        $this->handleRealtimeRequest();
    }
    protected function handleRealtimeRequest() {
        if(!$this->wire('user')->hasPermission('nativeanalytics-view')) {
            $this->sendTrackingResponse(403, ['ok' => false, 'message' => 'Forbidden']);
        }
        $input = $this->wire('input');
        $days = max(1, min(365, (int) ($input->get('range_days') ?: 30)));
        $minutes = max(1, min(60, (int) ($input->get('minutes') ?: (int) $this->realtimeWindowMinutes)));
        $fromDate = trim((string) $input->get('from_date'));
        $toDate = trim((string) $input->get('to_date'));
        $rangeSpec = ($fromDate !== '' || $toDate !== '') ? $this->getDateRangeBetween($fromDate, $toDate, $days) : $this->getDateRangeForDays($days);

        $filters = [];
        $pageId = (int) $input->get('page_id');
        $template = $this->wire('sanitizer')->name($input->get('template'));
        if($pageId > 0) $filters['page_id'] = $pageId;
        if($template !== '') $filters['template'] = $template;

        $payload = [
            'ok' => true,
            'summary' => $this->getSummary($rangeSpec, $filters),
            'current' => $this->getCurrentVisitorsSummary($minutes, $filters),
            'summary404' => $this->get404Summary($rangeSpec),
            'sessionQuality' => $this->getSessionQuality($rangeSpec, $filters),
            'health' => $this->getHealthSnapshot(),
            'currentVisitors' => $this->getCurrentVisitors($minutes, 25, $filters),
            'minutes' => $minutes,
            'ts' => date('c'),
        ];
        $this->sendTrackingResponse(200, $payload);
    }

    protected function trackCurrentRequestServerSide() {
        static $done = false;
        if($done) return;
        $done = true;

        $page = $this->wire('page');
        if(!$page || !$page->id) return;

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
        if($this->isBotUserAgent($ua)) return;
        if($this->respectDnt && isset($_SERVER['HTTP_DNT']) && (string) $_SERVER['HTTP_DNT'] === '1') return;

        $ids = $this->getOrCreateTrackingIds();
        $statusCode = $this->detectStatusCode($page);
        $path = $statusCode === 404 ? $this->getRequestedPathWithoutQuery() : $this->getCanonicalPagePath($page);
        $url = $this->trimValue($this->getCurrentRequestUrl(), 767);
        $referrerUrl = isset($_SERVER['HTTP_REFERER']) ? trim((string) $_SERVER['HTTP_REFERER']) : '';
        $referrerHost = '';
        if($referrerUrl !== '') {
            $host = parse_url($referrerUrl, PHP_URL_HOST);
            $referrerHost = is_string($host) ? mb_strtolower($host) : '';
        }
        $queryVars = $this->wire('input')->get->getArray();
        $searchTerm = $this->extractSearchTermFromArray($queryVars);
        $device = $this->parseUserAgent($ua);
        $ip = $this->getClientIp();
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');

        $pageId = $statusCode === 404 ? 0 : (int) $page->id;
        $pageTitle = $statusCode === 404 ? '404' : $this->cleanTextValue($page->get('title'), 255);
        $template = $statusCode === 404 ? '404' : ($page->template ? $page->template->name : '');

        $row = [
            'created_at' => $now,
            'created_date' => $today,
            'created_hour' => (int) date('G'),
            'page_id' => $pageId,
            'page_title' => $this->trimValue($pageTitle, 255),
            'template' => $this->trimValue($template, 128),
            'url' => $url,
            'path' => $this->trimValue($path, 767),
            'path_hash' => md5($path),
            'referrer_host' => $this->trimValue($referrerHost, 191),
            'referrer_url' => $this->trimValue($referrerUrl, 767),
            'search_term' => $this->trimValue($searchTerm, 255),
            'utm_source' => $this->trimValue((string) ($queryVars['utm_source'] ?? ''), 191),
            'utm_medium' => $this->trimValue((string) ($queryVars['utm_medium'] ?? ''), 191),
            'utm_campaign' => $this->trimValue((string) ($queryVars['utm_campaign'] ?? ''), 191),
            'device_type' => $this->trimValue($device['device_type'], 32),
            'browser' => $this->trimValue($device['browser'], 64),
            'os' => $this->trimValue($device['os'], 64),
            'user_agent' => $this->trimValue($ua, 255),
            'visitor_hash' => $this->hashValue($ids['visitor_id']),
            'session_hash' => $this->hashValue($ids['session_id']),
            'ip_hash' => $this->hashValue($ip),
            'is_bot' => 0,
            'status_code' => $statusCode,
        ];

        try {
            $this->insert(self::HITS_TABLE, $row);
            $this->upsertRealtimeSession($row);
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Server-side tracking failed: ' . $e->getMessage());
        }
    }

    protected function getOrCreateTrackingIds() {
        if($this->isCookielessMode()) return $this->getCookielessTrackingIds();

        $visitorId = isset($_COOKIE['pwna_vid']) ? trim((string) $_COOKIE['pwna_vid']) : '';
        $sessionId = isset($_COOKIE['pwna_sid']) ? trim((string) $_COOKIE['pwna_sid']) : '';
        if($visitorId === '') {
            $visitorId = $this->createTrackingId('v');
            $this->setTrackingCookie('pwna_vid', $visitorId, time() + 31536000);
            $_COOKIE['pwna_vid'] = $visitorId;
        }
        if($sessionId === '') {
            $sessionId = $this->createTrackingId('s');
        }
        $this->setTrackingCookie('pwna_sid', $sessionId, time() + 7200);
        $_COOKIE['pwna_sid'] = $sessionId;
        return ['visitor_id' => $visitorId, 'session_id' => $sessionId];
    }

    protected function getTrackingStorageMode() {
        $mode = (string) $this->trackingStorageMode;
        return in_array($mode, ['cookie', 'cookieless'], true) ? $mode : 'cookie';
    }

    protected function isCookielessMode() {
        return $this->getTrackingStorageMode() === 'cookieless';
    }

    protected function getCookielessTrackingIds() {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
        $lang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? trim((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']) : '';
        $base = $this->getClientIp() . '|' . $ua . '|' . $lang;
        $sessionSlot = (string) floor(time() / 1800);
        return [
            'visitor_id' => 'cl_v_' . date('Ymd') . '_' . hash('sha256', $base),
            'session_id' => 'cl_s_' . $sessionSlot . '_' . hash('sha256', $base),
        ];
    }

    protected function resolveIncomingTrackingIds(array $payload) {
        $visitorId = trim((string) ($payload['visitorId'] ?? ''));
        $sessionId = trim((string) ($payload['sessionId'] ?? ''));
        if(($visitorId === '' || $sessionId === '') && $this->isCookielessMode()) {
            return $this->getCookielessTrackingIds();
        }
        return ['visitor_id' => $visitorId, 'session_id' => $sessionId];
    }

    protected function createTrackingId($prefix) {
        return $prefix . '_' . bin2hex(random_bytes(16));
    }

    protected function setTrackingCookie($name, $value, $expires) {
        $path = rtrim((string) $this->wire('config')->urls->root, '/');
        if($path === '') $path = '/';
        setcookie($name, $value, [
            'expires' => (int) $expires,
            'path' => $path,
            'secure' => $this->wire('config')->https,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    protected function getCurrentRequestUrl() {
        $scheme = $this->wire('config')->https ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        return $scheme . '://' . $host . $uri;
    }

    protected function getCurrentPageEventEndpointUrl() {
        $url = $this->getCurrentRequestUrl();
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'pwna_event=1';
    }

    protected function cleanTextValue($value, $maxLen = 255) {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = preg_replace('/\s+/u', ' ', trim((string) $value));
        return $this->trimValue($value, (int) $maxLen);
    }

    protected function is404Page(Page $page) {
        if(!$page || !$page->id) return true;
        $config = $this->wire('config');
        $http404PageID = isset($config->http404PageID) ? (int) $config->http404PageID : 0;
        if($http404PageID > 0 && (int) $page->id === $http404PageID) return true;
        $templateName = $page->template ? (string) $page->template->name : '';
        if($templateName !== '' && in_array(mb_strtolower($templateName), ['404', 'http404'], true)) return true;
        return false;
    }

    protected function getCanonicalPagePath(Page $page = null) {
        if(!$page || !$page->id) return '/';
        return $this->normalizePath((string) $page->path());
    }

    protected function getRequestedPathWithoutQuery() {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        return $this->normalizePath((string) parse_url($requestUri, PHP_URL_PATH));
    }

    protected function normalizeAnalyticsRowForDisplay(array $row, $pathKey = 'path') {
        $pageId = (int) ($row['page_id'] ?? 0);
        if($pageId > 0) {
            $page = $this->wire('pages')->get($pageId);
            if($page && $page->id) {
                $canonicalPath = $this->getCanonicalPagePath($page);
                $row['page_title'] = $this->cleanTextValue($page->get('title'), 255);
                if(isset($row[$pathKey])) $row[$pathKey] = $canonicalPath;
                if(isset($row['path'])) $row['path'] = $canonicalPath;
                if(isset($row['current_path'])) $row['current_path'] = $canonicalPath;
                if(isset($row['template']) && $page->template) $row['template'] = (string) $page->template->name;
                if(isset($row['status_code']) && !$this->is404Page($page)) $row['status_code'] = 200;
            }
        } else {
            if(isset($row[$pathKey])) $row[$pathKey] = $this->normalizePath((string) $row[$pathKey]);
            if(isset($row['path'])) $row['path'] = $this->normalizePath((string) $row['path']);
            if(isset($row['current_path'])) $row['current_path'] = $this->normalizePath((string) $row['current_path']);
            if(isset($row['page_title'])) $row['page_title'] = $this->cleanTextValue((string) $row['page_title'], 255);
        }
        if(isset($row['created_at'])) $row['created_at'] = $this->formatDisplayDateTime($row['created_at']);
        if(isset($row['first_seen_at'])) $row['first_seen_at'] = $this->formatDisplayDateTime($row['first_seen_at']);
        if(isset($row['last_seen_at'])) $row['last_seen_at'] = $this->formatDisplayDateTime($row['last_seen_at']);
        if(isset($row['created_date'])) $row['created_date'] = $this->formatDisplayDate($row['created_date']);
        return $row;
    }

    protected function detectStatusCode(Page $page) {
        if($this->is404Page($page)) return 404;
        if($page && $page->id && (!$page->template || $page->template->name !== 'admin')) return 200;
        $code = (int) http_response_code();
        if($code < 100) $code = 200;
        return $code;
    }

    protected function handleTrackingRequest() {
        if(!$this->trackingEnabled) $this->sendTrackingResponse(204);
        $requestMethod = strtoupper((string) $this->wire('input')->requestMethod());
        if($requestMethod !== 'POST' && $requestMethod !== 'GET') {
            $this->sendTrackingResponse(405, ['ok' => false, 'message' => 'Method not allowed']);
        }

        $payload = $this->getIncomingPayload();
        if(($payload['type'] ?? '') === 'event') {
            $this->handleEventTrackingRequest($payload);
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
        if($this->isBotUserAgent($ua)) $this->sendTrackingResponse(204);
        if($this->respectDnt && isset($_SERVER['HTTP_DNT']) && (string) $_SERVER['HTTP_DNT'] === '1') $this->sendTrackingResponse(204);
        if($this->requireConsent && !$this->hasConsentCookie()) $this->sendTrackingResponse(204);

        $ids = $this->resolveIncomingTrackingIds($payload);
        $visitorId = $ids['visitor_id'];
        $sessionId = $ids['session_id'];
        if($visitorId === '' || $sessionId === '') {
            $this->sendTrackingResponse(422, ['ok' => false, 'message' => 'Missing visitor or session id']);
        }

        $page = $this->wire('page');
        $statusCode = isset($payload['statusCode']) ? max(100, min(599, (int) $payload['statusCode'])) : 200;
        if($page && $page->id && !$this->is404Page($page)) {
            $statusCode = 200;
        }
        $path = $statusCode === 404 ? $this->getRequestedPathWithoutQuery() : $this->getRequestPathForStorage();
        $url = $this->trimValue((string) ($payload['url'] ?? $this->getCurrentRequestUrl()), 767);

        $pageId = max(0, (int) ($payload['pageId'] ?? 0));
        $pageTitle = $this->cleanTextValue((string) ($payload['pageTitle'] ?? ''), 255);
        $template = trim((string) ($payload['template'] ?? ''));
        if($statusCode === 404) {
            $template = '404';
            $pageId = 0;
            $pageTitle = '404';
        } elseif($page && $page->id && $page->template && $page->template->name !== 'admin') {
            $pageId = (int) $page->id;
            $pageTitle = $this->cleanTextValue((string) $page->get('title'), 255);
            if($template === '') $template = $page->template->name;
            $path = $this->getCanonicalPagePath($page);
        }

        $referrerUrl = trim((string) ($payload['referrer'] ?? ''));
        $referrerHost = '';
        if($referrerUrl !== '') {
            $host = parse_url($referrerUrl, PHP_URL_HOST);
            $referrerHost = is_string($host) ? mb_strtolower($host) : '';
        }

        $queryVars = is_array($payload['queryVars'] ?? null) ? $payload['queryVars'] : [];
        $searchTerm = $this->extractSearchTermFromArray($queryVars);
        $device = $this->parseUserAgent($ua);
        $ip = $this->getClientIp();
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');

        $row = [
            'created_at' => $now,
            'created_date' => $today,
            'created_hour' => (int) date('G'),
            'page_id' => $pageId,
            'page_title' => $this->trimValue($pageTitle, 255),
            'template' => $this->trimValue($template, 128),
            'url' => $url,
            'path' => $this->trimValue($path, 767),
            'path_hash' => md5($path),
            'referrer_host' => $this->trimValue($referrerHost, 191),
            'referrer_url' => $this->trimValue($referrerUrl, 767),
            'search_term' => $this->trimValue($searchTerm, 255),
            'utm_source' => $this->trimValue((string) ($queryVars['utm_source'] ?? ''), 191),
            'utm_medium' => $this->trimValue((string) ($queryVars['utm_medium'] ?? ''), 191),
            'utm_campaign' => $this->trimValue((string) ($queryVars['utm_campaign'] ?? ''), 191),
            'device_type' => $this->trimValue($device['device_type'], 32),
            'browser' => $this->trimValue($device['browser'], 64),
            'os' => $this->trimValue($device['os'], 64),
            'user_agent' => $this->trimValue($ua, 255),
            'visitor_hash' => $this->hashValue($visitorId),
            'session_hash' => $this->hashValue($sessionId),
            'ip_hash' => $this->hashValue($ip),
            'is_bot' => 0,
            'status_code' => $statusCode,
        ];

        try {
            $this->insert(self::HITS_TABLE, $row);
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Raw hit insert failed: ' . $e->getMessage());
        }

        try {
            $this->upsertRealtimeSession($row);
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Realtime session upsert failed: ' . $e->getMessage());
        }

        $this->sendTrackingResponse(204);
    }

    protected function handleEventTrackingRequest(array $payload) {
        if(!$this->trackingEnabled || !$this->eventTrackingEnabled) $this->sendTrackingResponse(204);

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
        if($this->isBotUserAgent($ua)) $this->sendTrackingResponse(204);
        if($this->respectDnt && isset($_SERVER['HTTP_DNT']) && (string) $_SERVER['HTTP_DNT'] === '1') $this->sendTrackingResponse(204);
        if($this->requireConsent && !$this->hasConsentCookie()) $this->sendTrackingResponse(204);

        $ids = $this->resolveIncomingTrackingIds($payload);
        $visitorId = $ids['visitor_id'];
        $sessionId = $ids['session_id'];
        if($visitorId === '' || $sessionId === '') $this->sendTrackingResponse(422, ['ok' => false, 'message' => 'Missing visitor or session id']);

        $name = $this->sanitizeEventName((string) ($payload['name'] ?? ''));
        if($name === '') $this->sendTrackingResponse(422, ['ok' => false, 'message' => 'Missing event name']);

        $extra = is_array($payload['extra'] ?? null) ? $payload['extra'] : [];
        $group = $this->sanitizeEventGroup((string) ($extra['group'] ?? ''));
        if($group === '') $group = $this->inferEventGroup($name);

        $page = $this->wire('page');
        $path = $this->getRequestPathForStorage();
        $url = $this->trimValue((string) ($payload['url'] ?? $this->getCurrentRequestUrl()), 767);
        $pageId = max(0, (int) ($payload['pageId'] ?? 0));
        $pageTitle = $this->cleanTextValue((string) ($payload['pageTitle'] ?? ''), 255);
        $template = trim((string) ($payload['template'] ?? ''));
        if($page && $page->id && $page->template && $page->template->name !== 'admin') {
            if($pageId < 1) $pageId = (int) $page->id;
            if($pageTitle === '') $pageTitle = $this->cleanTextValue((string) $page->get('title'), 255);
            if($template === '') $template = (string) $page->template->name;
            $path = $this->getCanonicalPagePath($page);
        }

        $label = $this->trimValue((string) ($extra['label'] ?? ''), 255);
        if($label === '') $label = $this->trimValue((string) ($extra['text'] ?? ''), 255);
        $target = $this->trimValue((string) ($extra['target'] ?? ''), 767);
        if($target === '') $target = $this->trimValue((string) ($extra['href'] ?? ''), 767);

        $row = [
            'created_at' => date('Y-m-d H:i:s'),
            'created_date' => date('Y-m-d'),
            'created_hour' => (int) date('G'),
            'page_id' => $pageId,
            'page_title' => $this->trimValue($pageTitle, 255),
            'template' => $this->trimValue($template, 128),
            'url' => $url,
            'path' => $this->trimValue($path, 767),
            'path_hash' => md5($path),
            'event_group' => $this->trimValue($group, 64),
            'event_name' => $this->trimValue($name, 128),
            'event_label' => $label,
            'event_target' => $target,
            'extra_json' => $this->encodeEventExtra($extra),
            'visitor_hash' => $this->hashValue($visitorId),
            'session_hash' => $this->hashValue($sessionId),
        ];

        try {
            $this->insert(self::EVENTS_TABLE, $row);
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Event insert failed: ' . $e->getMessage());
            $this->sendTrackingResponse(500, ['ok' => false, 'message' => 'Event insert failed']);
        }

        $this->sendTrackingResponse(204);
    }

    protected function upsertRealtimeSession(array $row) {
        $db = $this->wire('database');
        $sql = "INSERT INTO `" . self::SESSIONS_TABLE . "` (
            `session_hash`,`visitor_hash`,`first_seen_at`,`last_seen_at`,`page_id`,`page_title`,`template`,
            `current_url`,`current_path`,`current_path_hash`,`referrer_host`,`referrer_url`,`device_type`,
            `browser`,`os`,`hit_count`,`status_code`
        ) VALUES (
            :session_hash,:visitor_hash,:first_seen_at,:last_seen_at,:page_id,:page_title,:template,
            :current_url,:current_path,:current_path_hash,:referrer_host,:referrer_url,:device_type,
            :browser,:os,:hit_count,:status_code
        ) ON DUPLICATE KEY UPDATE
            `last_seen_at` = VALUES(`last_seen_at`),
            `page_id` = VALUES(`page_id`),
            `page_title` = VALUES(`page_title`),
            `template` = VALUES(`template`),
            `current_url` = VALUES(`current_url`),
            `current_path` = VALUES(`current_path`),
            `current_path_hash` = VALUES(`current_path_hash`),
            `referrer_host` = VALUES(`referrer_host`),
            `referrer_url` = VALUES(`referrer_url`),
            `device_type` = VALUES(`device_type`),
            `browser` = VALUES(`browser`),
            `os` = VALUES(`os`),
            `status_code` = VALUES(`status_code`),
            `hit_count` = `hit_count` + 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':session_hash' => $row['session_hash'],
            ':visitor_hash' => $row['visitor_hash'],
            ':first_seen_at' => $row['created_at'],
            ':last_seen_at' => $row['created_at'],
            ':page_id' => $row['page_id'],
            ':page_title' => $row['page_title'],
            ':template' => $row['template'],
            ':current_url' => $row['url'],
            ':current_path' => $row['path'],
            ':current_path_hash' => $row['path_hash'],
            ':referrer_host' => $row['referrer_host'],
            ':referrer_url' => $row['referrer_url'],
            ':device_type' => $row['device_type'],
            ':browser' => $row['browser'],
            ':os' => $row['os'],
            ':hit_count' => 1,
            ':status_code' => $row['status_code'],
        ]);
    }

    protected function getIncomingPayload() {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if(is_array($json)) return $json;
        $payload = [];
        parse_str($raw, $payload);
        if(is_array($payload) && $payload) return $payload;
        $payload = $this->wire('input')->post->getArray();
        if(is_array($payload) && $payload) return $payload;
        return $this->wire('input')->get->getArray();
    }

    protected function sendTrackingResponse($status = 200, array $payload = []) {
        http_response_code((int) $status);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        if($payload) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload);
        }
        exit;
    }

    public function handleDailyCron() {
        $this->rebuildDailyAggregate(date('Y-m-d', strtotime('-1 day')));
        $this->purgeOldHits();
    }

    public function handleHourlyCron() {
        $this->rebuildDailyAggregate(date('Y-m-d'));
        $this->purgeOldRealtimeSessions();
    }

    public function rebuildDailyAggregate($day) {
        $day = date('Y-m-d', strtotime($day));
        $nextDay = date('Y-m-d', strtotime($day . ' +1 day'));
        $db = $this->wire('database');

        $stmt = $db->prepare("DELETE FROM `" . self::DAILY_TABLE . "` WHERE `day` = :day");
        $stmt->execute([':day' => $day]);

        $sql = "INSERT INTO `" . self::DAILY_TABLE . "`
            (`day`,`page_id`,`page_title`,`template`,`path`,`path_hash`,`views`,`uniques`,`sessions`)
            SELECT :day, `page_id`, MAX(`page_title`), `template`, `path`, `path_hash`,
                   COUNT(*), COUNT(DISTINCT `visitor_hash`), COUNT(DISTINCT `session_hash`)
            FROM `" . self::HITS_TABLE . "`
            WHERE `created_at` >= :start AND `created_at` < :end
            GROUP BY `page_id`, `template`, `path`, `path_hash`";
        $stmt = $db->prepare($sql);
        $stmt->execute([':day' => $day, ':start' => $day . ' 00:00:00', ':end' => $nextDay . ' 00:00:00']);
    }

    public function purgeOldHits() {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(7, (int) $this->rawRetentionDays) . ' days'));
        $stmt = $this->wire('database')->prepare("DELETE FROM `" . self::HITS_TABLE . "` WHERE `created_at` < :cutoff");
        $stmt->execute([':cutoff' => $cutoff]);
    }

    public function purgeOldRealtimeSessions($hours = 48) {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(1, (int) $hours) . ' hours'));
        $stmt = $this->wire('database')->prepare("DELETE FROM `" . self::SESSIONS_TABLE . "` WHERE `last_seen_at` < :cutoff");
        $stmt->execute([':cutoff' => $cutoff]);
    }

    public function resetAnalyticsData() {
        $db = $this->wire('database');
        $db->exec("DELETE FROM `" . self::EVENTS_TABLE . "`");
        $db->exec("DELETE FROM `" . self::SESSIONS_TABLE . "`");
        $db->exec("DELETE FROM `" . self::DAILY_TABLE . "`");
        $db->exec("DELETE FROM `" . self::HITS_TABLE . "`");
    }

    public function getSummary($days = 30, array $filters = []) {
        $where = $this->buildWhere($filters, $this->getDateRangeForDays($days), ["status_code <> 404"]);
        $sql = "SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques, COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::HITS_TABLE . "` WHERE {$where['sql']}";
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['views' => 0, 'uniques' => 0, 'sessions' => 0];
    }

    public function get404Summary($days = 30) {
        $where = $this->buildWhere([], $this->getDateRangeForDays($days), ["status_code = 404"]);
        $sql = "SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques FROM `" . self::HITS_TABLE . "` WHERE {$where['sql']}";
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['views' => 0, 'uniques' => 0];
    }

    public function getCurrentVisitorsSummary($minutes = null, array $filters = []) {
        $minutes = $minutes ?: (int) $this->realtimeWindowMinutes;
        $where = $this->buildRealtimeWhere($minutes, $filters);
        $sql = "SELECT COUNT(*) AS current_visitors, COUNT(DISTINCT visitor_hash) AS current_uniques FROM `" . self::SESSIONS_TABLE . "` WHERE {$where['sql']}";
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['current_visitors' => 0, 'current_uniques' => 0];
    }

    public function getCurrentVisitors($minutes = null, $limit = 25, array $filters = []) {
        $minutes = $minutes ?: (int) $this->realtimeWindowMinutes;
        $where = $this->buildRealtimeWhere($minutes, $filters);
        $sql = "SELECT page_id, page_title, template, current_path, current_url, referrer_host, device_type, browser, os,
                       first_seen_at, last_seen_at, hit_count, status_code
                FROM `" . self::SESSIONS_TABLE . "`
                WHERE {$where['sql']}
                ORDER BY last_seen_at DESC
                LIMIT " . (int) $limit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach($rows as &$row) {
            $row = $this->normalizeAnalyticsRowForDisplay($row, 'current_path');
        }
        unset($row);
        return $rows;
    }

    public function getDailySeries($days = 30, array $filters = []) {
        $range = $this->getDateRangeForDays($days);
        $where = $this->buildWhere($filters, $range, ["status_code <> 404"]);
        $sql = "SELECT created_date AS day, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques, COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::HITS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY created_date
                ORDER BY created_date ASC";
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $indexed = [];
        foreach($rows as $row) $indexed[$row['day']] = $row;

        $series = [];
        $cursor = strtotime($range['start_date']);
        $end = strtotime($range['end_date']);
        while($cursor <= $end) {
            $day = date('Y-m-d', $cursor);
            $series[] = [
                'day' => $day,
                'label' => $this->formatDisplayDate($cursor),
                'time_label' => 'All day',
                'views' => isset($indexed[$day]) ? (int) $indexed[$day]['views'] : 0,
                'uniques' => isset($indexed[$day]) ? (int) $indexed[$day]['uniques'] : 0,
                'sessions' => isset($indexed[$day]) ? (int) $indexed[$day]['sessions'] : 0,
            ];
            $cursor = strtotime('+1 day', $cursor);
        }
        return $series;
    }



    public function getHourlySeries($date = null, array $filters = []) {
        $day = $date ? date('Y-m-d', strtotime((string) $date)) : date('Y-m-d');
        $range = [
            'start' => $day . ' 00:00:00',
            'end' => $day . ' 23:59:59',
            'start_date' => $day,
            'end_date' => $day,
        ];
        $where = $this->buildWhere($filters, $range, ["status_code <> 404"]);
        $sql = "SELECT created_hour AS hour, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques, COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::HITS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY created_hour
                ORDER BY created_hour ASC";
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $indexed = [];
        foreach($rows as $row) $indexed[(int) $row['hour']] = $row;

        $series = [];
        for($hour = 0; $hour < 24; $hour++) {
            $slot = isset($indexed[$hour]) ? $indexed[$hour] : null;
            $series[] = [
                'day' => $day,
                'hour' => $hour,
                'label' => $this->formatDisplayDate($day),
                'time_label' => sprintf('%02d:00–%02d:59', $hour, $hour),
                'views' => $slot ? (int) $slot['views'] : 0,
                'uniques' => $slot ? (int) $slot['uniques'] : 0,
                'sessions' => $slot ? (int) $slot['sessions'] : 0,
            ];
        }
        return $series;
    }

    public function getTopCampaigns($days = 30, $limit = 10, array $filters = []) {
        $where = $this->buildWhere($filters, $this->getDateRangeForDays($days), ["(utm_source != '' OR utm_medium != '' OR utm_campaign != '')"]);
        $sql = "SELECT CONCAT_WS(' / ',
                    NULLIF(utm_source, ''),
                    NULLIF(utm_medium, ''),
                    NULLIF(utm_campaign, '')
                ) AS label,
                COUNT(*) AS views,
                COUNT(DISTINCT visitor_hash) AS uniques,
                COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::HITS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY utm_source, utm_medium, utm_campaign
                ORDER BY views DESC, uniques DESC
                LIMIT " . (int) $limit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }


    public function getTopPages($days = 30, $limit = 15, array $filters = []) {
        $where = $this->buildWhere($filters, $this->getDateRangeForDays($days), ["status_code != 404"]);
        $sql = "SELECT MAX(page_id) AS page_id, MAX(page_title) AS page_title, MAX(template) AS template, MAX(path) AS path,
                       COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques, COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::HITS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY CASE WHEN page_id > 0 THEN CONCAT('page:', page_id) ELSE CONCAT('path:', path_hash) END
                ORDER BY views DESC, uniques DESC
                LIMIT " . (int) $limit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach($rows as &$row) $row = $this->normalizeAnalyticsRowForDisplay($row);
        unset($row);
        return $rows;
    }


    public function getTopLandingPages($days = 30, $limit = 10, array $filters = []) {
        $range = $this->getDateRangeForDays($days);
        $where = $this->buildWhere($filters, $range, ["status_code != 404"]);
        $sql = "SELECT MAX(h.page_id) AS page_id, MAX(h.page_title) AS page_title, MAX(h.template) AS template, MAX(h.path) AS path,
                       COUNT(*) AS sessions
                FROM `" . self::HITS_TABLE . "` AS h
                INNER JOIN (
                    SELECT MIN(id) AS hit_id
                    FROM `" . self::HITS_TABLE . "`
                    WHERE {$where['sql']}
                    GROUP BY session_hash
                ) AS first_hits ON first_hits.hit_id = h.id
                GROUP BY CASE WHEN h.page_id > 0 THEN CONCAT('page:', h.page_id) ELSE CONCAT('path:', h.path_hash) END
                ORDER BY sessions DESC
                LIMIT " . (int) $limit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach($rows as &$row) $row = $this->normalizeAnalyticsRowForDisplay($row);
        unset($row);
        return $rows;
    }

    public function getTopExitPages($days = 30, $limit = 10, array $filters = []) {
        $range = $this->getDateRangeForDays($days);
        $where = $this->buildWhere($filters, $range, ["status_code != 404"]);
        $sql = "SELECT MAX(h.page_id) AS page_id, MAX(h.page_title) AS page_title, MAX(h.template) AS template, MAX(h.path) AS path,
                       COUNT(*) AS sessions
                FROM `" . self::HITS_TABLE . "` AS h
                INNER JOIN (
                    SELECT MAX(id) AS hit_id
                    FROM `" . self::HITS_TABLE . "`
                    WHERE {$where['sql']}
                    GROUP BY session_hash
                ) AS last_hits ON last_hits.hit_id = h.id
                GROUP BY CASE WHEN h.page_id > 0 THEN CONCAT('page:', h.page_id) ELSE CONCAT('path:', h.path_hash) END
                ORDER BY sessions DESC
                LIMIT " . (int) $limit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach($rows as &$row) $row = $this->normalizeAnalyticsRowForDisplay($row);
        unset($row);
        return $rows;
    }

    public function getSessionQuality($days = 30, array $filters = []) {
        $range = $this->getDateRangeForDays($days);
        $where = $this->buildWhere($filters, $range);
        $sql = "SELECT COUNT(*) AS total_sessions,
                       SUM(CASE WHEN hits_per_session = 1 THEN 1 ELSE 0 END) AS single_page_sessions,
                       AVG(hits_per_session) AS avg_pages_per_session
                FROM (
                    SELECT session_hash, COUNT(*) AS hits_per_session
                    FROM `" . self::HITS_TABLE . "`
                    WHERE {$where['sql']}
                    GROUP BY session_hash
                ) AS t";
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total_sessions'] ?? 0);
        $single = (int) ($row['single_page_sessions'] ?? 0);
        $avg = $total > 0 ? (float) ($row['avg_pages_per_session'] ?? 0) : 0.0;
        $singleRate = $total > 0 ? round(($single / $total) * 100, 1) : 0.0;
        return [
            'total_sessions' => $total,
            'single_page_sessions' => $single,
            'avg_pages_per_session' => round($avg, 2),
            'single_page_rate' => $singleRate,
        ];
    }

    public function getTop404Paths($days = 30, $limit = 15) {
        $where = $this->buildWhere([], $this->getDateRangeForDays($days), ["status_code = 404"]);
        $sql = "SELECT path, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques
                FROM `" . self::HITS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY path, path_hash
                ORDER BY views DESC, uniques DESC
                LIMIT " . (int) $limit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach($rows as &$row) {
            $row['path'] = $this->normalizePath((string) ($row['path'] ?? '/'));
        }
        unset($row);
        return $rows;
    }

    public function getTopReferrers($days = 30, $limit = 10, array $filters = []) {
        $where = $this->buildWhere($filters, $this->getDateRangeForDays($days), ["referrer_host != ''"]);
        $sql = "SELECT referrer_host, COUNT(*) AS views
                FROM `" . self::HITS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY referrer_host
                ORDER BY views DESC
                LIMIT " . (int) $limit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function getTopSearchTerms($days = 30, $limit = 10, array $filters = []) {
        $where = $this->buildWhere($filters, $this->getDateRangeForDays($days), ["search_term != ''"]);
        $sql = "SELECT search_term, COUNT(*) AS views
                FROM `" . self::HITS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY search_term
                ORDER BY views DESC
                LIMIT " . (int) $limit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function getBreakdown($field, $days = 30, $limit = 10, array $filters = []) {
        if(!in_array($field, ['browser', 'device_type', 'os', 'template'], true)) return [];
        $where = $this->buildWhere($filters, $this->getDateRangeForDays($days), ["{$field} != ''"]);
        $sql = "SELECT {$field} AS label, COUNT(*) AS views
                FROM `" . self::HITS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY {$field}
                ORDER BY views DESC
                LIMIT " . (int) $limit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function getPageSummary($pageId, $days = 30) {
        $pageId = (int) $pageId;
        if($pageId < 1) return ['views' => 0, 'uniques' => 0, 'sessions' => 0];
        return $this->getSummary($days, ['page_id' => $pageId]);
    }

    public function getEventSummary($days = 30, array $filters = [], $group = '') {
        $where = $this->buildEventWhere($filters, $this->getDateRangeForDays($days), $group);
        $sql = "SELECT COUNT(*) AS events, COUNT(DISTINCT visitor_hash) AS uniques, COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::EVENTS_TABLE . "` WHERE {$where['sql']}";
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['events' => 0, 'uniques' => 0, 'sessions' => 0];
    }

    public function getTopEvents($days = 30, $limit = 15, array $filters = [], $group = '') {
        $where = $this->buildEventWhere($filters, $this->getDateRangeForDays($days), $group);
        $sql = "SELECT event_group, event_name, event_label, event_target,
                       COUNT(*) AS events, COUNT(DISTINCT visitor_hash) AS uniques, COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::EVENTS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY event_group, event_name, event_label, event_target
                ORDER BY events DESC, sessions DESC
                LIMIT " . (int) $limit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function getTopEventTargets($days = 30, $limit = 15, array $filters = [], $group = '') {
        $where = $this->buildEventWhere($filters, $this->getDateRangeForDays($days), $group, ["event_target != ''"]);
        $sql = "SELECT event_group, event_target, COUNT(*) AS events, COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::EVENTS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY event_group, event_target
                ORDER BY events DESC, sessions DESC
                LIMIT " . (int) $limit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function getEventDailySeries($days = 30, array $filters = [], $group = '') {
        $range = $this->getDateRangeForDays($days);
        $where = $this->buildEventWhere($filters, $range, $group);
        $sql = "SELECT created_date AS day, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques, COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::EVENTS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY created_date
                ORDER BY created_date ASC";
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $indexed = [];
        foreach($rows as $row) $indexed[$row['day']] = $row;

        $series = [];
        $cursor = strtotime($range['start_date']);
        $end = strtotime($range['end_date']);
        while($cursor <= $end) {
            $day = date('Y-m-d', $cursor);
            $series[] = [
                'day' => $day,
                'label' => $this->formatDisplayDate($cursor),
                'time_label' => 'All day',
                'views' => isset($indexed[$day]) ? (int) $indexed[$day]['views'] : 0,
                'uniques' => isset($indexed[$day]) ? (int) $indexed[$day]['uniques'] : 0,
                'sessions' => isset($indexed[$day]) ? (int) $indexed[$day]['sessions'] : 0,
            ];
            $cursor = strtotime('+1 day', $cursor);
        }
        return $series;
    }

    protected function buildWhere(array $filters, array $range, array $extra = []) {
        $where = ['created_at >= :start', 'created_at <= :end'];
        $params = [':start' => $range['start'], ':end' => $range['end']];
        if(!empty($filters['page_id'])) {
            $where[] = 'page_id = :page_id';
            $params[':page_id'] = (int) $filters['page_id'];
        }
        if(!empty($filters['template'])) {
            $where[] = 'template = :template';
            $params[':template'] = (string) $filters['template'];
        }
        foreach($extra as $fragment) $where[] = $fragment;
        return ['sql' => implode(' AND ', $where), 'params' => $params];
    }

    protected function buildEventWhere(array $filters, array $range, $group = '', array $extra = []) {
        $where = ['created_at >= :start', 'created_at <= :end'];
        $params = [':start' => $range['start'], ':end' => $range['end']];
        if(!empty($filters['page_id'])) {
            $where[] = 'page_id = :page_id';
            $params[':page_id'] = (int) $filters['page_id'];
        }
        if(!empty($filters['template'])) {
            $where[] = 'template = :template';
            $params[':template'] = (string) $filters['template'];
        }
        if($group !== '') {
            $where[] = 'event_group = :event_group';
            $params[':event_group'] = (string) $group;
        }
        foreach($extra as $fragment) $where[] = $fragment;
        return ['sql' => implode(' AND ', $where), 'params' => $params];
    }

    protected function buildRealtimeWhere($minutes, array $filters = []) {
        $where = ['last_seen_at >= :cutoff'];
        $params = [':cutoff' => date('Y-m-d H:i:s', strtotime('-' . max(1, (int) $minutes) . ' minutes'))];
        if(!empty($filters['page_id'])) {
            $where[] = 'page_id = :page_id';
            $params[':page_id'] = (int) $filters['page_id'];
        }
        if(!empty($filters['template'])) {
            $where[] = 'template = :template';
            $params[':template'] = (string) $filters['template'];
        }
        return ['sql' => implode(' AND ', $where), 'params' => $params];
    }

    public function getDateDisplayFormat() {
        $selected = trim((string) ($this->displayDateFormat ?? 'site_default'));
        if($selected === '' || $selected === 'site_default') {
            $siteFormat = (string) ($this->wire('config')->dateFormat ?? 'd M Y');
            return $siteFormat !== '' ? $siteFormat : 'd M Y';
        }
        return $selected;
    }

    public function getDateTimeDisplayFormat() {
        $format = $this->getDateDisplayFormat();
        if(preg_match('/[GHhisuaA]/', $format)) return $format;
        return rtrim($format) . ' H:i';
    }

    public function formatDisplayDate($value) {
        if(!$value) return '';
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if(!$ts) return (string) $value;
        return date($this->getDateDisplayFormat(), $ts);
    }

    public function formatDisplayDateTime($value) {
        if(!$value) return '';
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if(!$ts) return (string) $value;
        return date($this->getDateTimeDisplayFormat(), $ts);
    }

    public function formatDisplayRange(array $rangeSpec) {
        $start = isset($rangeSpec['start_date']) ? $this->formatDisplayDate($rangeSpec['start_date']) : '';
        $end = isset($rangeSpec['end_date']) ? $this->formatDisplayDate($rangeSpec['end_date']) : '';
        if($start !== '' && $end !== '') return $start . ' → ' . $end;
        return trim($start . ' ' . $end);
    }

    public function getDateRangeBetween($fromDate, $toDate, $fallbackDays = 30) {
        $fromDate = trim((string) $fromDate);
        $toDate = trim((string) $toDate);
        $validFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) ? $fromDate : '';
        $validTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) ? $toDate : '';
        if($validFrom === '' && $validTo === '') return $this->getDateRangeForDays((int) $fallbackDays);
        if($validFrom === '' && $validTo !== '') $validFrom = $validTo;
        if($validTo === '' && $validFrom !== '') $validTo = $validFrom;
        if(strtotime($validFrom) > strtotime($validTo)) {
            $tmp = $validFrom;
            $validFrom = $validTo;
            $validTo = $tmp;
        }
        return [
            'start' => $validFrom . ' 00:00:00',
            'end' => $validTo . ' 23:59:59',
            'start_date' => $validFrom,
            'end_date' => $validTo,
        ];
    }

    public function getDateRangeForDays($days) {
        if(is_array($days)) {
            $start = isset($days['start_date']) ? (string) $days['start_date'] : '';
            $end = isset($days['end_date']) ? (string) $days['end_date'] : '';
            if($start !== '' || $end !== '') return $this->getDateRangeBetween($start, $end, 30);
            if(isset($days['start'], $days['end'])) {
                $s = date('Y-m-d', strtotime((string) $days['start']));
                $e = date('Y-m-d', strtotime((string) $days['end']));
                return $this->getDateRangeBetween($s, $e, 30);
            }
        }
        $days = max(1, (int) $days);
        return [
            'start' => date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days')),
            'end' => date('Y-m-d 23:59:59'),
            'start_date' => date('Y-m-d', strtotime('-' . ($days - 1) . ' days')),
            'end_date' => date('Y-m-d'),
        ];
    }

    protected function isRoleExcluded() {
        $user = $this->wire('user');
        if(!$user || !$user->id) return false;
        foreach($user->roles as $role) {
            if(in_array($role->name, (array) $this->excludeRoles, true)) return true;
        }
        return false;
    }

    protected function getExcludedPathPrefixes() {
        $prefixes = [];
        foreach(preg_split('/\R+/', (string) $this->excludePaths) as $line) {
            $line = trim($line);
            if($line !== '') $prefixes[] = $line;
        }
        return $prefixes;
    }

    protected function getPrivacyWireGroups() {
        $groups = [];
        foreach(explode(',', (string) $this->privacyWireGroups) as $group) {
            $group = trim($group);
            if($group !== '') $groups[] = $group;
        }
        return $groups ?: ['statistics'];
    }

    public function getRequestPathForStorage() {
        $page = $this->wire('page');
        if($page && $page->id && !$this->is404Page($page)) {
            return $this->getCanonicalPagePath($page);
        }
        return $this->getRequestedPathWithoutQuery();
    }

    protected function normalizePath($path) {
        $path = trim((string) $path);
        if($path === '') $path = '/';
        if(strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
            $parts = parse_url($path);
            $path = $parts['path'] ?? '/';
            if(!$this->ignoreQueryString && !empty($parts['query'])) $path .= '?' . $parts['query'];
        }
        $root = (string) $this->wire('config')->urls->root;
        $root = '/' . trim($root, '/') . '/';
        if($root !== '//' && strpos($path, $root) === 0) {
            $path = '/' . ltrim(substr($path, strlen($root) - 1), '/');
        }
        if($this->ignoreQueryString && strpos($path, '?') !== false) $path = strtok($path, '?');
        return $path === '' ? '/' : $path;
    }

    protected function extractSearchTermFromArray(array $queryVars) {
        foreach(array_map('trim', explode(',', (string) $this->searchQueryVars)) as $key) {
            if($key !== '' && isset($queryVars[$key]) && is_scalar($queryVars[$key])) {
                $value = trim((string) $queryVars[$key]);
                if($value !== '') return $value;
            }
        }
        return '';
    }

    protected function sanitizeEventName($name) {
        $name = preg_replace('/[^a-z0-9_\-:]+/i', '_', mb_strtolower(trim((string) $name)));
        $name = trim((string) $name, '_-:');
        return $this->trimValue($name, 128);
    }

    protected function sanitizeEventGroup($group) {
        $group = preg_replace('/[^a-z0-9_\-]+/i', '_', mb_strtolower(trim((string) $group)));
        return trim((string) $group, '_-');
    }

    protected function inferEventGroup($name) {
        if(strpos($name, 'form_') === 0) return 'form';
        if(strpos($name, 'download_') === 0) return 'download';
        if(strpos($name, 'click_mail') === 0 || strpos($name, 'click_tel') === 0) return 'contact';
        if(strpos($name, 'click_outbound') === 0) return 'navigation';
        return 'custom';
    }

    protected function encodeEventExtra(array $extra) {
        if(!$extra) return null;
        $json = json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }

    protected function insert($table, array $row) {
        $db = $this->wire('database');
        $columns = array_keys($row);
        $placeholders = array_map(function($column) { return ':' . $column; }, $columns);
        $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $columns) . "`) VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_combine($placeholders, array_values($row)));
    }


    public function getHealthSnapshot() {
        $db = $this->wire('database');
        $snapshot = [
            'module_dir' => $this->getModuleDirName(),
            'admin_css_url' => $this->getAssetUrl('assets/admin.css') . '?v=' . rawurlencode(self::VERSION),
            'tracker_js_url' => $this->getAssetUrl('assets/tracker.js') . '?v=' . rawurlencode(self::VERSION),
            'track_endpoint_url' => $this->getTrackEndpointUrl(),
            'realtime_endpoint_url' => $this->getRealtimeEndpointUrl(),
            'hits_count' => 0,
            'sessions_count' => 0,
            'daily_count' => 0,
            'events_count' => 0,
            'last_hit_at' => '',
            'last_session_at' => '',
            'last_event_at' => '',
        ];
        foreach([
            'hits_count' => self::HITS_TABLE,
            'sessions_count' => self::SESSIONS_TABLE,
            'daily_count' => self::DAILY_TABLE,
            'events_count' => self::EVENTS_TABLE,
        ] as $key => $table) {
            try {
                $snapshot[$key] = (int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            } catch(\Throwable $e) {
                $snapshot[$key] = -1;
            }
        }
        try {
            $snapshot['last_hit_at'] = (string) $db->query("SELECT created_at FROM `" . self::HITS_TABLE . "` ORDER BY id DESC LIMIT 1")->fetchColumn();
        } catch(\Throwable $e) {
            $snapshot['last_hit_at'] = '';
        }
        try {
            $snapshot['last_session_at'] = (string) $db->query("SELECT last_seen_at FROM `" . self::SESSIONS_TABLE . "` ORDER BY last_seen_at DESC LIMIT 1")->fetchColumn();
        } catch(\Throwable $e) {
            $snapshot['last_session_at'] = '';
        }
        try {
            $snapshot['last_event_at'] = (string) $db->query("SELECT created_at FROM `" . self::EVENTS_TABLE . "` ORDER BY id DESC LIMIT 1")->fetchColumn();
        } catch(\Throwable $e) {
            $snapshot['last_event_at'] = '';
        }
        return $snapshot;
    }

    public function addPageAnalyticsBox(HookEvent $event) {
        if(!$this->wire('user')->hasPermission('nativeanalytics-view')) return;
        $form = $event->return;
        $editedPage = $event->process->getPage();
        if(!$editedPage || !$editedPage->id || ($editedPage->template && $editedPage->template->name === 'admin')) return;

        $summary7 = $this->getPageSummary($editedPage->id, 7);
        $summary30 = $this->getPageSummary($editedPage->id, 30);
        $current = $this->getCurrentVisitorsSummary((int) $this->realtimeWindowMinutes, ['page_id' => (int) $editedPage->id]);

        $field = $this->wire('modules')->get('InputfieldMarkup');
        $field->label = 'Analytics';
        $field->icon = 'line-chart';
        $field->collapsed = Inputfield::collapsedNo;
        $field->value = $this->renderMiniStatsBox($summary7, $summary30, $current, $editedPage);
        $form->add($field);
    }

    public function renderMiniStatsBox(array $summary7, array $summary30, array $current, Page $page) {
        $url = $this->wire('config')->urls->admin . 'native-analytics/?range=30d&page_id=' . (int) $page->id;
        $minutes = max(1, (int) $this->realtimeWindowMinutes);
        $html = '<div class="pwna-mini"><div class="pwna-mini-grid">';
        $html .= '<div><strong>Last 7 days</strong><div>' . (int) $summary7['views'] . ' views</div><div>' . (int) $summary7['uniques'] . ' uniques</div></div>';
        $html .= '<div><strong>Last 30 days</strong><div>' . (int) $summary30['views'] . ' views</div><div>' . (int) $summary30['uniques'] . ' uniques</div></div>';
        $html .= '<div><strong>Current visitors</strong><div>' . (int) ($current['current_visitors'] ?? 0) . ' active</div><div>window: ' . $minutes . ' min</div></div>';
        $html .= '<div><strong>Sessions</strong><div>' . (int) $summary30['sessions'] . ' in 30 days</div><div>page ID ' . (int) $page->id . '</div></div>';
        $html .= '</div><p style="margin-top:10px;"><a class="ui-button" href="' . $this->wire('sanitizer')->entities($url) . '">Open full analytics</a></p></div>';
        return $html;
    }

    public function renderComparisonChart(array $primarySeries, array $comparisonSeries, $metric = 'views', $chartLabel = 'Comparison chart', $primaryLabel = 'Selected period', $secondaryLabel = 'Comparison period') {
            $metric = in_array($metric, ['views', 'uniques', 'sessions'], true) ? $metric : 'views';
        if(!$primarySeries) return '<p>No data yet.</p>';

        $width = 920;
        $height = 260;
        $padX = 40;
        $padY = 20;
        $plotWidth = $width - ($padX * 2);
        $plotHeight = $height - ($padY * 2) - 24;
        $count = max(count($primarySeries), count($comparisonSeries));
        $sanitizer = $this->wire('sanitizer');
        $max = 1;
        for($i = 0; $i < $count; $i++) {
            $primary = $primarySeries[$i] ?? [];
            $compare = $comparisonSeries[$i] ?? [];
            $max = max($max, (int) ($primary[$metric] ?? 0), (int) ($compare[$metric] ?? 0));
        }

        $primaryPoints = [];
        $comparePoints = [];
        $circles = [];
        for($i = 0; $i < $count; $i++) {
            $primary = $primarySeries[$i] ?? [];
            $compare = $comparisonSeries[$i] ?? [];
            $primaryValue = (int) ($primary[$metric] ?? 0);
            $compareValue = (int) ($compare[$metric] ?? 0);
            $x = $padX + ($count > 1 ? ($plotWidth / ($count - 1)) * $i : ($plotWidth / 2));
            $yPrimary = $padY + $plotHeight - (($primaryValue / $max) * $plotHeight);
            $yCompare = $padY + $plotHeight - (($compareValue / $max) * $plotHeight);
            $x = round($x, 2);
            $yPrimary = round($yPrimary, 2);
            $yCompare = round($yCompare, 2);
            $primaryPoints[] = $x . ',' . $yPrimary;
            $comparePoints[] = $x . ',' . $yCompare;

            $primaryLabelText = (string) ($primary['label'] ?? ('Slot ' . ($i + 1)));
            $primaryTime = (string) ($primary['time_label'] ?? '');
            $compareLabelText = (string) ($compare['label'] ?? ('Slot ' . ($i + 1)));
            $title = $primaryLabel . ': ' . $primaryLabelText . ' | ' . ucfirst($metric) . ': ' . $primaryValue . ' || ' . $secondaryLabel . ': ' . $compareLabelText . ' | ' . ucfirst($metric) . ': ' . $compareValue;

            $circles[] = '<circle class="pwna-point" cx="' . $x . '" cy="' . $yPrimary . '" r="4" data-label="' . $sanitizer->entities($primaryLabelText) . '" data-time="' . $sanitizer->entities($primaryTime) . '" data-views="' . (int) ($primary['views'] ?? 0) . '" data-uniques="' . (int) ($primary['uniques'] ?? 0) . '" data-sessions="' . (int) ($primary['sessions'] ?? 0) . '" data-compare-label="' . $sanitizer->entities($secondaryLabel . ': ' . $compareLabelText) . '" data-compare-views="' . (int) ($compare['views'] ?? 0) . '" data-compare-uniques="' . (int) ($compare['uniques'] ?? 0) . '" data-compare-sessions="' . (int) ($compare['sessions'] ?? 0) . '"><title>' . $sanitizer->entities($title) . '</title></circle>';
        }

        $first = reset($primarySeries);
        $last = end($primarySeries);
        $firstLabel = isset($first['hour']) ? '00:00' : $this->formatDisplayDate($first['day']);
        $lastLabel = isset($last['hour']) ? '23:00' : $this->formatDisplayDate($last['day']);

        $html  = '<div class="pwna-chart-wrap">';
        $html .= '<svg class="pwna-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . $sanitizer->entities($chartLabel) . '">';
        $html .= '<line x1="' . $padX . '" y1="' . ($padY + $plotHeight) . '" x2="' . ($padX + $plotWidth) . '" y2="' . ($padY + $plotHeight) . '" class="pwna-axis" />';
        $html .= '<line x1="' . $padX . '" y1="' . $padY . '" x2="' . $padX . '" y2="' . ($padY + $plotHeight) . '" class="pwna-axis" />';
        for($i = 0; $i <= 4; $i++) {
            $v = (int) round(($max / 4) * $i);
            $y = $padY + $plotHeight - (($v / $max) * $plotHeight);
            $html .= '<line x1="' . $padX . '" y1="' . round($y, 2) . '" x2="' . ($padX + $plotWidth) . '" y2="' . round($y, 2) . '" class="pwna-grid" />';
            $html .= '<text x="8" y="' . (round($y, 2) + 4) . '" class="pwna-label">' . $v . '</text>';
        }
        $html .= '<polyline fill="none" class="pwna-line-compare" points="' . implode(' ', $comparePoints) . '" />';
        $html .= '<polyline fill="none" class="pwna-line" points="' . implode(' ', $primaryPoints) . '" />';
        $html .= implode('', $circles);
        $html .= '<text x="' . $padX . '" y="' . ($height - 6) . '" class="pwna-label">' . $sanitizer->entities($firstLabel) . '</text>';
        $html .= '<text x="' . ($padX + $plotWidth - 46) . '" y="' . ($height - 6) . '" class="pwna-label">' . $sanitizer->entities($lastLabel) . '</text>';
        $html .= '</svg>';
        $html .= '<div class="pwna-chart-tooltip" hidden><div class="pwna-chart-tooltip-day"></div><div class="pwna-chart-tooltip-time" hidden></div><div class="pwna-chart-tooltip-grid"><span>Views</span><strong data-pwna-tip="views">0</strong><span>Uniques</span><strong data-pwna-tip="uniques">0</strong><span>Sessions</span><strong data-pwna-tip="sessions">0</strong></div><div class="pwna-chart-tooltip-compare" hidden><div class="pwna-chart-tooltip-day" data-pwna-tip="compare-day"></div><div class="pwna-chart-tooltip-grid"><span>Views</span><strong data-pwna-tip="compare-views">0</strong><span>Uniques</span><strong data-pwna-tip="compare-uniques">0</strong><span>Sessions</span><strong data-pwna-tip="compare-sessions">0</strong></div></div></div>';
        $html .= '</div>';
        return $html;
    }

    public function renderLineChart(array $series, $metric = 'views', $chartLabel = 'Analytics chart') {
        $metric = in_array($metric, ['views', 'uniques', 'sessions'], true) ? $metric : 'views';
        if(!$series) return '<p>No data yet.</p>';

        $width = 920;
        $height = 260;
        $padX = 40;
        $padY = 20;
        $plotWidth = $width - ($padX * 2);
        $plotHeight = $height - ($padY * 2) - 24;
        $max = 1;
        foreach($series as $row) $max = max($max, (int) ($row[$metric] ?? 0));

        $points = [];
        $circles = [];
        $count = count($series);
        $sanitizer = $this->wire('sanitizer');
        foreach($series as $index => $row) {
            $value = (int) ($row[$metric] ?? 0);
            $x = $padX + ($count > 1 ? ($plotWidth / ($count - 1)) * $index : ($plotWidth / 2));
            $y = $padY + $plotHeight - (($value / $max) * $plotHeight);
            $x = round($x, 2);
            $y = round($y, 2);
            $points[] = $x . ',' . $y;

            $label = (string) ($row['label'] ?? (isset($row['day']) ? $this->formatDisplayDate($row['day']) : ''));
            $timeLabel = (string) ($row['time_label'] ?? (isset($row['hour']) ? sprintf('%02d:00–%02d:59', (int) $row['hour'], (int) $row['hour']) : ''));
            $title = trim($label . ' ' . $timeLabel) . ' | Views: ' . (int) ($row['views'] ?? 0) . ' | Uniques: ' . (int) ($row['uniques'] ?? 0) . ' | Sessions: ' . (int) ($row['sessions'] ?? 0);

            $circles[] = '<circle class="pwna-point" cx="' . $x . '" cy="' . $y . '" r="4" data-label="' . $sanitizer->entities($label) . '" data-time="' . $sanitizer->entities($timeLabel) . '" data-views="' . (int) ($row['views'] ?? 0) . '" data-uniques="' . (int) ($row['uniques'] ?? 0) . '" data-sessions="' . (int) ($row['sessions'] ?? 0) . '"><title>' . $sanitizer->entities($title) . '</title></circle>';
        }

        $first = reset($series);
        $last = end($series);
        $firstLabel = isset($first['hour']) ? '00:00' : $this->formatDisplayDate($first['day']);
        $lastLabel = isset($last['hour']) ? '23:00' : $this->formatDisplayDate($last['day']);

        $html  = '<div class="pwna-chart-wrap">';
        $html .= '<svg class="pwna-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . $sanitizer->entities($chartLabel) . '">';
        $html .= '<line x1="' . $padX . '" y1="' . ($padY + $plotHeight) . '" x2="' . ($padX + $plotWidth) . '" y2="' . ($padY + $plotHeight) . '" class="pwna-axis" />';
        $html .= '<line x1="' . $padX . '" y1="' . $padY . '" x2="' . $padX . '" y2="' . ($padY + $plotHeight) . '" class="pwna-axis" />';
        for($i = 0; $i <= 4; $i++) {
            $v = (int) round(($max / 4) * $i);
            $y = $padY + $plotHeight - (($v / $max) * $plotHeight);
            $html .= '<line x1="' . $padX . '" y1="' . round($y, 2) . '" x2="' . ($padX + $plotWidth) . '" y2="' . round($y, 2) . '" class="pwna-grid" />';
            $html .= '<text x="8" y="' . (round($y, 2) + 4) . '" class="pwna-label">' . $v . '</text>';
        }
        $html .= '<polyline fill="none" class="pwna-line" points="' . implode(' ', $points) . '" />';
        $html .= implode('', $circles);
        $html .= '<text x="' . $padX . '" y="' . ($height - 6) . '" class="pwna-label">' . $sanitizer->entities($firstLabel) . '</text>';
        $html .= '<text x="' . ($padX + $plotWidth - 46) . '" y="' . ($height - 6) . '" class="pwna-label">' . $sanitizer->entities($lastLabel) . '</text>';
        $html .= '</svg>';
        $html .= '<div class="pwna-chart-tooltip" hidden><div class="pwna-chart-tooltip-day"></div><div class="pwna-chart-tooltip-time" hidden></div><div class="pwna-chart-tooltip-grid"><span>Views</span><strong data-pwna-tip="views">0</strong><span>Uniques</span><strong data-pwna-tip="uniques">0</strong><span>Sessions</span><strong data-pwna-tip="sessions">0</strong></div><div class="pwna-chart-tooltip-compare" hidden><div class="pwna-chart-tooltip-day" data-pwna-tip="compare-day"></div><div class="pwna-chart-tooltip-grid"><span>Views</span><strong data-pwna-tip="compare-views">0</strong><span>Uniques</span><strong data-pwna-tip="compare-uniques">0</strong><span>Sessions</span><strong data-pwna-tip="compare-sessions">0</strong></div></div></div>';
        $html .= '</div>';
        return $html;
    }


    protected function parseUserAgent($ua) {
        $ua = (string) $ua;
        $browser = 'Other';
        $os = 'Other';
        $device = 'desktop';

        if(preg_match('/ipad|tablet/i', $ua)) $device = 'tablet';
        elseif(preg_match('/mobi|android|iphone/i', $ua)) $device = 'mobile';

        foreach([
            'Edge' => '/edg/i',
            'Chrome' => '/chrome|crios/i',
            'Firefox' => '/firefox|fxios/i',
            'Safari' => '/safari/i',
            'Opera' => '/opera|opr\//i',
        ] as $label => $regex) {
            if(!preg_match($regex, $ua)) continue;
            if($label === 'Safari' && preg_match('/chrome|crios|android|edg/i', $ua)) continue;
            $browser = $label;
            break;
        }

        foreach([
            'Windows' => '/windows/i',
            'macOS' => '/macintosh|mac os x/i',
            'iOS' => '/iphone|ipad/i',
            'Android' => '/android/i',
            'Linux' => '/linux/i',
        ] as $label => $regex) {
            if(preg_match($regex, $ua)) {
                $os = $label;
                break;
            }
        }

        return ['browser' => $browser, 'os' => $os, 'device_type' => $device];
    }

    protected function isBotUserAgent($ua) {
        if(!$ua) return false;
        return (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|headless|python-requests|wget|curl/i', $ua);
    }

    protected function getClientIp() {
        foreach(['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if(empty($_SERVER[$key])) continue;
            $value = trim((string) $_SERVER[$key]);
            if(strpos($value, ',') !== false) $value = trim(explode(',', $value)[0]);
            return $value;
        }
        return '';
    }

    protected function hashValue($value) {
        return hash('sha256', $this->hashSalt . '|' . (string) $value);
    }

    protected function trimValue($value, $maxLength) {
        $value = trim((string) $value);
        if(mb_strlen($value) > $maxLength) $value = mb_substr($value, 0, $maxLength);
        return $value;
    }
}

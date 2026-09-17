<?php namespace ProcessWire;

require_once __DIR__ . '/lib/like-escape.php';

class NativeAnalytics extends WireData implements Module, ConfigurableModule {

    const VERSION = '1.0.33';
    const HITS_TABLE = 'pwna_hits';
    const DAILY_TABLE = 'pwna_daily';
    const SESSIONS_TABLE = 'pwna_sessions';
    const EVENTS_TABLE = 'pwna_events';
    const EVENT_DAILY_TABLE = 'pwna_event_daily';
    const GOALS_TABLE = 'pwna_goals';
    const GOAL_DAILY_TABLE = 'pwna_goal_daily';
    const ATTRIBUTION_TABLE = 'pwna_attribution';
    const CURRENT_VISITORS_FETCH_LIMIT = 1000;

    protected $defaults = [
        'schemaCheckedVersion' => 0,
        'schemaBackfillPending' => 0,
        'trackingEnabled' => 1,
        'respectDnt' => 1,
        'requireConsent' => 0,
        'consentCookieName' => 'pwna_consent',
        'rawRetentionDays' => 90,
        'rawEventRetentionDays' => 180,
        'optimizeAfterPurge' => 1,
        'lastPurgeAt' => '',
        'lastOptimizeAt' => '',
        'highTrafficMode' => 0,
        'realtimeWindowMinutes' => 5,
        'realtimeRefreshSeconds' => 10,
        'funnelSteps' => '',
        'ignoreQueryString' => 1,
        'excludeRoles' => ['superuser'],
        'excludePaths' => "/processwire/\n/admin/\n/404/",
        'suspiciousPathPatterns' => '',
        'ipBlocklist' => '',
        'searchQueryVars' => 'q,s,search',
        'dashboardDefaultRange' => '30d',
        'displayDateFormat' => 'site_default',
        'showPageEditAnalytics' => 1,
        'hashSalt' => '',
        'eventTrackingEnabled' => 1,
        'trackingStorageMode' => 'cookie',
        'privacyWireAutoConsent' => 0,
        'privacyWireStorageKey' => 'privacywire',
        'privacyWireGroups' => 'statistics,marketing',
        'privacyWireConsentCookieMaxAge' => 31536000,
        'monthlyReportsEnabled' => 0,
        'monthlyReportRecipients' => '',
        'monthlyReportSendDay' => 1,
        'monthlyReportFromEmail' => '',
        'monthlyReportAttachPdf' => 1,
        'monthlyReportIncludeTopPages' => 1,
        'monthlyReportIncludeReferrers' => 1,
        'monthlyReportIncludeChannels' => 1,
        'monthlyReportIncludeEvents' => 1,
        'monthlyReportLastSentPeriod' => '',
        'trackingMode' => 'js_first',
        'botFilterEnabled' => 1,
        'botFleetMinSessions' => 20,
        'botFleetMinIps' => 5,
        'botFleetWindowMinutes' => 240,
        'botIpMaxHitsPerHour' => 30,
        'bot404ShowBots' => 0,
        'deleteDataOnUninstall' => 0,
    ];

    public static function getModuleInfo() {
        return [
            'title' => 'NativeAnalytics',
            'summary' => 'Native first-party analytics dashboard for ProcessWire with traffic, acquisition channels, funnels, compare, exports, event tracking and goals.',
            'version' => 1033,
            'author' => 'Pyxios - Roych (www.pyxios.com)',
            'href' => 'https://processwire.com/talk/topic/31808-native-analytics-%E2%80%94-a-native-analytics-module-for-processwire/',
            'repo' => 'https://github.com/Roychgod/NativeAnalytics',
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

        if($this->wire('config')->admin) {
            $this->maybeHandleMonthlyReportToolRequest();
            $this->maybeHandleMaintenanceToolRequest();
        }

        if(!$this->trackingEnabled) return;

        if($this->wire('config')->admin) {
            if(!empty($this->showPageEditAnalytics)) {
                $this->addHookAfter('ProcessPageEdit::buildForm', $this, 'addPageAnalyticsBox');
            }
            return;
        }

        $mode = $this->getTrackingMode();

        // Server-side recording: only in 'both' or 'server_only' modes.
        // In 'js_first' / 'js_only' we rely on the browser tracker, which boots
        // never fire — eliminating most bot traffic.
        if(($mode === 'both' || $mode === 'server_only') && $this->shouldTrackCurrentRequest()) {
            $this->trackCurrentRequestServerSide();
        }

        // JS tracker injection: in every mode except 'server_only'.
        if($mode !== 'server_only' && $this->shouldInjectTrackerCurrentRequest()) {
            $this->addHookAfter('Page::render', $this, 'injectTracker');
        }
    }

    /**
     * Returns the configured tracking mode, normalized.
     *  - 'js_first'     (default): JS tracker is the source of truth; no server-side recording.
     *                              Best bot resistance. Visitors without JS aren't counted.
     *  - 'both':                   Legacy behaviour. Server-side records every page render AND
     *                              JS tracker fires. Higher bot noise.
     *  - 'server_only':            No JS, server-side only. Maximum coverage, maximum bot noise.
     *  - 'js_only':                Alias of 'js_first' for clarity in the UI.
     */
    public function getTrackingMode() {
        $m = (string) ($this->trackingMode ?? 'js_first');
        if(!in_array($m, ['js_first', 'js_only', 'both', 'server_only'], true)) $m = 'js_first';
        return $m;
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

    public function getAssetVersion($relativePath = '') {
        $version = self::VERSION;
        $relativePath = ltrim((string) $relativePath, '/');
        if($relativePath !== '') {
            $file = __DIR__ . '/' . $relativePath;
            if(is_file($file)) $version .= '-' . filemtime($file);
        }
        return $version;
    }

    public function getTrackEndpointUrl() {
        return $this->wire('config')->urls->root . 'pwna-track/';
    }

    /**
     * Absolute tracking endpoint URL for server-to-server diagnostics.
     * Browser tracking intentionally uses getTrackEndpointUrl(), which may be
     * root-relative. WireHttp, however, requires an absolute HTTP(S) URL.
     */
    protected function getTrackEndpointHttpUrl() {
        $config = $this->wire('config');
        $relative = (string) $this->getTrackEndpointUrl();
        if(preg_match('#^https?://#i', $relative)) return $relative;

        $scheme = !empty($config->https) ? 'https' : 'http';
        $host = trim((string) ($config->httpHost ?? ''));
        if($host === '' && !empty($_SERVER['HTTP_HOST'])) $host = trim((string) $_SERVER['HTTP_HOST']);
        if($host === '') return '';
        // httpHost/HTTP_HOST is used only as a host component; reject values
        // containing characters that could turn this into an arbitrary URL.
        if(!preg_match('/^[a-z0-9.\-\[\]:]+$/i', $host)) return '';
        return $scheme . '://' . $host . '/' . ltrim($relative, '/');
    }

    public function getRealtimeEndpointUrl() {
        return $this->wire('config')->urls->root . 'pwna-realtime/';
    }


    /**
     * Return a CSP nonce for script tags when the site defines one.
     *
     * NativeAnalytics does not create or manage the CSP header. This helper only
     * reuses an existing nonce from a custom $config->cspNonce value/method or
     * from the already prepared Content-Security-Policy header. If no nonce is
     * found, script tags are rendered normally.
     */
    public function getCspNonce() {
        $nonce = '';
        $config = $this->wire('config');

        try {
            if($config && method_exists($config, 'cspNonce')) {
                $value = $config->cspNonce();
                if(is_string($value) && $value !== '') $nonce = $value;
            }
        } catch(\Throwable $e) {
            $nonce = '';
        }

        if($nonce === '' && $config) {
            try {
                $value = $config->get('cspNonce');
                if(is_string($value) && $value !== '') $nonce = $value;
                elseif($value === null) {
                    $value = $config->cspNonce;
                    if(is_string($value) && $value !== '') $nonce = $value;
                }
            } catch(\Throwable $e) {
                $nonce = '';
            }
        }

        if($nonce === '') {
            $headers = function_exists('headers_list') ? headers_list() : [];
            if($headers) {
                $headerText = implode("\n", $headers);
                if(preg_match('#^Content-Security-Policy(?:-Report-Only)?:.*\s(?:script-src|script-src-elem)\s+(?:[^;]+\s)?\'nonce-([A-Za-z0-9+/_=-]+)\'#mi', $headerText, $m)) {
                    $nonce = (string) $m[1];
                }
            }
        }

        $nonce = trim((string) $nonce);
        if($nonce === '' || !preg_match('/^[A-Za-z0-9+\/_=-]+$/', $nonce)) return '';
        return $nonce;
    }

    /**
     * Return a ready-to-insert nonce attribute for script tags.
     */
    public function getScriptNonceAttribute() {
        $nonce = $this->getCspNonce();
        if($nonce === '') return '';
        return ' nonce="' . $this->wire('sanitizer')->entities($nonce) . '"';
    }

    public function install() {
        $this->ensureSchema(true);
        $this->createPermission('nativeanalytics-view', 'View NativeAnalytics');
        $this->createPermission('nativeanalytics-manage', 'Manage NativeAnalytics');
        $this->installDashboardModule();

        // Fresh installs default to JS-first tracking (best bot resistance).
        // Persist this explicitly so it survives later upgrades and so the
        // dashboard reflects the chosen value from the start.
        try {
            $modules = $this->wire('modules');
            $cfg = $modules->getConfig($this);
            if(!isset($cfg['trackingMode']) || $cfg['trackingMode'] === '') {
                $cfg['trackingMode'] = 'js_first';
                $modules->saveConfig($this, $cfg);
            }
        } catch(\Throwable $e) {
            // non-fatal
        }
    }

    /**
     * Upgrade hook called by ProcessWire when the installed version is lower
     * than the version in module info. We use it to apply lightweight schema
     * migrations and to preserve legacy behaviour for existing installations.
     * Existing installs keep the old "server + JS" model unless the user has
     * already selected another tracking mode. New installs (handled in install())
     * get the recommended JS-first mode.
     */
    public function ___upgrade($fromVersion, $toVersion) {
        $this->ensureSchema(true);

        if((int) $fromVersion < 1024) {
            try {
                $modules = $this->wire('modules');
                $cfg = $modules->getConfig($this);
                // Only set if user has never explicitly chosen a mode.
                if(!isset($cfg['trackingMode']) || $cfg['trackingMode'] === '') {
                    $cfg['trackingMode'] = 'both';
                    $modules->saveConfig($this, $cfg);
                    $this->message(
                        'NativeAnalytics: kept legacy "Both (server + JS)" tracking mode. '
                        . 'For dramatically less bot traffic, switch to "JavaScript first" '
                        . 'in the module settings.'
                    );
                }
            } catch(\Throwable $e) {
                // non-fatal
            }
        }
    }

    public function uninstall() {
        $session = $this->wire('session');
        $session->setFor('NativeAnalytics', 'uninstall_mode', 'main-direct');
        try {
            if((string) $session->getFor('NativeAnalytics', 'uninstall_mode') !== 'dashboard-direct') {
                $this->uninstallDashboardModule();
            }
            // Preserve collected analytics by default. This makes an accidental
            // uninstall/reinstall recoverable. Administrators can explicitly opt
            // into destructive removal in the module settings before uninstalling.
            if(!empty($this->deleteDataOnUninstall)) {
                $db = $this->wire('database');
                foreach([self::GOAL_DAILY_TABLE, self::GOALS_TABLE, self::EVENT_DAILY_TABLE, self::EVENTS_TABLE, self::ATTRIBUTION_TABLE, self::SESSIONS_TABLE, self::DAILY_TABLE, self::HITS_TABLE] as $table) {
                    $db->exec("DROP TABLE IF EXISTS `{$table}`");
                }
                $this->wire('log')->save('native-analytics', 'Module uninstalled and analytics tables removed by explicit configuration.');
            } else {
                $this->wire('log')->save('native-analytics', 'Module uninstalled; analytics tables were preserved for a future reinstall.');
            }
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

        // This module is autoloaded, so init() runs on every request. Persist the
        // module version that last completed the schema sweep and skip the expensive
        // SHOW COLUMNS / SHOW INDEX checks until the module version changes. Install
        // and upgrade paths still call ensureSchema(true), so lifecycle migrations
        // always force a complete schema check.
        $moduleVersion = (int) $this->wire('modules')->getModuleInfoProperty($this, 'version');
        if(!$force && $moduleVersion > 0 && (int) $this->schemaCheckedVersion === $moduleVersion) {
            $done = true;
            return;
        }

        $db = $this->wire('database');
        $schemaOk = true;

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
            `utm_term` VARCHAR(191) NOT NULL DEFAULT '',
            `utm_content` VARCHAR(191) NOT NULL DEFAULT '',
            `device_type` VARCHAR(32) NOT NULL DEFAULT '',
            `browser` VARCHAR(64) NOT NULL DEFAULT '',
            `os` VARCHAR(64) NOT NULL DEFAULT '',
            `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
            `visitor_hash` CHAR(64) NOT NULL,
            `session_hash` CHAR(64) NOT NULL,
            `ip_hash` CHAR(64) NOT NULL,
            `is_bot` TINYINT(1) NOT NULL DEFAULT 0,
            `bot_reason` VARCHAR(64) NOT NULL DEFAULT '',
            `status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 200,
            `time_on_page` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
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
            `is_bot` TINYINT(1) NOT NULL DEFAULT 0,
            `bot_reason` VARCHAR(64) NOT NULL DEFAULT '',
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

        $db->exec("CREATE TABLE IF NOT EXISTS `" . self::EVENT_DAILY_TABLE . "` (
            `day` DATE NOT NULL,
            `page_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `template` VARCHAR(128) NOT NULL DEFAULT '',
            `event_group` VARCHAR(64) NOT NULL DEFAULT '',
            `event_name` VARCHAR(128) NOT NULL DEFAULT '',
            `event_label` VARCHAR(255) NOT NULL DEFAULT '',
            `event_label_hash` CHAR(32) NOT NULL,
            `event_target` VARCHAR(767) NOT NULL DEFAULT '',
            `event_target_hash` CHAR(32) NOT NULL,
            `events` INT UNSIGNED NOT NULL DEFAULT 0,
            `uniques` INT UNSIGNED NOT NULL DEFAULT 0,
            `sessions` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`day`, `event_group`, `event_name`, `event_label_hash`, `event_target_hash`, `page_id`),
            KEY `day_group_name` (`day`, `event_group`, `event_name`),
            KEY `page_id` (`page_id`),
            KEY `template` (`template`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `" . self::GOALS_TABLE . "` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(191) NOT NULL DEFAULT '',
            `goal_type` VARCHAR(16) NOT NULL DEFAULT 'event',
            `event_group` VARCHAR(64) NOT NULL DEFAULT '',
            `event_name` VARCHAR(128) NOT NULL DEFAULT '',
            `event_label_contains` VARCHAR(191) NOT NULL DEFAULT '',
            `event_target_contains` VARCHAR(191) NOT NULL DEFAULT '',
            `path_contains` VARCHAR(191) NOT NULL DEFAULT '',
            `conversion_base` VARCHAR(16) NOT NULL DEFAULT 'sessions',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `active` (`active`),
            KEY `goal_type` (`goal_type`),
            KEY `event_group_name` (`event_group`, `event_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `" . self::GOAL_DAILY_TABLE . "` (
            `day` DATE NOT NULL,
            `goal_id` INT UNSIGNED NOT NULL,
            `conversions` INT UNSIGNED NOT NULL DEFAULT 0,
            `uniques` INT UNSIGNED NOT NULL DEFAULT 0,
            `sessions` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`day`, `goal_id`),
            KEY `goal_id` (`goal_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // First-touch acquisition attribution, one row per session. Populated
        // via INSERT IGNORE on the first hit of each session so it captures the
        // channel/campaign that originally brought the visitor. Lets reports
        // attribute a whole session's pageviews and goal conversions to its
        // acquisition source, not just the single landing hit.
        $db->exec("CREATE TABLE IF NOT EXISTS `" . self::ATTRIBUTION_TABLE . "` (
            `session_hash` CHAR(64) NOT NULL,
            `visitor_hash` CHAR(64) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            `created_date` DATE NOT NULL,
            `channel` VARCHAR(32) NOT NULL DEFAULT '',
            `utm_source` VARCHAR(191) NOT NULL DEFAULT '',
            `utm_medium` VARCHAR(191) NOT NULL DEFAULT '',
            `utm_campaign` VARCHAR(191) NOT NULL DEFAULT '',
            `utm_term` VARCHAR(191) NOT NULL DEFAULT '',
            `utm_content` VARCHAR(191) NOT NULL DEFAULT '',
            `referrer_host` VARCHAR(191) NOT NULL DEFAULT '',
            `landing_path` VARCHAR(767) NOT NULL DEFAULT '',
            `landing_path_hash` CHAR(32) NOT NULL DEFAULT '',
            `is_bot` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`session_hash`),
            KEY `created_channel` (`created_at`, `channel`),
            KEY `created_campaign` (`created_at`, `utm_source`, `utm_medium`, `utm_campaign`),
            KEY `created_date` (`created_date`),
            KEY `visitor_hash` (`visitor_hash`),
            KEY `bot_created` (`is_bot`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if(!$this->ensureLegacySchemaColumns()) $schemaOk = false;

        if(!$this->ensureIndex(self::HITS_TABLE, 'created_status', '`created_at`, `status_code`')) $schemaOk = false;
        if(!$this->ensureIndex(self::HITS_TABLE, 'created_page', '`created_at`, `page_id`')) $schemaOk = false;
        if(!$this->ensureIndex(self::HITS_TABLE, 'created_template', '`created_at`, `template`')) $schemaOk = false;
        if(!$this->ensureIndex(self::HITS_TABLE, 'created_path', '`created_at`, `path_hash`')) $schemaOk = false;
        if(!$this->ensureIndex(self::HITS_TABLE, 'created_session', '`created_at`, `session_hash`')) $schemaOk = false;
        // Almost every report and every daily-aggregate rebuild filters
        // `is_bot = 0` over a `created_at` range. Equality column first, range
        // second is the optimal order for that access pattern.
        if(!$this->ensureIndex(self::HITS_TABLE, 'bot_created', '`is_bot`, `created_at`')) $schemaOk = false;
        // Serves the ip_excessive bot rule (GROUP BY ip_hash, created_date,
        // created_hour) and the IP-based 404/suspicious-path cleanup deletes.
        // The hits table previously had no ip_hash index at all.
        if(!$this->ensureIndex(self::HITS_TABLE, 'ip_created', '`ip_hash`, `created_date`, `created_hour`')) $schemaOk = false;
        if(!$this->ensureIndex(self::EVENTS_TABLE, 'created_group_name', '`created_at`, `event_group`, `event_name`')) $schemaOk = false;
        if(!$this->ensureIndex(self::EVENTS_TABLE, 'created_page', '`created_at`, `page_id`')) $schemaOk = false;
        if(!$this->ensureIndex(self::EVENTS_TABLE, 'created_template', '`created_at`, `template`')) $schemaOk = false;
        if(!$this->ensureIndex(self::EVENTS_TABLE, 'created_session', '`created_at`, `session_hash`')) $schemaOk = false;
        if(!$this->ensureIndex(self::EVENTS_TABLE, 'bot_created', '`is_bot`, `created_at`')) $schemaOk = false;

        $done = true;

        // Only mark this version as checked when every recoverable schema operation
        // succeeded. A transient ALTER/INDEX/backfill failure is then retried on the
        // next request instead of being hidden until the next release.
        if($schemaOk && $moduleVersion > 0 && (int) $this->schemaCheckedVersion !== $moduleVersion) {
            $this->saveConfigValue('schemaCheckedVersion', $moduleVersion);
        }
    }

    /**
     * Older test releases created some tables before all current columns existed.
     * CREATE TABLE IF NOT EXISTS is not enough for upgrades, so keep the schema
     * self-healing by adding missing columns before indexes are created.
     */
    protected function ensureLegacySchemaColumns() {
        $definitions = [
            self::HITS_TABLE => [
                'created_date' => "`created_date` DATE NOT NULL DEFAULT '1970-01-01' AFTER `created_at`",
                'created_hour' => "`created_hour` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `created_date`",
                'page_id' => "`page_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `created_hour`",
                'page_title' => "`page_title` VARCHAR(255) NOT NULL DEFAULT '' AFTER `page_id`",
                'template' => "`template` VARCHAR(128) NOT NULL DEFAULT '' AFTER `page_title`",
                'url' => "`url` VARCHAR(767) NOT NULL DEFAULT '' AFTER `template`",
                'path' => "`path` VARCHAR(767) NOT NULL DEFAULT '' AFTER `url`",
                'path_hash' => "`path_hash` CHAR(32) NOT NULL DEFAULT '' AFTER `path`",
                'referrer_host' => "`referrer_host` VARCHAR(191) NOT NULL DEFAULT '' AFTER `path_hash`",
                'referrer_url' => "`referrer_url` VARCHAR(767) NOT NULL DEFAULT '' AFTER `referrer_host`",
                'search_term' => "`search_term` VARCHAR(255) NOT NULL DEFAULT '' AFTER `referrer_url`",
                'utm_source' => "`utm_source` VARCHAR(191) NOT NULL DEFAULT '' AFTER `search_term`",
                'utm_medium' => "`utm_medium` VARCHAR(191) NOT NULL DEFAULT '' AFTER `utm_source`",
                'utm_campaign' => "`utm_campaign` VARCHAR(191) NOT NULL DEFAULT '' AFTER `utm_medium`",
                'utm_term' => "`utm_term` VARCHAR(191) NOT NULL DEFAULT '' AFTER `utm_campaign`",
                'utm_content' => "`utm_content` VARCHAR(191) NOT NULL DEFAULT '' AFTER `utm_term`",
                'device_type' => "`device_type` VARCHAR(32) NOT NULL DEFAULT '' AFTER `utm_campaign`",
                'browser' => "`browser` VARCHAR(64) NOT NULL DEFAULT '' AFTER `device_type`",
                'os' => "`os` VARCHAR(64) NOT NULL DEFAULT '' AFTER `browser`",
                'user_agent' => "`user_agent` VARCHAR(255) NOT NULL DEFAULT '' AFTER `os`",
                'visitor_hash' => "`visitor_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `user_agent`",
                'session_hash' => "`session_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `visitor_hash`",
                'ip_hash' => "`ip_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `session_hash`",
                'is_bot' => "`is_bot` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ip_hash`",
                'bot_reason' => "`bot_reason` VARCHAR(64) NOT NULL DEFAULT '' AFTER `is_bot`",
                'status_code' => "`status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 200 AFTER `bot_reason`",
                'time_on_page' => "`time_on_page` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `status_code`",
            ],
            self::DAILY_TABLE => [
                'page_title' => "`page_title` VARCHAR(255) NOT NULL DEFAULT '' AFTER `page_id`",
                'template' => "`template` VARCHAR(128) NOT NULL DEFAULT '' AFTER `page_title`",
                'path' => "`path` VARCHAR(767) NOT NULL DEFAULT '' AFTER `template`",
                'path_hash' => "`path_hash` CHAR(32) NOT NULL DEFAULT '' AFTER `path`",
                'views' => "`views` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `path_hash`",
                'uniques' => "`uniques` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `views`",
                'sessions' => "`sessions` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `uniques`",
            ],
            self::SESSIONS_TABLE => [
                'visitor_hash' => "`visitor_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `session_hash`",
                'first_seen_at' => "`first_seen_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER `visitor_hash`",
                'last_seen_at' => "`last_seen_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER `first_seen_at`",
                'page_id' => "`page_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_seen_at`",
                'page_title' => "`page_title` VARCHAR(255) NOT NULL DEFAULT '' AFTER `page_id`",
                'template' => "`template` VARCHAR(128) NOT NULL DEFAULT '' AFTER `page_title`",
                'current_url' => "`current_url` VARCHAR(767) NOT NULL DEFAULT '' AFTER `template`",
                'current_path' => "`current_path` VARCHAR(767) NOT NULL DEFAULT '' AFTER `current_url`",
                'current_path_hash' => "`current_path_hash` CHAR(32) NOT NULL DEFAULT '' AFTER `current_path`",
                'referrer_host' => "`referrer_host` VARCHAR(191) NOT NULL DEFAULT '' AFTER `current_path_hash`",
                'referrer_url' => "`referrer_url` VARCHAR(767) NOT NULL DEFAULT '' AFTER `referrer_host`",
                'device_type' => "`device_type` VARCHAR(32) NOT NULL DEFAULT '' AFTER `referrer_url`",
                'browser' => "`browser` VARCHAR(64) NOT NULL DEFAULT '' AFTER `device_type`",
                'os' => "`os` VARCHAR(64) NOT NULL DEFAULT '' AFTER `browser`",
                'hit_count' => "`hit_count` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `os`",
                'status_code' => "`status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 200 AFTER `hit_count`",
            ],
            self::EVENTS_TABLE => [
                'created_date' => "`created_date` DATE NOT NULL DEFAULT '1970-01-01' AFTER `created_at`",
                'created_hour' => "`created_hour` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `created_date`",
                'page_id' => "`page_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `created_hour`",
                'page_title' => "`page_title` VARCHAR(255) NOT NULL DEFAULT '' AFTER `page_id`",
                'template' => "`template` VARCHAR(128) NOT NULL DEFAULT '' AFTER `page_title`",
                'url' => "`url` VARCHAR(767) NOT NULL DEFAULT '' AFTER `template`",
                'path' => "`path` VARCHAR(767) NOT NULL DEFAULT '' AFTER `url`",
                'path_hash' => "`path_hash` CHAR(32) NOT NULL DEFAULT '' AFTER `path`",
                'event_group' => "`event_group` VARCHAR(64) NOT NULL DEFAULT '' AFTER `path_hash`",
                'event_name' => "`event_name` VARCHAR(128) NOT NULL DEFAULT '' AFTER `event_group`",
                'event_label' => "`event_label` VARCHAR(255) NOT NULL DEFAULT '' AFTER `event_name`",
                'event_target' => "`event_target` VARCHAR(767) NOT NULL DEFAULT '' AFTER `event_label`",
                'extra_json' => "`extra_json` MEDIUMTEXT NULL AFTER `event_target`",
                'visitor_hash' => "`visitor_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `extra_json`",
                'session_hash' => "`session_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `visitor_hash`",
                'is_bot' => "`is_bot` TINYINT(1) NOT NULL DEFAULT 0 AFTER `session_hash`",
                'bot_reason' => "`bot_reason` VARCHAR(64) NOT NULL DEFAULT '' AFTER `is_bot`",
            ],
            self::EVENT_DAILY_TABLE => [
                'page_id' => "`page_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `day`",
                'template' => "`template` VARCHAR(128) NOT NULL DEFAULT '' AFTER `page_id`",
                'event_group' => "`event_group` VARCHAR(64) NOT NULL DEFAULT '' AFTER `template`",
                'event_name' => "`event_name` VARCHAR(128) NOT NULL DEFAULT '' AFTER `event_group`",
                'event_label' => "`event_label` VARCHAR(255) NOT NULL DEFAULT '' AFTER `event_name`",
                'event_label_hash' => "`event_label_hash` CHAR(32) NOT NULL DEFAULT '' AFTER `event_label`",
                'event_target' => "`event_target` VARCHAR(767) NOT NULL DEFAULT '' AFTER `event_label_hash`",
                'event_target_hash' => "`event_target_hash` CHAR(32) NOT NULL DEFAULT '' AFTER `event_target`",
                'events' => "`events` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `event_target_hash`",
                'uniques' => "`uniques` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `events`",
                'sessions' => "`sessions` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `uniques`",
            ],
            self::GOALS_TABLE => [
                'title' => "`title` VARCHAR(191) NOT NULL DEFAULT '' AFTER `id`",
                'goal_type' => "`goal_type` VARCHAR(16) NOT NULL DEFAULT 'event' AFTER `title`",
                'event_group' => "`event_group` VARCHAR(64) NOT NULL DEFAULT '' AFTER `goal_type`",
                'event_name' => "`event_name` VARCHAR(128) NOT NULL DEFAULT '' AFTER `event_group`",
                'event_label_contains' => "`event_label_contains` VARCHAR(191) NOT NULL DEFAULT '' AFTER `event_name`",
                'event_target_contains' => "`event_target_contains` VARCHAR(191) NOT NULL DEFAULT '' AFTER `event_label_contains`",
                'path_contains' => "`path_contains` VARCHAR(191) NOT NULL DEFAULT '' AFTER `event_target_contains`",
                'conversion_base' => "`conversion_base` VARCHAR(16) NOT NULL DEFAULT 'sessions' AFTER `path_contains`",
                'active' => "`active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `conversion_base`",
                'created_at' => "`created_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER `active`",
                'updated_at' => "`updated_at` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER `created_at`",
            ],
            self::GOAL_DAILY_TABLE => [
                'conversions' => "`conversions` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `goal_id`",
                'uniques' => "`uniques` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `conversions`",
                'sessions' => "`sessions` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `uniques`",
            ],
        ];

        $addedColumn = false;
        $schemaOk = true;
        foreach($definitions as $table => $columns) {
            foreach($columns as $column => $definition) {
                $result = $this->ensureColumn($table, $column, $definition);
                if($result > 0) $addedColumn = true;
                elseif($result < 0) $schemaOk = false;
            }
        }

        if(!$this->ensureIndex(self::HITS_TABLE, 'is_bot', '`is_bot`')) $schemaOk = false;
        if(!$this->ensureIndex(self::EVENTS_TABLE, 'is_bot', '`is_bot`')) $schemaOk = false;

        // If a backfill is interrupted (for example by a deadlock), keep a small
        // persistent pending flag so it is retried even though the added columns
        // already exist on the next request.
        if($addedColumn) {
            $this->saveConfigValue('schemaBackfillPending', 1);
        }
        if($addedColumn || !empty($this->schemaBackfillPending)) {
            if($this->backfillLegacySchemaValues()) {
                $this->saveConfigValue('schemaBackfillPending', 0);
            } else {
                $schemaOk = false;
            }
        }

        return $schemaOk;
    }

    /**
     * Ensure a legacy column exists.
     *
     * @return int 1 when added, 0 when already present, -1 on failure.
     */
    protected function ensureColumn($table, $column, $definition) {
        $table = preg_replace('/[^a-zA-Z0-9_]+/', '', (string) $table);
        $column = preg_replace('/[^a-zA-Z0-9_]+/', '', (string) $column);
        if($table === '' || $column === '' || trim((string) $definition) === '') return -1;
        try {
            $db = $this->wire('database');
            $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column");
            $stmt->execute([':column' => $column]);
            if($stmt->fetch(\PDO::FETCH_ASSOC)) return 0;

            try {
                $db->exec("ALTER TABLE `{$table}` ADD {$definition}");
            } catch(\Throwable $firstError) {
                // Some very old test schemas may not have the column referenced
                // in an AFTER clause. Retry without column positioning.
                $fallbackDefinition = preg_replace('/\s+AFTER\s+`[^`]+`/i', '', (string) $definition);
                if($fallbackDefinition !== $definition) {
                    $db->exec("ALTER TABLE `{$table}` ADD {$fallbackDefinition}");
                } else {
                    throw $firstError;
                }
            }
            return 1;
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Column ensure failed for ' . $table . '.' . $column . ': ' . $e->getMessage());
        }
        return -1;
    }

    protected function backfillLegacySchemaValues() {
        $db = $this->wire('database');
        $ok = true;
        $updates = [
            "UPDATE `" . self::HITS_TABLE . "` SET `created_date` = DATE(`created_at`) WHERE (`created_date` = '1970-01-01' OR `created_date` IS NULL) AND DATE(`created_at`) <> '1970-01-01' AND `created_at` IS NOT NULL",
            "UPDATE `" . self::HITS_TABLE . "` SET `created_hour` = HOUR(`created_at`) WHERE `created_hour` = 0 AND HOUR(`created_at`) <> 0 AND `created_at` IS NOT NULL",
            "UPDATE `" . self::HITS_TABLE . "` SET `path_hash` = MD5(`path`) WHERE (`path_hash` = '' OR `path_hash` IS NULL) AND `path` <> ''",
            "UPDATE `" . self::HITS_TABLE . "` SET `status_code` = 200 WHERE `status_code` IS NULL OR `status_code` < 100",
            "UPDATE `" . self::SESSIONS_TABLE . "` SET `current_path_hash` = MD5(`current_path`) WHERE (`current_path_hash` = '' OR `current_path_hash` IS NULL) AND `current_path` <> ''",
            "UPDATE `" . self::SESSIONS_TABLE . "` SET `status_code` = 200 WHERE `status_code` IS NULL OR `status_code` < 100",
            "UPDATE `" . self::EVENTS_TABLE . "` SET `created_date` = DATE(`created_at`) WHERE (`created_date` = '1970-01-01' OR `created_date` IS NULL) AND DATE(`created_at`) <> '1970-01-01' AND `created_at` IS NOT NULL",
            "UPDATE `" . self::EVENTS_TABLE . "` SET `created_hour` = HOUR(`created_at`) WHERE `created_hour` = 0 AND HOUR(`created_at`) <> 0 AND `created_at` IS NOT NULL",
            "UPDATE `" . self::EVENTS_TABLE . "` SET `path_hash` = MD5(`path`) WHERE (`path_hash` = '' OR `path_hash` IS NULL) AND `path` <> ''",
        ];
        foreach($updates as $sql) {
            try {
                $db->exec($sql);
            } catch(\Throwable $e) {
                $ok = false;
                $this->wire('log')->save('native-analytics', 'Legacy schema backfill failed: ' . $e->getMessage());
            }
        }
        return $ok;
    }

    protected function ensureIndex($table, $name, $columns) {
        $table = preg_replace('/[^a-zA-Z0-9_]+/', '', (string) $table);
        $name = preg_replace('/[^a-zA-Z0-9_]+/', '', (string) $name);
        if($table === '' || $name === '' || trim((string) $columns) === '') return false;
        try {
            $db = $this->wire('database');
            $stmt = $db->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = :name");
            $stmt->execute([':name' => $name]);
            if($stmt->fetch(\PDO::FETCH_ASSOC)) return true;
            $db->exec("ALTER TABLE `{$table}` ADD KEY `{$name}` ({$columns})");
            return true;
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Index ensure failed for ' . $table . '.' . $name . ': ' . $e->getMessage());
        }
        return false;
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
            'schemaCheckedVersion' => 0,
            'schemaBackfillPending' => 0,
            'trackingEnabled' => 1,
            'respectDnt' => 1,
            'requireConsent' => 0,
            'consentCookieName' => 'pwna_consent',
            'rawRetentionDays' => 90,
            'rawEventRetentionDays' => 180,
            'optimizeAfterPurge' => 1,
            'lastPurgeAt' => '',
            'lastOptimizeAt' => '',
            'highTrafficMode' => 0,
            'realtimeWindowMinutes' => 5,
            'realtimeRefreshSeconds' => 10,
            'funnelSteps' => '',
            'ignoreQueryString' => 1,
            'excludeRoles' => ['superuser'],
            'excludePaths' => "/processwire/\n/admin/\n/404/",
            'suspiciousPathPatterns' => '',
            'ipBlocklist' => '',
            'searchQueryVars' => 'q,s,search',
            'dashboardDefaultRange' => '30d',
            'showPageEditAnalytics' => 1,
            'hashSalt' => '',
            'eventTrackingEnabled' => 1,
            'trackingStorageMode' => 'cookie',
            'privacyWireAutoConsent' => 0,
            'privacyWireStorageKey' => 'privacywire',
            'privacyWireGroups' => 'statistics,marketing',
            'privacyWireConsentCookieMaxAge' => 31536000,
            'displayDateFormat' => 'site_default',
            'monthlyReportsEnabled' => 0,
            'monthlyReportRecipients' => '',
            'monthlyReportSendDay' => 1,
            'monthlyReportFromEmail' => '',
            'monthlyReportAttachPdf' => 1,
            'monthlyReportIncludeTopPages' => 1,
            'monthlyReportIncludeReferrers' => 1,
            'monthlyReportIncludeChannels' => 1,
            'monthlyReportIncludeEvents' => 1,
            'monthlyReportLastSentPeriod' => '',
            'trackingMode' => 'js_first',
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

        // Bot detection status notice
        // Three possible states:
        //   - composer  : site has matomo/device-detector via Composer (gets updates via composer update)
        //   - bundled   : library is loaded from the version shipped inside this module
        //   - missing   : neither is available (should not happen in normal installs; bundled lib is shipped)
        $ddState = 'missing';
        // Probe Composer first
        if(!class_exists('DeviceDetector\\DeviceDetector')) {
            foreach([
                $wire->config->paths->site . 'vendor/autoload.php',
                $wire->config->paths->root . 'vendor/autoload.php',
            ] as $cand) {
                if(is_file($cand)) {
                    try { require_once $cand; } catch(\Throwable $e) {}
                    if(class_exists('DeviceDetector\\DeviceDetector')) { $ddState = 'composer'; break; }
                }
            }
        } else {
            $ddState = 'composer';
        }
        // Fallback to bundled
        if($ddState === 'missing') {
            $bundledBoot = __DIR__ . '/lib/bootstrap.php';
            if(is_file($bundledBoot)) {
                try { require_once $bundledBoot; } catch(\Throwable $e) {}
                if(class_exists('DeviceDetector\\DeviceDetector')) $ddState = 'bundled';
            }
        }

        $f = $wire->modules->get('InputfieldMarkup');
        $f->name = 'botDetectionStatus';
        $f->label = 'Bot detection';
        $styleBlock = '<style>'
            . '.pwna-bd-box{margin:0;padding:10px 14px;border-left:3px solid;border-radius:3px;line-height:1.5;}'
            . '.pwna-bd-box code{padding:2px 6px;border-radius:3px;font-size:.9em;}'
            . '.pwna-bd-box .pwna-bd-cmd{display:inline-block;margin-top:8px;padding:6px 10px;font-size:.9em;}'
            // OK (matomo present) — light defaults
            . '.pwna-bd-ok{background:#e8f5e9;color:#1b3a1d;border-left-color:#2e7d32;}'
            . '.pwna-bd-ok code{background:rgba(46,125,50,.12);color:#1b3a1d;}'
            // INFO (bundled) — light defaults
            . '.pwna-bd-info{background:#e3f2fd;color:#0d3a5e;border-left-color:#1565c0;}'
            . '.pwna-bd-info code{background:rgba(21,101,192,.12);color:#0d3a5e;}'
            . '.pwna-bd-info .pwna-bd-cmd{background:#fff;color:#222;border:1px solid #b8d4ec;}'
            // WARN (missing) — light defaults
            . '.pwna-bd-warn{background:#fff3e0;color:#3d2a05;border-left-color:#ef6c00;}'
            . '.pwna-bd-warn code{background:rgba(239,108,0,.12);color:#3d2a05;}'
            . '.pwna-bd-warn .pwna-bd-cmd{background:#fff;color:#222;border:1px solid #d8c4a4;}'
            // Dark mode (browsers honoring prefers-color-scheme)
            . '@media (prefers-color-scheme: dark){'
            . '  .pwna-bd-ok{background:#1b3a1d;color:#d6f0d8;border-left-color:#66bb6a;}'
            . '  .pwna-bd-ok code{background:rgba(102,187,106,.18);color:#d6f0d8;}'
            . '  .pwna-bd-info{background:#0d3a5e;color:#d6e6f5;border-left-color:#42a5f5;}'
            . '  .pwna-bd-info code{background:rgba(66,165,245,.18);color:#d6e6f5;}'
            . '  .pwna-bd-info .pwna-bd-cmd{background:#0a2742;color:#d6e6f5;border-color:#1d4d7a;}'
            . '  .pwna-bd-warn{background:#3d2a05;color:#ffe7c2;border-left-color:#ffa726;}'
            . '  .pwna-bd-warn code{background:rgba(255,167,38,.18);color:#ffe7c2;}'
            . '  .pwna-bd-warn .pwna-bd-cmd{background:#2a1f08;color:#ffe7c2;border-color:#5a431a;}'
            . '}'
            // PW dark theme classes
            . 'html.pw-theme-dark .pwna-bd-ok, body.pw-theme-dark .pwna-bd-ok,'
            . 'html.AdminThemeUikitDark .pwna-bd-ok, body.AdminThemeUikitDark .pwna-bd-ok'
            . '{background:#1b3a1d;color:#d6f0d8;border-left-color:#66bb6a;}'
            . 'html.pw-theme-dark .pwna-bd-ok code, body.pw-theme-dark .pwna-bd-ok code,'
            . 'html.AdminThemeUikitDark .pwna-bd-ok code, body.AdminThemeUikitDark .pwna-bd-ok code'
            . '{background:rgba(102,187,106,.18);color:#d6f0d8;}'
            . 'html.pw-theme-dark .pwna-bd-info, body.pw-theme-dark .pwna-bd-info,'
            . 'html.AdminThemeUikitDark .pwna-bd-info, body.AdminThemeUikitDark .pwna-bd-info'
            . '{background:#0d3a5e;color:#d6e6f5;border-left-color:#42a5f5;}'
            . 'html.pw-theme-dark .pwna-bd-info code, body.pw-theme-dark .pwna-bd-info code,'
            . 'html.AdminThemeUikitDark .pwna-bd-info code, body.AdminThemeUikitDark .pwna-bd-info code'
            . '{background:rgba(66,165,245,.18);color:#d6e6f5;}'
            . 'html.pw-theme-dark .pwna-bd-info .pwna-bd-cmd, body.pw-theme-dark .pwna-bd-info .pwna-bd-cmd,'
            . 'html.AdminThemeUikitDark .pwna-bd-info .pwna-bd-cmd, body.AdminThemeUikitDark .pwna-bd-info .pwna-bd-cmd'
            . '{background:#0a2742;color:#d6e6f5;border-color:#1d4d7a;}'
            . 'html.pw-theme-dark .pwna-bd-warn, body.pw-theme-dark .pwna-bd-warn,'
            . 'html.AdminThemeUikitDark .pwna-bd-warn, body.AdminThemeUikitDark .pwna-bd-warn'
            . '{background:#3d2a05;color:#ffe7c2;border-left-color:#ffa726;}'
            . 'html.pw-theme-dark .pwna-bd-warn code, body.pw-theme-dark .pwna-bd-warn code,'
            . 'html.AdminThemeUikitDark .pwna-bd-warn code, body.AdminThemeUikitDark .pwna-bd-warn code'
            . '{background:rgba(255,167,38,.18);color:#ffe7c2;}'
            . 'html.pw-theme-dark .pwna-bd-warn .pwna-bd-cmd, body.pw-theme-dark .pwna-bd-warn .pwna-bd-cmd,'
            . 'html.AdminThemeUikitDark .pwna-bd-warn .pwna-bd-cmd, body.AdminThemeUikitDark .pwna-bd-warn .pwna-bd-cmd'
            . '{background:#2a1f08;color:#ffe7c2;border-color:#5a431a;}'
            . '</style>';
        // Try to fetch matomo/device-detector version info (current + latest available)
        // for an inline update notice. Done via a non-static call to the module instance.
        $versionInfo = [
            'current' => '',
            'latest' => '',
            'latest_url' => '',
            'update_available' => false,
            'check_failed' => false,
        ];
        try {
            $moduleInstance = $wire->modules->get('NativeAnalytics');
            if($moduleInstance && method_exists($moduleInstance, 'getDeviceDetectorVersionInfo')) {
                $versionInfo = array_merge($versionInfo, $moduleInstance->getDeviceDetectorVersionInfo());
            }
        } catch(\Throwable $e) {
            // ignore
        }

        $currentVer = $versionInfo['current'];
        $latestVer = $versionInfo['latest'];
        $updateAvailable = !empty($versionInfo['update_available']);

        // Build optional "update available" sub-block reused across composer/bundled states.
        $updateNotice = '';
        if($updateAvailable && $latestVer !== '') {
            $latestUrl = $versionInfo['latest_url'] ?: 'https://github.com/matomo-org/device-detector/releases';
            $latestSafe = htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8');
            $currentSafe = htmlspecialchars($currentVer, ENT_QUOTES, 'UTF-8');
            $urlSafe = htmlspecialchars($latestUrl, ENT_QUOTES, 'UTF-8');
            $updateNotice = '<br><br><strong>↑ Update available:</strong> '
                . "your installed version <code>{$currentSafe}</code> is older than the latest release <code>{$latestSafe}</code>. ";
            if($ddState === 'composer') {
                $updateNotice .= 'Run <code class="pwna-bd-cmd">composer update matomo/device-detector</code> to upgrade.';
            } elseif($ddState === 'bundled') {
                $updateNotice .= '<a href="' . $urlSafe . '" target="_blank" rel="noopener">View release notes ↗</a>. '
                    . '<details style="margin-top:8px;"><summary style="cursor:pointer;"><strong>How to update the bundled copy manually</strong></summary>'
                    . '<ol style="margin:8px 0 0 18px;padding:0;line-height:1.6;">'
                    . '<li>Download the latest source archive from <a href="' . $urlSafe . '" target="_blank" rel="noopener">' . $latestSafe . ' release page</a> (the <em>Source code (zip)</em> link).</li>'
                    . '<li>Extract it on your computer. You will get a folder like <code>device-detector-' . $latestSafe . '/</code>.</li>'
                    . '<li>Replace the contents of <code>/site/modules/NativeAnalytics/lib/matomo-device-detector/</code> with the contents of the extracted folder (keep the <code>lib/bootstrap.php</code> and <code>lib/spyc/</code> folder untouched).</li>'
                    . '<li>Clear the ProcessWire module cache (<em>Modules → Refresh</em>) and reload this page.</li>'
                    . '</ol></details>';
            }
        }

        // "Up to date" suffix only when we successfully checked and current >= latest.
        $upToDateSuffix = '';
        if(!$updateAvailable && $latestVer !== '' && $currentVer !== '' && !$versionInfo['check_failed']) {
            $latestSafe = htmlspecialchars($latestVer, ENT_QUOTES, 'UTF-8');
            $upToDateSuffix = " <span style=\"opacity:.75\">(up to date — latest release is <code>{$latestSafe}</code>)</span>";
        }
        $currentSafe = htmlspecialchars($currentVer, ENT_QUOTES, 'UTF-8');
        $versionTag = $currentVer !== '' ? " <code>{$currentSafe}</code>" : '';

        if($ddState === 'composer') {
            $f->value = $styleBlock . '<p class="pwna-bd-box pwna-bd-ok"><strong>✓ matomo/device-detector loaded via Composer' . $versionTag . '.</strong>'
                . $upToDateSuffix
                . ' NativeAnalytics is using the site-wide installation.'
                . $updateNotice
                . '</p>';
        } elseif($ddState === 'bundled') {
            $f->value = $styleBlock . '<p class="pwna-bd-box pwna-bd-info"><strong>✓ matomo/device-detector loaded from the bundled copy' . $versionTag . '.</strong>'
                . $upToDateSuffix
                . ' NativeAnalytics is using the version shipped with this module. For automatic updates between module releases, install the library site-wide:<br><code class="pwna-bd-cmd">composer require matomo/device-detector</code><br>The site-wide copy will take precedence over the bundled one automatically.'
                . $updateNotice
                . '</p>';
        } else {
            $f->value = $styleBlock . '<p class="pwna-bd-box pwna-bd-warn"><strong>⚠ matomo/device-detector not available.</strong> The bundled <code>lib/</code> folder may have been removed or corrupted. Reinstall the module, or install the library site-wide:<br><code class="pwna-bd-cmd">composer require matomo/device-detector</code></p>';
        }
        $f->showIf = 'trackingEnabled=1';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldSelect');
        $f->name = 'trackingMode';
        $f->label = 'Tracking mode (bot resistance)';
        $f->addOptions([
            'js_first' => 'JavaScript first — recommended (most bot-resistant)',
            'both' => 'Both — server-side + JavaScript (legacy, more bot noise)',
            'server_only' => 'Server-side only — no JavaScript tracker (maximum coverage, maximum bot noise)',
        ]);
        $f->value = in_array((string) ($data['trackingMode'] ?? 'js_first'), ['js_first', 'both', 'server_only'], true) ? (string) $data['trackingMode'] : 'js_first';
        $f->showIf = 'trackingEnabled=1';
        $f->description = "JS-first means the browser tracker is the source of truth — bots that don't execute JavaScript are filtered out automatically. This typically reduces bot traffic by 60–80%. Visitors with JavaScript disabled won't be counted. 'Both' is the legacy behaviour: every page render is logged server-side AND the JS tracker fires. 'Server only' counts every render (including bots) — useful only if you need to track non-JS visitors.";
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
        $f->notes = 'Minimum 7 days.';
        $f->description = 'How long individual page-view rows are kept. Rows older than this are deleted automatically every day so the database stops growing. Aggregated daily stats are kept forever, so historical charts stay intact. Lower this (e.g. 30) if the database file is getting large.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldInteger');
        $f->name = 'rawEventRetentionDays';
        $f->showIf = 'trackingEnabled=1, eventTrackingEnabled=1';
        $f->label = 'Raw event retention (days)';
        $f->value = (int) ($data['rawEventRetentionDays'] ?? 180);
        $f->min = 7;
        $f->notes = 'Minimum 7 days.';
        $f->description = 'How long individual engagement-event rows are kept before automatic daily cleanup removes them. Event and goal daily aggregates remain available for long-term reporting.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'optimizeAfterPurge';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Reclaim disk space after cleanup';
        $f->checked = !empty($data['optimizeAfterPurge']);
        $f->description = 'Recommended. After deleting old rows, rebuild the analytics tables (OPTIMIZE TABLE) so the freed space is returned to the filesystem. Without this, MySQL/InnoDB keeps the space reserved and the database file stays large even after old data is removed. Runs at most once a week during the daily cleanup.';
        $wrapper->add($f);

        // Live storage status + manual maintenance buttons.
        $module = $wire->modules->get('NativeAnalytics');
        if($module instanceof self) {
            $tokenName = $wire->session->CSRF->getTokenName();
            $tokenValue = $wire->session->CSRF->getTokenValue();
            $baseConfigUrl = $wire->config->urls->admin . 'module/edit?name=NativeAnalytics';
            $purgeUrl = $baseConfigUrl . '&pwna_maintenance_action=purge_now&' . rawurlencode($tokenName) . '=' . rawurlencode($tokenValue);
            $optimizeUrl = $baseConfigUrl . '&pwna_maintenance_action=optimize_now&' . rawurlencode($tokenName) . '=' . rawurlencode($tokenValue);

            $stats = $module->getStorageStats();
            $rowsHtml = '';
            foreach($stats['tables'] as $t) {
                $rowsHtml .= '<tr><td>' . $wire->sanitizer->entities($t['label'])
                    . '</td><td style="text-align:right">' . number_format($t['rows'])
                    . '</td><td style="text-align:right">' . self::formatBytes($t['bytes']) . '</td></tr>';
            }
            $rowsHtml .= '<tr style="font-weight:bold;border-top:1px solid rgba(128,128,128,.4)"><td>Total</td><td></td>'
                . '<td style="text-align:right">' . self::formatBytes($stats['totalBytes']) . '</td></tr>';

            $lastPurge = trim((string) ($data['lastPurgeAt'] ?? '')) ?: 'never';
            $lastOptimize = trim((string) ($data['lastOptimizeAt'] ?? '')) ?: 'never';

            $f = $wire->modules->get('InputfieldMarkup');
            $f->name = 'retentionStatus';
            $f->showIf = 'trackingEnabled=1';
            $f->label = 'Storage status & manual maintenance';
            $f->value =
                '<table class="pwna-storage-table" style="width:auto;min-width:340px;border-collapse:collapse;margin-bottom:.5em">'
                . '<thead><tr><th style="text-align:left">Table</th><th style="text-align:right">Rows</th><th style="text-align:right">Size</th></tr></thead>'
                . '<tbody>' . $rowsHtml . '</tbody></table>'
                . '<p class="description">Automatic cleanup runs daily via LazyCron. Last cleanup: <strong>' . $wire->sanitizer->entities($lastPurge)
                . '</strong> &middot; Last disk reclaim: <strong>' . $wire->sanitizer->entities($lastOptimize) . '</strong>.</p>'
                . '<p class="pwna-maintenance-buttons">'
                . '<a class="ui-button ui-priority-secondary" href="' . $wire->sanitizer->entities($purgeUrl) . '"><i class="fa fa-trash-o"></i> Purge old data now</a> '
                . '<a class="ui-button ui-priority-secondary" href="' . $wire->sanitizer->entities($optimizeUrl) . '"><i class="fa fa-compress"></i> Reclaim disk space now</a>'
                . '</p>'
                . '<p class="description">Save any changed retention values first. "Purge old data now" applies the retention limits above immediately; "Reclaim disk space now" rebuilds the tables so freed space returns to the filesystem.</p>';
            $wrapper->add($f);
        }

        // Tracking health / diagnostics. Designed to explain the common "all zero"
        // situation without requiring Tracy or direct database access.
        if($module instanceof self) {
            $diag = $module->getTrackingDiagnostics();
            $health = $diag['health'];
            $testUrl = $baseConfigUrl . '&pwna_maintenance_action=tracking_test&' . rawurlencode($tokenName) . '=' . rawurlencode($tokenValue);
            $state = !empty($diag['ok']) ? 'OK' : 'Needs attention';
            $details = '<ul style="margin:.4em 0 1em 1.2em">';
            $details .= '<li><strong>Tracking:</strong> ' . (!empty($data['trackingEnabled']) ? 'Enabled' : 'Disabled') . '</li>';
            $details .= '<li><strong>Mode:</strong> ' . $wire->sanitizer->entities((string) $diag['mode']) . '</li>';
            $details .= '<li><strong>Storage:</strong> ' . $wire->sanitizer->entities((string) $diag['storage']) . '</li>';
            $details .= '<li><strong>Tracker asset:</strong> ' . (!empty($diag['tracker_asset']) ? 'Available' : 'Missing') . '</li>';
            $details .= '<li><strong>Raw hits:</strong> ' . number_format((int) ($health['hits_count'] ?? 0)) . '</li>';
            $details .= '<li><strong>Last hit:</strong> ' . $wire->sanitizer->entities(trim((string) ($health['last_hit_at'] ?? '')) ?: 'never') . '</li></ul>';
            foreach($diag['issues'] as $message) $details .= '<p class="uk-alert-danger pwna-diagnostic-message"><strong>Problem:</strong> ' . $wire->sanitizer->entities($message) . '</p>';
            foreach($diag['warnings'] as $message) $details .= '<p class="description">' . $wire->sanitizer->entities($message) . '</p>';
            $f = $wire->modules->get('InputfieldMarkup');
            $f->name = 'trackingHealth';
            $f->showIf = 'trackingEnabled=1';
            $f->label = 'Tracking health & diagnostics';
            // Consume a one-time diagnostic result before rendering this visible
            // Inputfield. Previously the dialog lived in a collapsedHidden
            // InputfieldMarkup; some admin themes correctly omit collapsedHidden
            // markup altogether, so the modal never reached the DOM. Keeping the
            // dialog inside the visible diagnostics field is theme-independent.
            $diagResult = $wire->session->getFor('NativeAnalytics', 'trackingDiagnosticResult');
            $diagResultHtml = '';
            if(is_array($diagResult)) {
                $wire->session->removeFor('NativeAnalytics', 'trackingDiagnosticResult');
                $diagResultHtml = self::renderTrackingDiagnosticResultModal($diagResult);
            }
            $f->value = '<p><strong>Status: ' . $wire->sanitizer->entities($state) . '</strong></p>' . $details
                . '<p><a class="ui-button ui-priority-secondary" href="' . $wire->sanitizer->entities($testUrl) . '"><i class="fa fa-stethoscope"></i> Run tracking test</a></p>'
                . '<p class="description">The test verifies that the analytics schema can be read and that the raw hits table accepts writes. It uses a transaction and rolls the test row back, so statistics are not changed.</p>'
                . $diagResultHtml;
            $wrapper->add($f);
        }

        // Destructive uninstall is deliberately opt-in.
        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'deleteDataOnUninstall';
        $f->label = 'Delete collected analytics data when uninstalling';
        $f->checked = !empty($data['deleteDataOnUninstall']);
        $f->description = 'Dangerous. Leave disabled to preserve analytics tables if the module is accidentally uninstalled or you plan to reinstall it. Enable only when you intentionally want all NativeAnalytics database data removed during uninstall.';
        $wrapper->add($f);

        // Internal runtime marker. Keep it in the configuration form as a hidden
        // value so saving normal module settings does not wipe the persisted schema
        // check state and re-enable a full schema sweep on every request.
        $f = $wire->modules->get('InputfieldHidden');
        $f->name = 'schemaCheckedVersion';
        $f->value = (int) ($data['schemaCheckedVersion'] ?? 0);
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldHidden');
        $f->name = 'schemaBackfillPending';
        $f->value = !empty($data['schemaBackfillPending']) ? 1 : 0;
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldHidden');
        $f->name = 'lastPurgeAt';
        $f->value = $data['lastPurgeAt'] ?? '';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldHidden');
        $f->name = 'lastOptimizeAt';
        $f->value = $data['lastOptimizeAt'] ?? '';
        $wrapper->add($f);

        // The funnel definition is edited on the dashboard Funnel tab, not here.
        // A hidden field keeps it from being wiped when module settings are saved.
        $f = $wire->modules->get('InputfieldHidden');
        $f->name = 'funnelSteps';
        $f->value = $data['funnelSteps'] ?? '';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'highTrafficMode';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'High traffic mode helpers';
        $f->checked = !empty($data['highTrafficMode']);
        $f->description = 'Adds retention and aggregate-first maintenance helpers for larger sites. Keep raw hits/events for a limited period and keep daily aggregates for long-term reporting.';
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

        $f = $wire->modules->get('InputfieldInteger');
        $f->name = 'realtimeRefreshSeconds';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Dashboard auto-refresh (seconds)';
        $f->value = (int) ($data['realtimeRefreshSeconds'] ?? 10);
        $f->min = 0;
        $f->max = 300;
        $f->notes = 'Set to 0 to disable auto-refresh. Minimum 5 seconds when enabled.';
        $f->description = 'How often the open dashboard refreshes live figures (cards, chart, current visitors). Polling automatically pauses while the browser tab is hidden and resumes when you return, to avoid needless server load.';
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

        $f = $wire->modules->get('InputfieldTextarea');
        $f->name = 'suspiciousPathPatterns';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Additional suspicious path patterns (optional)';
        $f->rows = 5;
        $f->value = $data['suspiciousPathPatterns'] ?? '';
        $f->description = 'Optional. The module already auto-detects most bot probes — login/admin scans in 15+ languages, WordPress/Joomla/Drupal/Magento exploit paths, config leaks (.env, .git, wp-config), shell uploads, path traversal, and rate-limited IP scanners (2+ different 404 paths or 5+ total 404s in 5 min from same IP). Use this field only to add your own custom patterns specific to your site. One pattern per line; substring match, case-insensitive.';
        $f->notes = 'Built-in auto-detection runs regardless of this field. Leave empty unless you have unique paths to filter.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldTextarea');
        $f->name = 'ipBlocklist';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Blocked IP addresses';
        $f->rows = 5;
        $f->value = $data['ipBlocklist'] ?? '';
        $f->description = 'One IP address per line (or IP prefix like 192.168.). Requests from these IPs will NOT be tracked at all. Useful for blocking persistent scrapers and bots that bypass other filters. Add IPs manually here, or use the "Block this IP" button on the Current visitors panel.';
        $f->notes = 'Examples: 1.2.3.4 (exact match), 1.2.3. (CIDR-like prefix matching), 2001:db8: (IPv6 prefix).';
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

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'showPageEditAnalytics';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Show page analytics summary in Page Edit';
        $f->checked = !empty($data['showPageEditAnalytics']);
        $f->description = 'Show a compact NativeAnalytics summary at the bottom of each page edit screen. Disable this if you prefer a cleaner Page Edit form.';
        $wrapper->add($f);


        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'monthlyReportsEnabled';
        $f->showIf = 'trackingEnabled=1';
        $f->label = 'Enable monthly email reports';
        $f->checked = !empty($data['monthlyReportsEnabled']);
        $f->description = 'Send a short NativeAnalytics summary by email once per month. The report covers the previous calendar month.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldTextarea');
        $f->name = 'monthlyReportRecipients';
        $f->showIf = 'trackingEnabled=1, monthlyReportsEnabled=1';
        $f->label = 'Monthly report recipients';
        $f->rows = 3;
        $f->value = $data['monthlyReportRecipients'] ?? '';
        $f->description = 'One or more email addresses, separated by commas or new lines.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldInteger');
        $f->name = 'monthlyReportSendDay';
        $f->showIf = 'trackingEnabled=1, monthlyReportsEnabled=1';
        $f->label = 'Send report on day of month';
        $f->value = max(1, min(28, (int) ($data['monthlyReportSendDay'] ?? 1)));
        $f->min = 1;
        $f->max = 28;
        $f->description = 'Recommended: 1. The report is sent once per month for the previous calendar month. Values are limited to 1–28 so the setting works in every month.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldText');
        $f->name = 'monthlyReportFromEmail';
        $f->showIf = 'trackingEnabled=1, monthlyReportsEnabled=1';
        $f->label = 'Report sender email (optional)';
        $f->value = $data['monthlyReportFromEmail'] ?? '';
        $f->description = 'Leave empty to use the site/default WireMail sender. Some SMTP providers require a verified sender address.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'monthlyReportAttachPdf';
        $f->showIf = 'trackingEnabled=1, monthlyReportsEnabled=1';
        $f->label = 'Attach PDF report to monthly email';
        $f->checked = !empty($data['monthlyReportAttachPdf']);
        $f->description = 'Adds a clean PDF version of the same report as an email attachment. Enabled by default.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'monthlyReportIncludeTopPages';
        $f->showIf = 'trackingEnabled=1, monthlyReportsEnabled=1';
        $f->label = 'Include top pages in monthly report';
        $f->checked = !empty($data['monthlyReportIncludeTopPages']);
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'monthlyReportIncludeReferrers';
        $f->showIf = 'trackingEnabled=1, monthlyReportsEnabled=1';
        $f->label = 'Include top referrers in monthly report';
        $f->checked = !empty($data['monthlyReportIncludeReferrers']);
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'monthlyReportIncludeChannels';
        $f->showIf = 'trackingEnabled=1, monthlyReportsEnabled=1';
        $f->label = 'Include acquisition channels in monthly report';
        $f->checked = !empty($data['monthlyReportIncludeChannels']);
        $f->description = 'Adds a first-touch channel breakdown (Direct, Organic Search, Social, Referral, Paid, Email, Campaign) by sessions.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'monthlyReportIncludeEvents';
        $f->showIf = 'trackingEnabled=1, monthlyReportsEnabled=1';
        $f->label = 'Include engagement events in monthly report';
        $f->checked = !empty($data['monthlyReportIncludeEvents']);
        $f->description = 'Includes total engagement events and the top tracked events for the month.';
        $wrapper->add($f);

        $tokenName = $wire->session->CSRF->getTokenName();
        $tokenValue = $wire->session->CSRF->getTokenValue();
        $baseConfigUrl = $wire->config->urls->admin . 'module/edit?name=NativeAnalytics';
        $testUrl = $baseConfigUrl . '&pwna_monthly_report_action=send_test&' . rawurlencode($tokenName) . '=' . rawurlencode($tokenValue);
        $previewUrl = $baseConfigUrl . '&pwna_monthly_report_preview=1';
        $f = $wire->modules->get('InputfieldMarkup');
        $f->name = 'monthlyReportTestTool';
        $f->showIf = 'trackingEnabled=1, monthlyReportsEnabled=1';
        $f->label = 'Monthly report tools';
        $f->value = '<p class="pwna-report-tool-buttons">'
            . '<a class="ui-button ui-priority-secondary" href="' . $wire->sanitizer->entities($previewUrl) . '"><i class="fa fa-eye"></i> Report preview</a> '
            . '<a class="ui-button ui-priority-secondary" href="' . $wire->sanitizer->entities($testUrl) . '"><i class="fa fa-envelope-o"></i> Send test report now</a>'
            . '</p>'
            . '<p class="description">Preview the monthly report in the admin or send it immediately to the configured recipients. The scheduled report uses the previous calendar month; test/preview automatically falls back to current month-to-date when the previous month has no data yet. Save settings first if you changed recipients or report options. Test sends do not update the last sent month marker.</p>';
        $wrapper->add($f);

        if((int) $wire->input->get('pwna_monthly_report_preview') === 1) {
            $f = $wire->modules->get('InputfieldMarkup');
            $f->name = 'monthlyReportPreview';
            $f->showIf = 'trackingEnabled=1, monthlyReportsEnabled=1';
            $f->label = 'Monthly report preview';
            $module = $wire->modules->get('NativeAnalytics');
            $previewHtml = ($module instanceof self) ? $module->renderMonthlyReportAdminPreview(false) : '<p class="description">Preview is not available until the module is loaded.</p>';
            $f->value = '<p class="description">Preview only. No email is sent from this view.</p>' . $previewHtml;
            $wrapper->add($f);
        }

        $f = $wire->modules->get('InputfieldHidden');
        $f->name = 'monthlyReportLastSentPeriod';
        $f->value = $data['monthlyReportLastSentPeriod'] ?? '';
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

        // ------------------------------------------------------------------
        // Behavioral bot filter (catches headless / rotating-proxy fleets that
        // present real-looking user agents). Runs hourly via LazyCron.
        // ------------------------------------------------------------------
        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'botFilterEnabled';
        $f->label = 'Behavioral bot filter';
        $f->label2 = 'Flag headless / proxy-fleet traffic and hide it from reporting';
        $f->icon = 'shield';
        $f->description = 'Runs hourly. Marks sessions that match a bot pattern (many single-hit, no-referrer sessions sharing one user agent across many IPs, or excessive single-hit sessions from one IP). Flagged rows stay in the database for review but are excluded from the dashboard.';
        if(!empty($data['botFilterEnabled'])) $f->attr('checked', 'checked');
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldInteger');
        $f->name = 'botFleetMinSessions';
        $f->showIf = 'botFilterEnabled=1';
        $f->label = 'UA fleet: min sessions';
        $f->columnWidth = 25;
        $f->value = isset($data['botFleetMinSessions']) ? (int) $data['botFleetMinSessions'] : 20;
        $f->description = 'Minimum single-hit, no-referrer sessions sharing one user agent before it is treated as a fleet.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldInteger');
        $f->name = 'botFleetMinIps';
        $f->showIf = 'botFilterEnabled=1';
        $f->label = 'UA fleet: min distinct IPs';
        $f->columnWidth = 25;
        $f->value = isset($data['botFleetMinIps']) ? (int) $data['botFleetMinIps'] : 5;
        $f->description = 'Minimum distinct IPs the shared user agent must span.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldInteger');
        $f->name = 'botFleetWindowMinutes';
        $f->showIf = 'botFilterEnabled=1';
        $f->label = 'UA fleet: window (minutes)';
        $f->columnWidth = 25;
        $f->value = isset($data['botFleetWindowMinutes']) ? (int) $data['botFleetWindowMinutes'] : 240;
        $f->description = 'Time window the fleet activity must cluster within.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldInteger');
        $f->name = 'botIpMaxHitsPerHour';
        $f->showIf = 'botFilterEnabled=1';
        $f->label = 'Single IP: max sessions/hour';
        $f->columnWidth = 25;
        $f->value = isset($data['botIpMaxHitsPerHour']) ? (int) $data['botIpMaxHitsPerHour'] : 30;
        $f->description = 'Single-hit, no-referrer sessions from one IP in one hour before that IP is flagged.';
        $wrapper->add($f);

        $f = $wire->modules->get('InputfieldCheckbox');
        $f->name = 'bot404ShowBots';
        $f->showIf = 'botFilterEnabled=1';
        $f->label = '404 panel: show bot traffic';
        $f->label2 = 'Include bot-flagged sessions in the 404 pages panel';
        $f->icon = 'bug';
        $f->description = 'Off by default: bot-flagged sessions are hidden from the 404 panel (consistent with all other panels). Enable to include scanner probes in the 404 panel for security visibility — useful for spotting what attackers are targeting.';
        $current = array_key_exists('bot404ShowBots', $data) ? $data['bot404ShowBots'] : 0;
        if(!empty($current)) $f->attr('checked', 'checked');
        $wrapper->add($f);

        // ------------------------------------------------------------------
        // Reorganize the flat field list into grouped, collapsible fieldsets
        // with two-column layouts where it helps reduce vertical scrolling.
        // ------------------------------------------------------------------
        $organized = self::organizeConfigFields($wrapper, $wire);
        if($organized instanceof InputfieldWrapper) $wrapper = $organized;

        return $wrapper;
    }

    /**
     * Take the flat $wrapper produced by getModuleConfigInputfields() and
     * return a new wrapper with the same fields grouped into fieldsets.
     * Unknown fields are appended at the end so future additions don't get
     * silently dropped.
     */
    protected static function organizeConfigFields(InputfieldWrapper $flat, $wire) {
        // Index existing fields by name for quick retrieval.
        $byName = [];
        foreach($flat->children() as $child) {
            $name = (string) $child->name;
            if($name === '') continue;
            $byName[$name] = $child;
        }

        // Groups: [fieldset label, icon, collapsed?, [[field, columnWidth%], ...]]
        $groups = [
            [
                'General tracking',
                'toggle-on',
                false,
                [
                    ['trackingEnabled', 100],
                    ['eventTrackingEnabled', 50],
                    ['showPageEditAnalytics', 50],
                    ['botDetectionStatus', 100],
                    ['trackingMode', 50],
                    ['trackingStorageMode', 50],
                ],
            ],
            [
                'Privacy & consent',
                'shield',
                true,
                [
                    ['respectDnt', 50],
                    ['requireConsent', 50],
                    ['consentCookieName', 50],
                    ['privacyWireConsentCookieMaxAge', 50],
                    ['privacyWireAutoConsent', 100],
                    ['privacyWireStorageKey', 50],
                    ['privacyWireGroups', 50],
                ],
            ],
            [
                'Data retention & performance',
                'database',
                true,
                [
                    ['rawRetentionDays', 50],
                    ['rawEventRetentionDays', 50],
                    ['optimizeAfterPurge', 100],
                    ['retentionStatus', 100],
                    ['highTrafficMode', 50],
                    ['realtimeWindowMinutes', 50],
                    ['realtimeRefreshSeconds', 50],
                    ['ignoreQueryString', 100],
                ],
            ],
            [
                'Filters & exclusions',
                'filter',
                true,
                [
                    ['excludeRoles', 100],
                    ['excludePaths', 50],
                    ['ipBlocklist', 50],
                    ['suspiciousPathPatterns', 50],
                    ['searchQueryVars', 50],
                ],
            ],
            [
                'Dashboard display',
                'tachometer',
                true,
                [
                    ['dashboardDefaultRange', 50],
                    ['displayDateFormat', 50],
                ],
            ],
            [
                'Monthly email reports',
                'envelope',
                true,
                [
                    ['monthlyReportsEnabled', 100],
                    ['monthlyReportRecipients', 50],
                    ['monthlyReportFromEmail', 50],
                    ['monthlyReportSendDay', 50],
                    ['monthlyReportAttachPdf', 50],
                    ['monthlyReportIncludeTopPages', 33],
                    ['monthlyReportIncludeReferrers', 33],
                    ['monthlyReportIncludeEvents', 34],
                    ['monthlyReportIncludeChannels', 100],
                    ['monthlyReportTestTool', 100],
                    ['monthlyReportPreview', 100],
                    ['monthlyReportLastSentPeriod', 100],
                ],
            ],
            [
                'Advanced',
                'wrench',
                true,
                [
                    ['hashSalt', 100],
                ],
            ],
        ];

        $newWrapper = new InputfieldWrapper();
        $assigned = [];

        foreach($groups as $g) {
            list($label, $icon, $collapsed, $fields) = $g;
            $fs = $wire->modules->get('InputfieldFieldset');
            $fs->label = $label;
            if($icon) $fs->icon = $icon;
            $fs->collapsed = $collapsed ? Inputfield::collapsedYes : Inputfield::collapsedNo;

            $hasAny = false;
            foreach($fields as $entry) {
                list($fieldName, $width) = $entry;
                if(!isset($byName[$fieldName])) continue;
                $field = $byName[$fieldName];
                if($width && $width > 0 && $width <= 100) {
                    $field->columnWidth = (int) $width;
                }
                $fs->add($field);
                $assigned[$fieldName] = true;
                $hasAny = true;
            }
            if($hasAny) $newWrapper->add($fs);
        }

        // Append anything we missed so future fields aren't lost silently.
        foreach($byName as $name => $field) {
            if(isset($assigned[$name])) continue;
            $newWrapper->add($field);
        }

        return $newWrapper;
    }

    public function shouldTrackCurrentRequest() {
        $config = $this->wire('config');
        $input = $this->wire('input');
        $page = $this->wire('page');

        if($config->admin || $config->ajax) return false;
        if($input->requestMethod() !== 'GET') return false;
        if((int) $input->get('pwna_event') === 1) return false;
        if($this->isRoleExcluded()) return false;
        if($this->isIpBlocked()) return false;
        if(!$page || !$page->id) return false;
        if($page->template && $page->template->name === 'admin') return false;
        if($this->isIgnorableRequestForPageview()) return false;
        if($this->isSuspiciousProbePath()) return false;
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
        if($this->isIpBlocked()) return false;
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

    /**
     * Check if the requesting IP is in the user-configured blocklist.
     * Supports:
     *   - exact IP match: "1.2.3.4"
     *   - prefix match: "192.168." or "2001:db8:"
     *   - hashed entries: "hash:abcd1234..." (used by the Block button in admin
     *     since raw IPs aren't stored long-term)
     */
    public function isIpBlocked($ip = null) {
        $blocklist = trim((string) $this->ipBlocklist);
        if($blocklist === '') return false;

        if($ip === null) $ip = $this->getClientIp();
        if($ip === '') return false;
        $ipHash = $this->hashValue($ip);

        foreach(preg_split('/\R+/', $blocklist) as $entry) {
            $entry = trim($entry);
            if($entry === '') continue;
            // Hashed entry (added via "Block this IP" admin button)
            if(strpos($entry, 'hash:') === 0) {
                if(substr($entry, 5) === $ipHash) return true;
                continue;
            }
            // Exact match
            if($ip === $entry) return true;
            // Prefix match (entry ends with . or :)
            if(strpos($ip, $entry) === 0 && (substr($entry, -1) === '.' || substr($entry, -1) === ':')) return true;
        }
        return false;
    }

    /**
     * Add an entry (raw IP or hash:...) to the blocklist config.
     */
    public function addIpToBlocklist($entry) {
        $entry = trim((string) $entry);
        if($entry === '') return false;
        $current = trim((string) $this->ipBlocklist);
        $lines = $current === '' ? [] : preg_split('/\R+/', $current);
        $lines = array_map('trim', $lines);
        if(in_array($entry, $lines, true)) return true;
        $lines[] = $entry;
        $newValue = implode("\n", array_filter($lines));
        $this->wire('modules')->saveConfig($this, 'ipBlocklist', $newValue);
        $this->ipBlocklist = $newValue;
        return true;
    }

    /**
     * Built-in auto-detection of probing patterns (no user config needed).
     * Returns true if the path looks like a bot probe based on universal patterns
     * used by vulnerability scanners and brute force tools across all CMSes.
     */
    protected function matchesBuiltInProbePattern($path) {
        if($path === '' || $path === '/') return false;
        $p = mb_strtolower($path);

        // Common login/admin probes (multilingual)
        // Matches /login, /prijava, /anmeldung, /connexion, /accedi, /iniciar-sesion, /entrar etc.
        // Word boundary via slash or end ensures we don't match e.g. /catalog/loginitems/
        $loginWords = '(?:login|signin|sign-in|log-in|prijava|anmeldung|anmelden|connexion|connecter|accedi|entrar|iniciar-sesion|iniciar_sesion|acceso|inloggen|aanmelden|logga-in|kirjaudu|wp-login|admin|administrator|administracja|administrace|panel|cpanel|webmail|user|users|account|signup|register|registracija|registrace|kayit|kayit-ol)';
        if(preg_match('#(?:^|/)' . $loginWords . '(?:\.php|/|$)#i', $p)) return true;

        // Common file probes (config leaks, exploit attempts)
        $fileProbes = '(?:wp-config|configuration|config|settings)\.(?:php|inc|bak|old|txt|yml|yaml|json)';
        if(preg_match('#/' . $fileProbes . '(?:[\.~]|$)#i', $p)) return true;

        // Sensitive file/dir probes
        if(preg_match('#/\.(?:env|git|svn|hg|aws|ssh|htpasswd|htaccess|DS_Store|vscode|idea|composer|npm|yarn|docker|kube)#i', $p)) return true;

        // CMS-specific paths (WordPress, Joomla, Drupal, Magento, Laravel, Bitrix)
        $cmsPaths = '(?:wp-admin|wp-includes|wp-content|xmlrpc\.php|wp-json|/wp/|administrator/|admin/index\.php|user/login|sites/default|magmi|index\.php\?option=com_|j_security_check|cgi-bin|telescope/requests|debug/default/view|_ignition|_profiler|bitrix/admin|bitrix/tools|/typo3|backend\.php|umbraco|sitecore|kentico)';
        if(preg_match('#/' . $cmsPaths . '#i', $p)) return true;

        // Database/admin tools
        if(preg_match('#/(?:phpmyadmin|pma|myadmin|mysql|sqladmin|adminer|phpinfo|info\.php|server-status|server-info)(?:[/.\?]|$)#i', $p)) return true;

        // Backup / archive probes
        if(preg_match('#/(?:backup|backups|dump|sql|bak|old|temp|tmp|cache)\.(?:sql|gz|tar|zip|rar|7z)$#i', $p)) return true;

        // Shell / RCE attempts (common backdoor filenames)
        if(preg_match('#/(?:shell|cmd|c99|r57|b374k|wso|webshell|backdoor|eval|exec|hax|hack|filemanager)(?:\.|/)#i', $p)) return true;

        // Suspicious ext on non-tech sites (.asp/.aspx/.jsp/.cfm/.cgi - if user runs PHP, these are 99% probes)
        if(preg_match('#\.(?:asp|aspx|jsp|jspx|cfm|cgi|pl|env|sql|bak|swp|orig|save|rej)(?:[\?\#]|$)#i', $p)) return true;

        // Path traversal attempts
        if(strpos($p, '../') !== false || strpos($p, '..%2f') !== false || strpos($p, '%2e%2e/') !== false) return true;

        // Vendor / framework leaks
        if(preg_match('#/(?:vendor|node_modules|composer\.(?:json|lock)|package(?:-lock)?\.json|yarn\.lock|gemfile|requirements\.txt)#i', $p)) return true;

        // Common probe filenames at root
        if(preg_match('#^/(?:phpunit|owa|webdav|fckeditor|tinymce|ckeditor|elmah|trace\.axd|crossdomain\.xml|clientaccesspolicy\.xml)#i', $p)) return true;

        return false;
    }

    /**
     * Rate-limit based bot detection. Triggers when the same IP shows
     * scanner-like behavior in the last 5 minutes:
     *  - 2+ distinct 404 paths, OR
     *  - 5+ total 404 hits (even to the same path repeatedly)
     * Both conditions catch different bot patterns (vulnerability scanners vs. hammering bots).
     * Uses an in-memory static cache per request to avoid repeated DB hits.
     */
    protected function ipIsRecent404Scanner($ipHash = null) {
        if($ipHash === null) {
            $ipHash = $this->hashValue($this->getClientIp());
        }
        if(empty($ipHash)) return false;

        static $cache = [];
        if(isset($cache[$ipHash])) return $cache[$ipHash];

        try {
            $db = $this->wire('database');
            $sql = "SELECT COUNT(DISTINCT path) AS distinct_paths, COUNT(*) AS total_hits
                    FROM `" . self::HITS_TABLE . "`
                    WHERE ip_hash = :iphash
                      AND status_code = 404
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
            $stmt = $db->prepare($sql);
            $stmt->execute([':iphash' => $ipHash]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['distinct_paths' => 0, 'total_hits' => 0];
            $distinct = (int) $row['distinct_paths'];
            $total = (int) $row['total_hits'];
            // Either 2+ distinct paths OR 5+ total hits in 5 min = scanner
            $cache[$ipHash] = ($distinct >= 2 || $total >= 5);
        } catch(\Throwable $e) {
            $cache[$ipHash] = false;
        }
        return $cache[$ipHash];
    }

    /**
     * Detect scanner-like behaviour by visitor_hash on the hits table.
     * Used by realtime/current-visitors rendering where the sessions table
     * does not store ip_hash (only visitor_hash). Same thresholds as
     * ipIsRecent404Scanner(): 2+ distinct paths OR 5+ hits in last 5 minutes.
     */
    protected function visitorIsRecent404Scanner($visitorHash) {
        if(empty($visitorHash)) return false;

        static $cache = [];
        if(isset($cache[$visitorHash])) return $cache[$visitorHash];

        try {
            $db = $this->wire('database');
            $sql = "SELECT COUNT(DISTINCT path) AS distinct_paths, COUNT(*) AS total_hits
                    FROM `" . self::HITS_TABLE . "`
                    WHERE visitor_hash = :vhash
                      AND status_code = 404
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
            $stmt = $db->prepare($sql);
            $stmt->execute([':vhash' => $visitorHash]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['distinct_paths' => 0, 'total_hits' => 0];
            $distinct = (int) $row['distinct_paths'];
            $total = (int) $row['total_hits'];
            $cache[$visitorHash] = ($distinct >= 2 || $total >= 5);
        } catch(\Throwable $e) {
            $cache[$visitorHash] = false;
        }
        return $cache[$visitorHash];
    }

    /**
     * Detect 404 requests from unidentifiable user-agents.
     * Real browsers always have recognizable UA strings; "Other browser + Other OS"
     * almost always indicates a scripted bot/scraper with custom or minimal UA.
     * For 404 requests specifically, this combination is a strong bot signal.
     */
    protected function isLikelyBotFromUserAgent($ua) {
        if($ua === '' || $ua === null) return true; // no UA at all is suspicious
        $device = $this->parseUserAgent($ua);
        $browserUnknown = !$device['browser'] || strtolower((string) $device['browser']) === 'other';
        $osUnknown = !$device['os'] || strtolower((string) $device['os']) === 'other';
        return $browserUnknown && $osUnknown;
    }

    /**
     * Check whether the requested path matches a known suspicious/probe pattern.
     * Combines THREE detection layers (all auto, plus user-customizable):
     *  1. Built-in regex patterns (multilingual login probes, CMS exploits, config leaks etc.)
     *  2. User-configured patterns from `suspiciousPathPatterns` (substring match)
     *  3. IP-based 404 scanner rate limit (2+ distinct 404 paths or 5+ total 404s in 5 min)
     * Returns true if any layer triggers.
     */
    protected function isSuspiciousProbePath($path = null) {
        if($path === null) {
            $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            $path = $this->normalizePath((string) parse_url($requestUri, PHP_URL_PATH));
        }
        if($path === '' || $path === '/') return false;

        // Layer 1: built-in auto patterns (handles multilingual logins, CMS probes, exploits)
        if($this->matchesBuiltInProbePattern($path)) return true;

        // Layer 2: user-configured custom patterns (substring match, case-insensitive)
        $patterns = trim((string) $this->suspiciousPathPatterns);
        if($patterns !== '') {
            $pathLower = mb_strtolower($path);
            foreach(preg_split('/\R+/', $patterns) as $pattern) {
                $pattern = trim($pattern);
                if($pattern === '') continue;
                if(mb_strpos($pathLower, mb_strtolower($pattern)) !== false) return true;
            }
        }

        return false;
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

        $mode = $this->getTrackingMode();
        $jsIsPrimary = ($mode === 'js_first' || $mode === 'js_only');

        // A short HTML meta refresh can navigate away before a deferred browser
        // tracker gets a chance to run. In JS-first mode, record that rendered
        // redirect page server-side and suppress the duplicate client pageview.
        // This keeps normal pages JS-first while making deliberate redirect pages
        // reliable even with very short refresh delays.
        $metaRefreshFallback = false;
        if($jsIsPrimary && $this->containsMetaRefresh($html) && $this->shouldTrackCurrentRequest()) {
            $this->trackCurrentRequestServerSide();
            $metaRefreshFallback = true;
        }

        $payload = [
            'trackEndpoint' => $this->getTrackEndpointUrl(),
            'path' => $this->getRequestPathForStorage(),
            'pageId' => (int) $page->id,
            'pageTitle' => (string) $page->get('title'),
            'template' => $page->template ? (string) $page->template->name : '',
            'statusCode' => $this->detectStatusCode($page),
            'consentRequired' => (bool) $this->requireConsent,
            'consentCookieName' => (string) $this->consentCookieName,
            'respectDnt' => (bool) $this->respectDnt,
            // When JS is the primary source of pageviews, fire one on load.
            'autoTrack' => $jsIsPrimary && !$metaRefreshFallback,
            // When server-side is disabled and consent is needed, JS must still fire
            // the pageview after consent is given.
            'needsClientPageview' => (!$metaRefreshFallback && $jsIsPrimary) || (bool) ($this->requireConsent && !$this->hasConsentCookie()),
            'eventTracking' => (bool) $this->eventTrackingEnabled,
            'storageMode' => $this->getTrackingStorageMode(),
            'privacyWireAutoConsent' => (bool) $this->privacyWireAutoConsent,
            'privacyWireStorageKey' => (string) $this->privacyWireStorageKey,
            'privacyWireGroups' => $this->getPrivacyWireGroups(),
            'privacyWireVersion' => $this->getPrivacyWireConsentVersion(),
            'consentCookieMaxAge' => max(3600, (int) $this->privacyWireConsentCookieMaxAge),
        ];

        $scriptUrl = $this->getAssetUrl('assets/tracker.js') . '?v=' . rawurlencode($this->getAssetVersion('assets/tracker.js'));
        $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $configJson = json_encode($payload, $jsonFlags);
        if($configJson === false) return;
        $nonceAttr = $this->getScriptNonceAttribute();
        $injected = "\n<script" . $nonceAttr . ">window.PWNA_CONFIG = " . $configJson . ";</script>\n";
        $injected .= '<script' . $nonceAttr . ' src="' . $this->wire('sanitizer')->entities($scriptUrl) . '" data-pwna-tracker="1" defer></script>' . "\n";

        if(stripos($html, '</body>') !== false) {
            $event->return = preg_replace('~</body>~i', $injected . '</body>', $html, 1);
        } else {
            $event->return = $html . $injected;
        }
    }

    /**
     * Detect an HTML meta refresh in rendered markup. Attribute order and quote
     * style are intentionally flexible; only a real <meta> tag with an
     * http-equiv=refresh attribute qualifies.
     */
    protected function containsMetaRefresh($html) {
        $html = (string) $html;
        if($html === '' || stripos($html, '<meta') === false || stripos($html, 'refresh') === false) return false;
        if(!preg_match_all('/<meta\b[^>]*>/i', $html, $matches)) return false;
        foreach($matches[0] as $tag) {
            if(preg_match("~\bhttp-equiv\s*=\s*(['\"]?)refresh\1(?:\s|/?>|$)~i", $tag)) return true;
        }
        return false;
    }

    protected function maybeHandleSpecialEndpoints() {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if($requestUri === '') return;
        $endpoint = $this->getSpecialEndpointFromUri($requestUri);
        if($endpoint === 'track') {
            $this->handleTrackingRequest();
        }
        if($endpoint === 'realtime') {
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

        $renderCharts = trim((string) $input->get('render_charts'));
        if($renderCharts !== '') {
            $allowed = ['daily', 'hourly', 'events', 'goals'];
            $requested = array_filter(array_map('trim', explode(',', $renderCharts)));
            $chartIds = array_values(array_intersect($allowed, $requested));
            $this->sendTrackingResponse(200, [
                'ok' => true,
                'chartHtml' => $this->getLiveChartHtml($rangeSpec, $filters, $chartIds),
                'ts' => date('c'),
            ]);
        }

        $currentVisitors = $this->getCurrentVisitors($minutes, 25, $filters);
        if($this->wire('user')->hasPermission('nativeanalytics-manage')) {
            $visitorHashes = [];
            foreach($currentVisitors as $row) {
                if(!empty($row['visitor_hash'])) $visitorHashes[(string) $row['visitor_hash']] = true;
            }
            $visitorIpMap = $this->resolveVisitorIpHashes(array_keys($visitorHashes));
            foreach($currentVisitors as &$row) {
                $vh = (string) ($row['visitor_hash'] ?? '');
                $row['ip_hash'] = ($vh !== '' && isset($visitorIpMap[$vh])) ? $visitorIpMap[$vh] : '';
            }
            unset($row);
        }

        $payload = [
            'ok' => true,
            'summary' => $this->getSummary($rangeSpec, $filters),
            'current' => $this->getCurrentVisitorsSummary($minutes, $filters),
            'summary404' => $this->get404Summary($rangeSpec),
            'sessionQuality' => $this->getSessionQuality($rangeSpec, $filters),
            'health' => $this->getHealthSnapshot(),
            'chartLatest' => $this->getChartLatestSlots($rangeSpec, $filters),
            'currentVisitors' => $currentVisitors,
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
        if($this->isSuspiciousProbePath()) return;
        if($this->isIpBlocked()) return;

        $ids = $this->getOrCreateTrackingIds();
        $statusCode = $this->detectStatusCode($page);
        $path = $statusCode === 404 ? $this->getRequestedPathWithoutQuery() : $this->getCanonicalPagePath($page);

        // Skip 404 if path is actually resolvable via redirect modules
        // (PagePathHistory, ProcessRedirects, Jumplinks). This avoids logging
        // false 404s for paths that get redirected to a valid page.
        if($statusCode === 404 && $this->pathResolvesToValidPage($path)) return;

        // Aggressive 404 bot detection (combined signals):
        // 1. IP scanner rate limit (2+ distinct or 5+ total 404s in 5 min)
        // 2. Unrecognizable user-agent (Other browser + Other OS) on a 404
        if($statusCode === 404) {
            if($this->ipIsRecent404Scanner()) return;
            if($this->isLikelyBotFromUserAgent($ua)) return;
        }

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
            'utm_term' => $this->trimValue((string) ($queryVars['utm_term'] ?? ''), 191),
            'utm_content' => $this->trimValue((string) ($queryVars['utm_content'] ?? ''), 191),
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
            $this->recordSessionAttribution($row);
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

    /**
     * Check if a "404" path actually resolves to a real page via redirect modules.
     * Checks PagePathHistory, ProcessRedirects and Jumplinks (whichever are installed).
     * Returns true if the path is resolvable (so the 404 should NOT be tracked / shown).
     */
    public function pathResolvesToValidPage($path) {
        $path = $this->normalizePath((string) $path);
        if($path === '' || $path === '/') return false;

        $modules = $this->wire('modules');
        $pages = $this->wire('pages');

        // 1. PagePathHistory (core PW module) — automatic redirects when page name/parent changes
        if($modules->isInstalled('PagePathHistory')) {
            try {
                $pph = $modules->get('PagePathHistory');
                if(method_exists($pph, 'getPage')) {
                    $page = $pph->getPage($path);
                    if($page && $page->id) return true;
                }
            } catch(\Exception $e) {
                // silently ignore
            }
        }

        // 2. ProcessRedirects (3rd party but very common)
        if($modules->isInstalled('ProcessRedirects')) {
            try {
                $db = $this->wire('database');
                $stmt = $db->prepare("SELECT redirect_to FROM process_redirects WHERE redirect_from = :path LIMIT 1");
                $stmt->execute([':path' => $path]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if($row && !empty($row['redirect_to'])) return true;
            } catch(\Exception $e) {
                // ignore (table may not exist)
            }
        }

        // 3. Jumplinks (3rd party)
        if($modules->isInstalled('ProcessJumplinks')) {
            try {
                $db = $this->wire('database');
                $stmt = $db->prepare("SELECT destination FROM jumplinks WHERE source = :path LIMIT 1");
                $stmt->execute([':path' => $path]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if($row && !empty($row['destination'])) return true;
            } catch(\Exception $e) {
                // ignore
            }
        }

        return false;
    }

    /**
     * Cleanup helper: remove suspicious probe paths from the DB.
     * Useful for cleaning out brute force / vulnerability scan attempts
     * that were tracked before suspicious-path filtering was configured.
     * Returns the number of records removed from hits/realtime sessions.
     */
    public function cleanupSuspiciousPaths() {
        $db = $this->wire('database');
        $removedEntries = 0;
        $affectedDays = [];

        // 1. Get all distinct paths from hits and check each via isSuspiciousProbePath
        $stmt = $db->prepare("SELECT DISTINCT path FROM `" . self::HITS_TABLE . "`");
        $stmt->execute();
        $paths = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        foreach($paths as $path) {
            if($this->isSuspiciousProbePath($path)) {
                $this->collectAffectedHitDays($affectedDays, "`path` = :path", [':path' => $path]);

                $del = $db->prepare("DELETE FROM `" . self::HITS_TABLE . "` WHERE path = :path");
                $del->execute([':path' => $path]);
                $removedEntries += (int) $del->rowCount();

                $del2 = $db->prepare("DELETE FROM `" . self::SESSIONS_TABLE . "` WHERE current_path = :path");
                $del2->execute([':path' => $path]);
                $removedEntries += (int) $del2->rowCount();
            }
        }

        // 2. Clean up 404 sessions where browser AND os are 'other' (likely bots)
        try {
            $del = $db->prepare("DELETE FROM `" . self::SESSIONS_TABLE . "`
                                 WHERE status_code = 404
                                   AND (browser = '' OR LOWER(browser) = 'other')
                                   AND (os = '' OR LOWER(os) = 'other')");
            $del->execute();
            $removedEntries += (int) $del->rowCount();
        } catch(\Throwable $e) {
            // ignore
        }

        // 3. Clean up 404 hits from IPs that look like scanners (2+ distinct or 5+ total 404s from each IP)
        try {
            $stmt = $db->prepare("SELECT ip_hash, COUNT(DISTINCT path) AS dp, COUNT(*) AS th
                                  FROM `" . self::HITS_TABLE . "`
                                  WHERE status_code = 404
                                  GROUP BY ip_hash
                                  HAVING dp >= 2 OR th >= 5");
            $stmt->execute();
            $scannerIps = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            if($scannerIps) {
                $placeholders = implode(',', array_fill(0, count($scannerIps), '?'));

                $dayStmt = $db->prepare("SELECT DISTINCT DATE(created_at) FROM `" . self::HITS_TABLE . "`
                                          WHERE status_code = 404 AND ip_hash IN ({$placeholders})");
                $dayStmt->execute($scannerIps);
                foreach($dayStmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $day) $affectedDays[(string) $day] = true;

                // Collect visitor_hashes tied to those scanner ip_hashes BEFORE deleting hits.
                // The sessions table has no ip_hash column, but it does have visitor_hash,
                // so we bridge through hits to find which sessions to clean up.
                $vstmt = $db->prepare("SELECT DISTINCT visitor_hash FROM `" . self::HITS_TABLE . "`
                                       WHERE status_code = 404 AND ip_hash IN ({$placeholders})");
                $vstmt->execute($scannerIps);
                $scannerVisitors = $vstmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

                $del = $db->prepare("DELETE FROM `" . self::HITS_TABLE . "` WHERE status_code = 404 AND ip_hash IN ({$placeholders})");
                $del->execute($scannerIps);
                $removedEntries += (int) $del->rowCount();

                if($scannerVisitors) {
                    $vph = implode(',', array_fill(0, count($scannerVisitors), '?'));
                    $del2 = $db->prepare("DELETE FROM `" . self::SESSIONS_TABLE . "` WHERE status_code = 404 AND visitor_hash IN ({$vph})");
                    $del2->execute($scannerVisitors);
                    $removedEntries += (int) $del2->rowCount();
                }
            }
        } catch(\Throwable $e) {
            // ignore
        }

        if($removedEntries > 0) $this->rebuildAffectedAggregateDays($affectedDays);
        return $removedEntries;
    }

    /**
     * Cleanup helper: remove 404 hits from the DB whose path now resolves
     * to a valid page (e.g. via PagePathHistory after a page was renamed).
     * Returns the number of records removed from hits/realtime sessions.
     */
    public function cleanupResolvable404s() {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT DISTINCT path FROM `" . self::HITS_TABLE . "` WHERE status_code = 404");
        $stmt->execute();
        $paths = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        $removed = 0;
        $affectedDays = [];
        foreach($paths as $path) {
            if($this->pathResolvesToValidPage($path)) {
                $this->collectAffectedHitDays($affectedDays, "`status_code` = 404 AND `path` = :path", [':path' => $path]);

                $del = $db->prepare("DELETE FROM `" . self::HITS_TABLE . "` WHERE status_code = 404 AND path = :path");
                $del->execute([':path' => $path]);
                $removed += (int) $del->rowCount();

                $del2 = $db->prepare("DELETE FROM `" . self::SESSIONS_TABLE . "` WHERE status_code = 404 AND current_path = :path");
                $del2->execute([':path' => $path]);
                $removed += (int) $del2->rowCount();
            }
        }
        if($removed > 0) $this->rebuildAffectedAggregateDays($affectedDays);
        return $removed;
    }

    protected function collectAffectedHitDays(array &$affectedDays, $whereSql, array $params = []) {
        try {
            $stmt = $this->wire('database')->prepare("SELECT DISTINCT DATE(created_at) FROM `" . self::HITS_TABLE . "` WHERE {$whereSql}");
            $stmt->execute($params);
            foreach($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $day) {
                $day = (string) $day;
                if($day !== '') $affectedDays[$day] = true;
            }
        } catch(\Throwable $e) {
            // non-fatal; cleanup should still run
        }
    }

    protected function rebuildAffectedAggregateDays(array $affectedDays) {
        foreach(array_keys($affectedDays) as $day) {
            $day = (string) $day;
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day === '0000-00-00') continue;
            try {
                $this->rebuildDailyAggregate($day);
                $this->rebuildGoalDailyAggregate($day);
            } catch(\Throwable $e) {
                $this->wire('log')->save('native-analytics', 'Aggregate rebuild after cleanup failed for ' . $day . ': ' . $e->getMessage());
            }
        }
    }

    protected function getCanonicalPagePath(?Page $page = null) {
        if(!$page || !$page->id) return '/';
        return $this->normalizePath((string) $page->path());
    }

    protected function getRequestedPathWithoutQuery() {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        return $this->normalizePath((string) parse_url($requestUri, PHP_URL_PATH));
    }

    protected function getSpecialEndpointFromUri($requestUri) {
        $requestPath = (string) parse_url((string) $requestUri, PHP_URL_PATH);
        if($requestPath === '') return '';
        $normalized = rtrim($this->normalizePath($requestPath), '/');
        if($normalized === '/pwna-track') return 'track';
        if($normalized === '/pwna-realtime') return 'realtime';
        return '';
    }

    protected function isTrackingEndpointRequest() {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        return $requestUri !== '' && $this->getSpecialEndpointFromUri($requestUri) === 'track';
    }

    /**
     * True if a stored/derived path points at the analytics endpoints themselves.
     * Guards against the endpoint ever being recorded as a pageview (e.g. when
     * the request is not recognised as the endpoint on subdir/proxy setups).
     */
    protected function isEndpointPath($path) {
        $p = $this->normalizePath((string) $path);
        if(strpos($p, '?') !== false) $p = strtok($p, '?');
        $p = rtrim($p, '/');
        return $p === '/pwna-track' || $p === '/pwna-realtime';
    }

    protected function getPayloadPathForStorage(array $payload) {
        foreach(['path', 'pathname'] as $key) {
            if(isset($payload[$key]) && is_scalar($payload[$key])) {
                $path = trim((string) $payload[$key]);
                if($path !== '') return $this->normalizePath($path);
            }
        }
        if(isset($payload['url']) && is_scalar($payload['url'])) {
            $url = trim((string) $payload['url']);
            if($url !== '') return $this->normalizePath($url);
        }
        return '';
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
        if(($payload['type'] ?? '') === 'time_on_page') {
            $this->handleTimeOnPageRequest($payload);
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
        if($this->isBotUserAgent($ua)) $this->sendTrackingResponse(204);
        if($this->isIpBlocked()) $this->sendTrackingResponse(204);
        if($this->respectDnt && isset($_SERVER['HTTP_DNT']) && (string) $_SERVER['HTTP_DNT'] === '1') $this->sendTrackingResponse(204);
        if($this->isSuspiciousProbePath($payload['path'] ?? null)) $this->sendTrackingResponse(204);
        if($this->requireConsent && !$this->hasConsentCookie()) $this->sendTrackingResponse(204);

        $ids = $this->resolveIncomingTrackingIds($payload);
        $visitorId = $ids['visitor_id'];
        $sessionId = $ids['session_id'];
        if($visitorId === '' || $sessionId === '') {
            $this->sendTrackingResponse(422, ['ok' => false, 'message' => 'Missing visitor or session id']);
        }

        $page = $this->wire('page');
        $endpointRequest = $this->isTrackingEndpointRequest();
        $payloadPath = $this->getPayloadPathForStorage($payload);
        if($payloadPath === '') $payloadPath = $this->getRequestPathForStorage();

        $statusCode = isset($payload['statusCode']) ? max(100, min(599, (int) $payload['statusCode'])) : 200;
        if(!$endpointRequest && $page && $page->id && !$this->is404Page($page)) {
            $statusCode = 200;
        }
        $path = $payloadPath;

        // Same aggressive 404 bot filtering as the server-side pipeline:
        //  - IP-rate-limited 404 scanners
        //  - Unidentifiable user-agents on a 404
        //  - 404s on paths that actually resolve via redirect modules
        if($statusCode === 404) {
            if($this->pathResolvesToValidPage($path)) $this->sendTrackingResponse(204);
            if($this->ipIsRecent404Scanner()) $this->sendTrackingResponse(204);
            if($this->isLikelyBotFromUserAgent($ua)) $this->sendTrackingResponse(204);
        }

        // Never record the tracking endpoint itself as a pageview.
        if($this->isEndpointPath($path)) $this->sendTrackingResponse(204);

        $url = $this->trimValue((string) ($payload['url'] ?? $this->getCurrentRequestUrl()), 767);

        $pageId = max(0, (int) ($payload['pageId'] ?? 0));
        $pageTitle = $this->cleanTextValue((string) ($payload['pageTitle'] ?? ''), 255);
        $template = trim((string) ($payload['template'] ?? ''));
        if($statusCode === 404) {
            $template = '404';
            $pageId = 0;
            $pageTitle = '404';
        } elseif(!$endpointRequest && $page && $page->id && $page->template && $page->template->name !== 'admin') {
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
            'utm_term' => $this->trimValue((string) ($queryVars['utm_term'] ?? ''), 191),
            'utm_content' => $this->trimValue((string) ($queryVars['utm_content'] ?? ''), 191),
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
            $this->recordSessionAttribution($row);
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

    protected function handleTimeOnPageRequest(array $payload) {
        if(!$this->trackingEnabled) $this->sendTrackingResponse(204);
        if($this->respectDnt && isset($_SERVER['HTTP_DNT']) && (string) $_SERVER['HTTP_DNT'] === '1') $this->sendTrackingResponse(204);
        if($this->isIpBlocked()) $this->sendTrackingResponse(204);
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
        if($this->isBotUserAgent($ua)) $this->sendTrackingResponse(204);
        if($this->requireConsent && !$this->hasConsentCookie()) $this->sendTrackingResponse(204);
        $seconds = max(0, min(65535, (int) ($payload['seconds'] ?? 0)));
        if($seconds < 1) $this->sendTrackingResponse(204);
        $ids = $this->resolveIncomingTrackingIds($payload);
        $sessionId = trim((string) ($ids['session_id'] ?? ''));
        if($sessionId === '') $this->sendTrackingResponse(204);
        $sessionHash = $this->hashValue($sessionId);
        try {
            $db = $this->wire('database');
            // Update the most recent hit for this session, keeping the highest value seen.
            $stmt = $db->prepare(
                "UPDATE `" . self::HITS_TABLE . "`
                 SET `time_on_page` = GREATEST(`time_on_page`, :seconds)
                 WHERE `session_hash` = :hash
                 ORDER BY `created_at` DESC
                 LIMIT 1"
            );
            $stmt->execute([':seconds' => $seconds, ':hash' => $sessionHash]);
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'time_on_page update failed: ' . $e->getMessage());
        }
        $this->sendTrackingResponse(204);
    }

    protected function handleEventTrackingRequest(array $payload) {
        if(!$this->trackingEnabled || !$this->eventTrackingEnabled) $this->sendTrackingResponse(204);

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
        if($this->isBotUserAgent($ua)) $this->sendTrackingResponse(204);
        if($this->isIpBlocked()) $this->sendTrackingResponse(204);
        if($this->respectDnt && isset($_SERVER['HTTP_DNT']) && (string) $_SERVER['HTTP_DNT'] === '1') $this->sendTrackingResponse(204);
        if($this->isSuspiciousProbePath($payload['path'] ?? null)) $this->sendTrackingResponse(204);
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
        $endpointRequest = $this->isTrackingEndpointRequest();
        $path = $this->getPayloadPathForStorage($payload);
        if($path === '') $path = $this->getRequestPathForStorage();
        $url = $this->trimValue((string) ($payload['url'] ?? $this->getCurrentRequestUrl()), 767);
        $pageId = max(0, (int) ($payload['pageId'] ?? 0));
        $pageTitle = $this->cleanTextValue((string) ($payload['pageTitle'] ?? ''), 255);
        $template = trim((string) ($payload['template'] ?? ''));
        if(!$endpointRequest && $page && $page->id && $page->template && $page->template->name !== 'admin') {
            if($pageId < 1) $pageId = (int) $page->id;
            if($pageTitle === '') $pageTitle = $this->cleanTextValue((string) $page->get('title'), 255);
            if($template === '') $template = (string) $page->template->name;
            $path = $this->getCanonicalPagePath($page);
        }

        $label = $this->trimValue((string) ($extra['label'] ?? ''), 255);
        if($label === '') $label = $this->trimValue((string) ($extra['text'] ?? ''), 255);
        $target = $this->trimValue((string) ($extra['target'] ?? ''), 767);
        if($target === '') $target = $this->trimValue((string) ($extra['href'] ?? ''), 767);

        // Never record an event whose path is the tracking endpoint itself.
        if($this->isEndpointPath($path)) $this->sendTrackingResponse(204);

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
        $day = date('Y-m-d', strtotime('-1 day'));
        $this->rebuildDailyAggregate($day);
        $this->rebuildEventDailyAggregate($day);
        $this->rebuildGoalDailyAggregate($day);
        $deleted = $this->purgeOldHits() + $this->purgeOldEvents();
        $this->purgeOldAttribution();
        $this->saveConfigValue('lastPurgeAt', date('Y-m-d H:i:s'));

        // InnoDB does not return freed disk space to the OS after DELETE, so the
        // database file keeps growing even though old rows are gone. When enabled,
        // rebuild the raw tables (at most weekly) to actually reclaim that space.
        if(!empty($this->optimizeAfterPurge) && $deleted > 0) {
            $lastOptimize = strtotime((string) $this->lastOptimizeAt);
            if(!$lastOptimize || $lastOptimize < strtotime('-7 days')) {
                $this->optimizeTables();
            }
        }

        $this->maybeSendMonthlyReport();
    }

    public function handleHourlyCron() {
        if(!empty($this->botFilterEnabled)) $this->markBehavioralBots(24);
        foreach([date('Y-m-d', strtotime('-1 day')), date('Y-m-d')] as $day) {
            $this->rebuildDailyAggregate($day);
            $this->rebuildEventDailyAggregate($day);
            $this->rebuildGoalDailyAggregate($day);
        }
        $this->purgeOldRealtimeSessions();
    }

    /**
     * Post-hoc behavioral bot classifier.
     *
     * UA-based detection (matomo/device-detector + regex) cannot catch
     * headless-browser scrapers and rotating-residential-proxy fleets that
     * present real-looking UA strings. This scans recently-recorded hits for
     * the behavioral signature of such fleets and marks matching sessions
     * is_bot = 1 with a short bot_reason. Rows stay in the table for forensic
     * review; analytics queries exclude them by default via buildWhere().
     *
     * Rules (conservative — favor false negatives over false positives):
     *  - ua_fleet:     N+ single-hit/no-referrer sessions sharing one UA across
     *                  M+ distinct IPs within a time window (rotating proxies).
     *  - ip_excessive: K+ single-hit/no-referrer sessions from one IP in an hour.
     *
     * Per-session safety: only sessions that themselves match the bot pattern
     * (single-hit + no-referrer) are flagged, so a legitimate visitor on a
     * stale browser version sharing a UA is not collateral damage.
     *
     * Originally contributed by adrianbj (PR #4). Thresholds are configurable
     * via module settings; the values passed here fall back to those defaults.
     *
     * @param int $lookbackHours How far back to scan.
     * @return array Counts keyed by reason.
     */
    public function markBehavioralBots($lookbackHours = 24) {
        $db = $this->wire('database');
        $hits = self::HITS_TABLE;
        $events = self::EVENTS_TABLE;

        $lookbackHours = max(1, (int) $lookbackHours);
        $uaSessions = max(2, (int) $this->botFleetMinSessions);
        $uaIps      = max(2, (int) $this->botFleetMinIps);
        $uaWindow   = max(5, (int) $this->botFleetWindowMinutes);
        $ipHits     = max(2, (int) $this->botIpMaxHitsPerHour);

        $result = ['ua_fleet' => 0, 'ip_excessive' => 0, 'scanner_404' => 0, 'events_propagated' => 0];
        $since = date('Y-m-d H:i:s', time() - ($lookbackHours * 3600));

        try {
            // Rule 1: ua_fleet — a single UA producing many single-hit,
            // no-referrer sessions across many distinct IPs in a short window.
            $sql = "UPDATE `{$hits}` h
                JOIN (
                    SELECT `user_agent`
                    FROM `{$hits}`
                    WHERE `created_at` >= :since
                      AND `is_bot` = 0
                      AND `referrer_host` = ''
                      AND `user_agent` <> ''
                    GROUP BY `user_agent`
                    HAVING COUNT(DISTINCT `session_hash`) >= :min_sessions
                       AND COUNT(DISTINCT `ip_hash`) >= :min_ips
                       AND TIMESTAMPDIFF(MINUTE, MIN(`created_at`), MAX(`created_at`)) <= :window
                ) fleet ON fleet.`user_agent` = h.`user_agent`
                JOIN (
                    SELECT `session_hash`
                    FROM `{$hits}`
                    WHERE `created_at` >= :since2
                    GROUP BY `session_hash`
                    HAVING COUNT(*) = 1 AND MAX(`referrer_host`) = ''
                ) single ON single.`session_hash` = h.`session_hash`
                SET h.`is_bot` = 1, h.`bot_reason` = 'ua_fleet'
                WHERE h.`is_bot` = 0 AND h.`created_at` >= :since3";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':since' => $since, ':since2' => $since, ':since3' => $since,
                ':min_sessions' => $uaSessions, ':min_ips' => $uaIps, ':window' => $uaWindow,
            ]);
            $result['ua_fleet'] = (int) $stmt->rowCount();

            // Rule 2: ip_excessive — one IP producing many single-hit,
            // no-referrer sessions within a single clock hour.
            $sql = "UPDATE `{$hits}` h
                JOIN (
                    SELECT `ip_hash`, `created_date`, `created_hour`
                    FROM `{$hits}`
                    WHERE `created_at` >= :since
                      AND `is_bot` = 0
                      AND `referrer_host` = ''
                      AND `ip_hash` <> ''
                    GROUP BY `ip_hash`, `created_date`, `created_hour`
                    HAVING COUNT(DISTINCT `session_hash`) >= :max_hits
                ) burst ON burst.`ip_hash` = h.`ip_hash`
                      AND burst.`created_date` = h.`created_date`
                      AND burst.`created_hour` = h.`created_hour`
                JOIN (
                    SELECT `session_hash`
                    FROM `{$hits}`
                    WHERE `created_at` >= :since2
                    GROUP BY `session_hash`
                    HAVING COUNT(*) = 1 AND MAX(`referrer_host`) = ''
                ) single ON single.`session_hash` = h.`session_hash`
                SET h.`is_bot` = 1, h.`bot_reason` = 'ip_excessive'
                WHERE h.`is_bot` = 0 AND h.`created_at` >= :since3";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':since' => $since, ':since2' => $since, ':since3' => $since,
                ':max_hits' => $ipHits,
            ]);
            $result['ip_excessive'] = (int) $stmt->rowCount();

            // Rule 3: scanner_404 — any session whose only hit is a 404. Catches recon
            // scanners probing for missing paths. Real users almost always produce a second
            // action after a 404, so COUNT(*) = 1 with a 404 is a reliable scanner signal.
            $sql = "UPDATE `{$hits}` h
                JOIN (
                    SELECT `session_hash`
                    FROM `{$hits}`
                    WHERE `created_at` >= :since
                      AND `is_bot` = 0
                      AND `session_hash` <> ''
                    GROUP BY `session_hash`
                    HAVING COUNT(*) = 1 AND MIN(`status_code`) = 404
                ) scanner ON scanner.`session_hash` = h.`session_hash`
                SET h.`is_bot` = 1, h.`bot_reason` = 'scanner_404'
                WHERE h.`is_bot` = 0 AND h.`created_at` >= :since2";
            $stmt = $db->prepare($sql);
            $stmt->execute([':since' => $since, ':since2' => $since]);
            $result['scanner_404'] = (int) $stmt->rowCount();

            // Propagate the flag to events by session_hash.
            $sql = "UPDATE `{$events}` e
                JOIN (
                    SELECT DISTINCT `session_hash`, `bot_reason`
                    FROM `{$hits}`
                    WHERE `is_bot` = 1 AND `created_at` >= :since AND `session_hash` <> ''
                ) b ON b.`session_hash` = e.`session_hash`
                SET e.`is_bot` = 1, e.`bot_reason` = b.`bot_reason`
                WHERE e.`is_bot` = 0";
            $stmt = $db->prepare($sql);
            $stmt->execute([':since' => $since]);
            $result['events_propagated'] = (int) $stmt->rowCount();
        } catch(\Throwable $e) {
            $this->log('markBehavioralBots failed: ' . $e->getMessage());
        }

        return $result;
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
            WHERE `created_at` >= :start AND `created_at` < :end AND `is_bot` = 0
            GROUP BY `page_id`, `template`, `path`, `path_hash`";
        $stmt = $db->prepare($sql);
        $stmt->execute([':day' => $day, ':start' => $day . ' 00:00:00', ':end' => $nextDay . ' 00:00:00']);
    }

    public function rebuildEventDailyAggregate($day) {
        $day = date('Y-m-d', strtotime($day));
        $nextDay = date('Y-m-d', strtotime($day . ' +1 day'));
        $db = $this->wire('database');

        $stmt = $db->prepare("DELETE FROM `" . self::EVENT_DAILY_TABLE . "` WHERE `day` = :day");
        $stmt->execute([':day' => $day]);

        $sql = "INSERT INTO `" . self::EVENT_DAILY_TABLE . "`
            (`day`,`page_id`,`template`,`event_group`,`event_name`,`event_label`,`event_label_hash`,`event_target`,`event_target_hash`,`events`,`uniques`,`sessions`)
            SELECT :day, `page_id`, `template`, `event_group`, `event_name`, `event_label`, MD5(`event_label`), `event_target`, MD5(`event_target`),
                   COUNT(*), COUNT(DISTINCT `visitor_hash`), COUNT(DISTINCT `session_hash`)
            FROM `" . self::EVENTS_TABLE . "`
            WHERE `created_at` >= :start AND `created_at` < :end AND `is_bot` = 0
            GROUP BY `page_id`, `template`, `event_group`, `event_name`, `event_label`, `event_target`";
        $stmt = $db->prepare($sql);
        $stmt->execute([':day' => $day, ':start' => $day . ' 00:00:00', ':end' => $nextDay . ' 00:00:00']);
    }

    public function rebuildGoalDailyAggregate($day) {
        $day = date('Y-m-d', strtotime($day));
        $range = $this->getDateRangeBetween($day, $day, 1);
        $db = $this->wire('database');
        $stmt = $db->prepare("DELETE FROM `" . self::GOAL_DAILY_TABLE . "` WHERE `day` = :day");
        $stmt->execute([':day' => $day]);

        foreach($this->getGoals(true) as $goal) {
            if(empty($goal['active'])) continue;
            $stats = $this->calculateSingleGoalStats($goal, $range, []);
            $insert = $db->prepare("INSERT INTO `" . self::GOAL_DAILY_TABLE . "` (`day`,`goal_id`,`conversions`,`uniques`,`sessions`) VALUES (:day,:goal_id,:conversions,:uniques,:sessions)");
            $insert->execute([
                ':day' => $day,
                ':goal_id' => (int) $goal['id'],
                ':conversions' => (int) ($stats['conversions'] ?? 0),
                ':uniques' => (int) ($stats['uniques'] ?? 0),
                ':sessions' => (int) ($stats['sessions'] ?? 0),
            ]);
        }
    }

    public function purgeOldHits() {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(7, (int) $this->rawRetentionDays) . ' days'));
        $stmt = $this->wire('database')->prepare("DELETE FROM `" . self::HITS_TABLE . "` WHERE `created_at` < :cutoff");
        $stmt->execute([':cutoff' => $cutoff]);
        return (int) $stmt->rowCount();
    }


    public function purgeOldEvents() {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(7, (int) $this->rawEventRetentionDays) . ' days'));
        $stmt = $this->wire('database')->prepare("DELETE FROM `" . self::EVENTS_TABLE . "` WHERE `created_at` < :cutoff");
        $stmt->execute([':cutoff' => $cutoff]);
        return (int) $stmt->rowCount();
    }

    /**
     * Run retention purge immediately (hits + events + stale realtime sessions)
     * and record the time. Returns the number of raw rows deleted.
     */
    public function runManualPurge() {
        $deleted = $this->purgeOldHits() + $this->purgeOldEvents();
        $this->purgeOldAttribution();
        $this->purgeOldRealtimeSessions();
        $this->saveConfigValue('lastPurgeAt', date('Y-m-d H:i:s'));
        return $deleted;
    }

    /**
     * Attribution follows the raw-hits retention window: once the hits that
     * created a session are gone, its attribution row is no longer needed.
     */
    public function purgeOldAttribution() {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(7, (int) $this->rawRetentionDays) . ' days'));
        $stmt = $this->wire('database')->prepare("DELETE FROM `" . self::ATTRIBUTION_TABLE . "` WHERE `created_at` < :cutoff");
        $stmt->execute([':cutoff' => $cutoff]);
        return (int) $stmt->rowCount();
    }

    /**
     * Rebuild the analytics tables to return freed space to the filesystem.
     *
     * InnoDB keeps deleted-row space inside its tablespace, so the DB file does
     * not shrink after purging. OPTIMIZE TABLE performs an online rebuild that
     * compacts the table and reclaims that space. Safe to run on all pwna_*
     * tables; the raw hits/events tables are where the growth actually lives.
     */
    public function optimizeTables() {
        $db = $this->wire('database');
        $tables = [
            self::HITS_TABLE, self::EVENTS_TABLE, self::SESSIONS_TABLE,
            self::ATTRIBUTION_TABLE,
            self::DAILY_TABLE, self::EVENT_DAILY_TABLE, self::GOAL_DAILY_TABLE,
        ];
        foreach($tables as $table) {
            try {
                // OPTIMIZE TABLE returns a result set (Table, Op, Msg_type,
                // Msg_text). It must be fully consumed and the cursor closed,
                // otherwise the next query fails with SQLSTATE HY000 2014
                // "Cannot execute queries while other unbuffered queries are active".
                $stmt = $db->query("OPTIMIZE TABLE `" . $table . "`");
                if($stmt instanceof \PDOStatement) {
                    $stmt->fetchAll();
                    $stmt->closeCursor();
                }
            } catch(\Throwable $e) {
                $this->wire('log')->save('native-analytics', 'OPTIMIZE TABLE failed for ' . $table . ': ' . $e->getMessage());
            }
        }
        $this->saveConfigValue('lastOptimizeAt', date('Y-m-d H:i:s'));
        return true;
    }

    /**
     * Per-table row counts and on-disk size (data + index) for the pwna_*
     * tables, read from information_schema. Used by the config status panel.
     */
    public function getStorageStats() {
        $db = $this->wire('database');
        $tables = [
            self::HITS_TABLE       => 'Raw hits',
            self::EVENTS_TABLE     => 'Raw events',
            self::SESSIONS_TABLE   => 'Realtime sessions',
            self::ATTRIBUTION_TABLE => 'Attribution',
            self::DAILY_TABLE      => 'Daily aggregates',
            self::EVENT_DAILY_TABLE => 'Event aggregates',
            self::GOAL_DAILY_TABLE => 'Goal aggregates',
        ];
        $stats = [];
        $totalBytes = 0;
        try {
            $stmt = $db->prepare(
                "SELECT TABLE_NAME, TABLE_ROWS, (DATA_LENGTH + INDEX_LENGTH) AS SIZE_BYTES
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . implode(',', array_fill(0, count($tables), '?')) . ")"
            );
            $stmt->execute(array_keys($tables));
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $byName = [];
            foreach($rows as $r) $byName[$r['TABLE_NAME']] = $r;
            foreach($tables as $name => $label) {
                $bytes = isset($byName[$name]) ? (int) $byName[$name]['SIZE_BYTES'] : 0;
                // TABLE_ROWS is an estimate for InnoDB; get the exact count for
                // the raw tables where accuracy matters most.
                $count = 0;
                try {
                    $c = $db->query("SELECT COUNT(*) FROM `" . $name . "`");
                    $count = (int) $c->fetchColumn();
                    $c->closeCursor();
                } catch(\Throwable $e) {
                    $count = isset($byName[$name]) ? (int) $byName[$name]['TABLE_ROWS'] : 0;
                }
                $stats[] = ['label' => $label, 'table' => $name, 'rows' => $count, 'bytes' => $bytes];
                $totalBytes += $bytes;
            }
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'getStorageStats failed: ' . $e->getMessage());
        }
        return ['tables' => $stats, 'totalBytes' => $totalBytes];
    }

    public static function formatBytes($bytes) {
        $bytes = max(0, (int) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
        return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
    }

    /**
     * Persist a single module config value without disturbing the rest.
     */
    /**
     * Persist the current funnel definition (raw multi-line text) so the
     * dashboard funnel survives reloads. Trimmed and length-capped.
     */
    public function saveFunnelSteps($raw) {
        $raw = trim((string) $raw);
        if(strlen($raw) > 4000) $raw = substr($raw, 0, 4000);
        $this->saveConfigValue('funnelSteps', $raw);
        return $raw;
    }

    protected function saveConfigValue($key, $value) {
        $this->set($key, $value);

        // Modules::saveConfig() persists the array supplied to it as the module's
        // configuration. Passing only one runtime marker here can therefore wipe
        // user settings (tracking mode, storage mode, consent options, etc.).
        // Always merge the marker into the currently persisted configuration.
        try {
            $modules = $this->wire('modules');
            $config = $modules->getConfig($this);
            if(!is_array($config)) $config = [];
            $config[$key] = $value;
            $modules->saveConfig($this, $config);
        } catch(\Throwable $e) {
            try {
                $modules = $this->wire('modules');
                $config = $modules->getConfig('NativeAnalytics');
                if(!is_array($config)) $config = [];
                $config[$key] = $value;
                $modules->saveConfig('NativeAnalytics', $config);
            } catch(\Throwable $e2) {
                $this->wire('log')->save('native-analytics', 'Could not save config value ' . $key . ': ' . $e2->getMessage());
            }
        }
    }

    public function purgeOldRealtimeSessions($hours = 48) {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(1, (int) $hours) . ' hours'));
        $stmt = $this->wire('database')->prepare("DELETE FROM `" . self::SESSIONS_TABLE . "` WHERE `last_seen_at` < :cutoff");
        $stmt->execute([':cutoff' => $cutoff]);
    }

    public function resetAnalyticsData() {
        $db = $this->wire('database');
        $db->exec("DELETE FROM `" . self::GOAL_DAILY_TABLE . "`");
        $db->exec("DELETE FROM `" . self::EVENT_DAILY_TABLE . "`");
        $db->exec("DELETE FROM `" . self::EVENTS_TABLE . "`");
        $db->exec("DELETE FROM `" . self::SESSIONS_TABLE . "`");
        $db->exec("DELETE FROM `" . self::ATTRIBUTION_TABLE . "`");
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
        $showBots = !empty($this->botFilterEnabled) && !empty($this->bot404ShowBots);
        $filters = $showBots ? ['include_bots' => 1] : [];
        $where = $this->buildWhere($filters, $this->getDateRangeForDays($days), ["status_code = 404"]);
        $sql = "SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques FROM `" . self::HITS_TABLE . "` WHERE {$where['sql']}";
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['views' => 0, 'uniques' => 0];
    }

    public function resolveVisitorIpHashes(array $visitorHashes) {
        $visitorHashes = array_values(array_filter(array_unique(array_map('strval', $visitorHashes))));
        if(!$visitorHashes) return [];
        if(count($visitorHashes) > 1000) $visitorHashes = array_slice($visitorHashes, 0, 1000);

        $map = [];
        try {
            $placeholders = implode(',', array_fill(0, count($visitorHashes), '?'));
            $sql = "SELECT visitor_hash, ip_hash
                    FROM `" . self::HITS_TABLE . "`
                    WHERE visitor_hash IN ({$placeholders})
                      AND ip_hash <> ''
                    ORDER BY created_at DESC";
            $stmt = $this->wire('database')->prepare($sql);
            $stmt->execute($visitorHashes);
            while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $vh = (string) ($row['visitor_hash'] ?? '');
                if($vh !== '' && !isset($map[$vh])) $map[$vh] = (string) ($row['ip_hash'] ?? '');
            }
        } catch(\Throwable $e) {
            $map = [];
        }
        return $map;
    }

    public function getCurrentVisitorsSummary($minutes = null, array $filters = []) {
        $rows = $this->getCurrentVisitors($minutes, 1000, $filters);
        $count = count($rows);
        return ['current_visitors' => $count, 'current_uniques' => $count];
    }

    public function getCurrentVisitors($minutes = null, $limit = 25, array $filters = []) {
        $minutes = $minutes ?: (int) $this->realtimeWindowMinutes;
        $where = $this->buildRealtimeWhere($minutes, $filters);

        // Fetch a fixed pool of recent sessions. A small multiplier can make the
        // displayed list disagree with the summary after bot/probe rows are
        // filtered out, especially on busy sites.
        $fetchLimit = self::CURRENT_VISITORS_FETCH_LIMIT;
        $classifierSql = '';
        if(!empty($this->botFilterEnabled)) {
            $classifierSql = " AND (visitor_hash IS NULL OR visitor_hash = '' OR visitor_hash NOT IN (
                SELECT visitor_hash
                FROM `" . self::HITS_TABLE . "`
                WHERE is_bot = 1
                  AND visitor_hash <> ''
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ))";
        }

        $sql = "SELECT page_id, page_title, template, current_path, current_url, referrer_host, device_type, browser, os, visitor_hash,
                       first_seen_at, last_seen_at, hit_count, status_code
                FROM `" . self::SESSIONS_TABLE . "`
                WHERE {$where['sql']}{$classifierSql}
                ORDER BY last_seen_at DESC
                LIMIT " . (int) $fetchLimit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $result = [];
        $seenHashes = [];
        foreach($rows as $row) {
            $row = $this->normalizeAnalyticsRowForDisplay($row, 'current_path');
            // Hide bot/probe sessions from the live panel even when they were
            // not yet marked by the persistent classifier.
            $isProbeSession = false;
            $statusCode = (int) ($row['status_code'] ?? 0);
            if($statusCode === 404) {
                if(!empty($row['visitor_hash']) && $this->visitorIsRecent404Scanner($row['visitor_hash'])) $isProbeSession = true;
                $browser = strtolower((string) ($row['browser'] ?? ''));
                $os = strtolower((string) ($row['os'] ?? ''));
                if(($browser === '' || $browser === 'other') && ($os === '' || $os === 'other')) $isProbeSession = true;
            }
            if(!empty($row['current_path']) && $this->matchesBuiltInProbePattern($row['current_path'])) $isProbeSession = true;
            if($isProbeSession) continue;

            $hash = (string) ($row['visitor_hash'] ?? '');
            if($hash !== '' && isset($seenHashes[$hash])) {
                $result[$seenHashes[$hash]]['hit_count'] = (int) $result[$seenHashes[$hash]]['hit_count'] + (int) $row['hit_count'];
                continue;
            }

            $result[] = $row;
            if($hash !== '') $seenHashes[$hash] = count($result) - 1;
            if(count($result) >= (int) $limit) break;
        }
        return $result;
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
        $where = $this->buildWhere($filters, $this->getDateRangeForDays($days), ["(utm_source != '' OR utm_medium != '' OR utm_campaign != '' OR utm_content != '')"]);
        $sql = "SELECT CONCAT_WS(' / ',
                    NULLIF(utm_source, ''),
                    NULLIF(utm_medium, ''),
                    NULLIF(utm_campaign, ''),
                    NULLIF(utm_content, '')
                ) AS label,
                utm_source, utm_medium, utm_campaign, utm_content,
                COUNT(*) AS views,
                COUNT(DISTINCT visitor_hash) AS uniques,
                COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::HITS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY utm_source, utm_medium, utm_campaign, utm_content
                ORDER BY views DESC, uniques DESC
                LIMIT " . (int) $limit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * First-touch channel breakdown (Direct / Organic Search / Social /
     * Referral / Paid / Email / Campaign …), one row per acquired session.
     * Reads the session-level attribution table, so counts reflect whole
     * sessions rather than only landing hits.
     */
    public function getTopChannels($days = 30, $limit = 12, array $filters = []) {
        $range = $this->getDateRangeForDays($days);
        $params = [':start' => $range['start'], ':end' => $range['end']];
        $botClause = empty($filters['include_bots']) ? ' AND is_bot = 0' : '';
        $sql = "SELECT channel AS label,
                    COUNT(*) AS sessions,
                    COUNT(DISTINCT visitor_hash) AS uniques
                FROM `" . self::ATTRIBUTION_TABLE . "`
                WHERE created_at >= :start AND created_at <= :end" . $botClause . "
                GROUP BY channel
                ORDER BY sessions DESC, uniques DESC
                LIMIT " . (int) $limit;
        try {
            $stmt = $this->wire('database')->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'getTopChannels failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Session-level campaign breakdown from first-touch attribution.
     * Unlike getTopCampaigns() (per-hit), this counts each acquired session
     * once against the campaign that brought it.
     */
    public function getAttributedCampaigns($days = 30, $limit = 10, array $filters = []) {
        $range = $this->getDateRangeForDays($days);
        $params = [':start' => $range['start'], ':end' => $range['end']];
        $botClause = empty($filters['include_bots']) ? ' AND is_bot = 0' : '';
        $sql = "SELECT CONCAT_WS(' / ',
                    NULLIF(utm_source, ''),
                    NULLIF(utm_medium, ''),
                    NULLIF(utm_campaign, ''),
                    NULLIF(utm_content, '')
                ) AS label,
                COUNT(*) AS sessions,
                COUNT(DISTINCT visitor_hash) AS uniques
                FROM `" . self::ATTRIBUTION_TABLE . "`
                WHERE created_at >= :start AND created_at <= :end" . $botClause . "
                  AND (utm_source != '' OR utm_medium != '' OR utm_campaign != '' OR utm_content != '')
                GROUP BY utm_source, utm_medium, utm_campaign, utm_content
                ORDER BY sessions DESC, uniques DESC
                LIMIT " . (int) $limit;
        try {
            $stmt = $this->wire('database')->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'getAttributedCampaigns failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Parse a raw multi-line funnel definition into ordered steps.
     * Each non-empty line is one step. Syntax per line:
     *   /path/here            page step, exact path match
     *   /section/*            page step, prefix match
     *   event:group           event step, any event in that group
     *   event:group/name      event step, group + name
     *   event:group/name/label event step, group + name + label
     *   Label = <spec>        optional display label before '='
     * Capped at 8 steps.
     *
     * @return array List of step descriptors. Page steps:
     *   ['label','type'=>'path','match','prefix'].
     * Event steps:
     *   ['label','type'=>'event','group','name','event_label'].
     */
    public function parseFunnelSteps($raw) {
        $steps = [];
        foreach(preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
            $line = trim($line);
            if($line === '') continue;
            $label = '';
            if(strpos($line, '=') !== false) {
                list($label, $line) = array_map('trim', explode('=', $line, 2));
            }
            if($line === '') continue;

            if(stripos($line, 'event:') === 0) {
                $spec = trim(substr($line, 6));
                if($spec === '') continue;
                $parts = array_map('trim', explode('/', $spec, 3));
                $group = $parts[0] ?? '';
                if($group === '') continue;
                $name = $parts[1] ?? '';
                $eventLabel = $parts[2] ?? '';
                $auto = 'event: ' . $group . ($name !== '' ? ' / ' . $name : '') . ($eventLabel !== '' ? ' / ' . $eventLabel : '');
                $steps[] = [
                    'label' => $label !== '' ? $label : $auto,
                    'type' => 'event',
                    'group' => $group,
                    'name' => $name,
                    'event_label' => $eventLabel,
                ];
            } else {
                $prefix = false;
                if(substr($line, -1) === '*') {
                    $prefix = true;
                    $line = rtrim($line, '*');
                    if($line === '') continue;
                }
                $steps[] = [
                    'label' => $label !== '' ? $label : ($prefix ? $line . '*' : $line),
                    'type' => 'path',
                    'match' => $line,
                    'prefix' => $prefix,
                ];
            }
            if(count($steps) >= 8) break;
        }
        return $steps;
    }

    /**
     * Compute a sequential funnel that can mix page (path) and event steps.
     *
     * Semantics: a session counts for step k if it reached step k at or after
     * the time it first reached step k-1 (using each step's first-occurrence
     * time). Sessions that skip a step drop out from there on. Only sessions
     * that entered step 1 are considered.
     *
     * Page steps are matched against pwna_hits, event steps against
     * pwna_events; first-touch times from both are merged per session before
     * the sequential walk, so the two kinds can interleave freely.
     *
     * @return array ['steps' => [['label','type','sessions','conv_from_start','conv_from_prev','dropoff'], ...], 'entered' => int]
     */
    public function getFunnelReport(array $steps, $days = 30, array $filters = []) {
        $steps = array_slice(array_values($steps), 0, 8);
        $n = count($steps);
        if($n < 2) return ['steps' => [], 'entered' => 0];

        $range = $this->getDateRangeForDays($days);
        $botClause = empty($filters['include_bots']) ? ' AND is_bot = 0' : '';

        // Merged per-session first-touch times, indexed by step position.
        $times = [];
        $ensure = function($sh) use (&$times, $n) {
            if(!isset($times[$sh])) $times[$sh] = array_fill(0, $n, null);
        };

        // --- Page steps → pwna_hits ---
        $pathSelects = [];
        $pathOr = [];
        $pathParams = [':start' => $range['start'], ':end' => $range['end']];
        foreach($steps as $i => $step) {
            if(($step['type'] ?? 'path') !== 'path') continue;
            $key = ':fp' . $i;
            if(!empty($step['prefix'])) {
                $cond = "path LIKE {$key}";
                $pathParams[$key] = pwna_escape_like_term((string) $step['match']) . '%';
            } else {
                $cond = "path = {$key}";
                $pathParams[$key] = (string) $step['match'];
            }
            $pathSelects[] = "MIN(CASE WHEN {$cond} THEN created_at END) AS t{$i}";
            $pathOr[] = $cond;
        }
        if($pathSelects) {
            $sql = "SELECT session_hash, " . implode(', ', $pathSelects) . "
                    FROM `" . self::HITS_TABLE . "`
                    WHERE created_at >= :start AND created_at <= :end" . $botClause . "
                      AND (" . implode(' OR ', $pathOr) . ")
                    GROUP BY session_hash";
            try {
                $stmt = $this->wire('database')->prepare($sql);
                $stmt->execute($pathParams);
                while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $sh = $row['session_hash'];
                    $ensure($sh);
                    foreach($steps as $i => $step) {
                        if(($step['type'] ?? 'path') !== 'path') continue;
                        if($row['t' . $i] !== null) $times[$sh][$i] = $row['t' . $i];
                    }
                }
            } catch(\Throwable $e) {
                $this->wire('log')->save('native-analytics', 'getFunnelReport (path) failed: ' . $e->getMessage());
                return ['steps' => [], 'entered' => 0];
            }
        }

        // --- Event steps → pwna_events ---
        $eventSelects = [];
        $eventOr = [];
        $eventParams = [':start' => $range['start'], ':end' => $range['end']];
        foreach($steps as $i => $step) {
            if(($step['type'] ?? 'path') !== 'event') continue;
            $conds = ["event_group = :eg{$i}"];
            $eventParams[":eg{$i}"] = (string) $step['group'];
            if(($step['name'] ?? '') !== '') {
                $conds[] = "event_name = :en{$i}";
                $eventParams[":en{$i}"] = (string) $step['name'];
            }
            if(($step['event_label'] ?? '') !== '') {
                $conds[] = "event_label = :el{$i}";
                $eventParams[":el{$i}"] = (string) $step['event_label'];
            }
            $cond = '(' . implode(' AND ', $conds) . ')';
            $eventSelects[] = "MIN(CASE WHEN {$cond} THEN created_at END) AS t{$i}";
            $eventOr[] = $cond;
        }
        if($eventSelects) {
            $sql = "SELECT session_hash, " . implode(', ', $eventSelects) . "
                    FROM `" . self::EVENTS_TABLE . "`
                    WHERE created_at >= :start AND created_at <= :end" . $botClause . "
                      AND (" . implode(' OR ', $eventOr) . ")
                    GROUP BY session_hash";
            try {
                $stmt = $this->wire('database')->prepare($sql);
                $stmt->execute($eventParams);
                while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $sh = $row['session_hash'];
                    $ensure($sh);
                    foreach($steps as $i => $step) {
                        if(($step['type'] ?? 'path') !== 'event') continue;
                        if($row['t' . $i] !== null) $times[$sh][$i] = $row['t' . $i];
                    }
                }
            } catch(\Throwable $e) {
                $this->wire('log')->save('native-analytics', 'getFunnelReport (event) failed: ' . $e->getMessage());
                return ['steps' => [], 'entered' => 0];
            }
        }

        // --- Sequential walk per session ---
        $counts = array_fill(0, $n, 0);
        foreach($times as $row) {
            if($row[0] === null) continue; // never entered step 1
            $prev = $row[0];
            $counts[0]++;
            for($k = 1; $k < $n; $k++) {
                $tk = $row[$k];
                if($tk !== null && $tk >= $prev) {
                    $counts[$k]++;
                    $prev = $tk;
                } else {
                    break;
                }
            }
        }

        $entered = $counts[0];
        $out = [];
        foreach($steps as $i => $step) {
            $sessions = $counts[$i];
            $prevSessions = $i > 0 ? $counts[$i - 1] : $sessions;
            $out[] = [
                'label' => (string) $step['label'],
                'type' => (string) ($step['type'] ?? 'path'),
                'sessions' => $sessions,
                'conv_from_start' => $entered > 0 ? round($sessions / $entered * 100, 1) : 0.0,
                'conv_from_prev' => $prevSessions > 0 ? round($sessions / $prevSessions * 100, 1) : 0.0,
                'dropoff' => $prevSessions > 0 ? max(0, $prevSessions - $sessions) : 0,
            ];
        }
        return ['steps' => $out, 'entered' => $entered];
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


    public function searchPagesWithData($term, $limit = 10) {
        $term = trim((string) $term);
        $limit = max(1, min(50, (int) $limit));
        if($term === '') return [];
        if(function_exists('mb_strlen')) {
            if(mb_strlen($term) < 2) return [];
        } elseif(strlen($term) < 2) {
            return [];
        }

        $params = [];
        // Page finder is used to populate the Page ID filter, so only return
        // rows that point to a real ProcessWire page. Exclude known bots and
        // 404s to avoid suggesting noisy probe URLs or classifier-flagged hits.
        $whereParts = ["page_id > 0", "is_bot = 0", "status_code != 404"];
        if(ctype_digit($term)) {
            $whereParts[] = "page_id = :page_id";
            $params[':page_id'] = (int) $term;
        } else {
            $like = '%' . pwna_escape_like_term($term) . '%';
            $whereParts[] = "(page_title LIKE :like_title OR path LIKE :like_path)";
            $params[':like_title'] = $like;
            $params[':like_path'] = $like;
        }

        $sql = "SELECT page_id, MAX(page_title) AS page_title, MAX(template) AS template, MAX(path) AS path,
                       COUNT(*) AS views, MAX(created_at) AS last_seen_at
                FROM `" . self::HITS_TABLE . "`
                WHERE " . implode(' AND ', $whereParts) . "
                GROUP BY page_id
                ORDER BY views DESC, last_seen_at DESC
                LIMIT " . (int) $limit;
        try {
            $stmt = $this->wire('database')->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach($rows as &$row) $row = $this->normalizeAnalyticsRowForDisplay($row);
            unset($row);
            return $rows;
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Page search failed: ' . $e->getMessage());
            return [];
        }
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
                       AVG(hits_per_session) AS avg_pages_per_session,
                       AVG(CASE WHEN total_time > 0 THEN total_time ELSE NULL END) AS avg_time_on_page
                FROM (
                    SELECT session_hash, COUNT(*) AS hits_per_session, SUM(time_on_page) AS total_time
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
        $avgTime = (float) ($row['avg_time_on_page'] ?? 0);
        return [
            'total_sessions' => $total,
            'single_page_sessions' => $single,
            'avg_pages_per_session' => round($avg, 2),
            'single_page_rate' => $singleRate,
            'avg_time_on_page' => round($avgTime),
        ];
    }

    public function getTop404Paths($days = 30, $limit = 15) {
        $showBots = !empty($this->botFilterEnabled) && !empty($this->bot404ShowBots);
        $filters = $showBots ? ['include_bots' => 1] : [];
        $where = $this->buildWhere($filters, $this->getDateRangeForDays($days), ["status_code = 404"]);
        // Fetch more rows than needed so we can filter out resolvable ones
        $fetchLimit = max(50, (int) $limit * 3);
        $sql = "SELECT path, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques
                FROM `" . self::HITS_TABLE . "`
                WHERE {$where['sql']}
                GROUP BY path, path_hash
                ORDER BY views DESC, uniques DESC
                LIMIT " . $fetchLimit;
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $result = [];
        foreach($rows as $row) {
            $row['path'] = $this->normalizePath((string) ($row['path'] ?? '/'));
            // Hide paths that resolve through redirect modules
            if($this->pathResolvesToValidPage($row['path'])) continue;
            $result[] = $row;
            if(count($result) >= (int) $limit) break;
        }
        return $result;
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
        $range = $this->getDateRangeForDays($days);
        if(!empty($this->highTrafficMode)) {
            $where = $this->buildEventDailyWhere($filters, $range, $group);
            $sql = "SELECT COALESCE(SUM(events),0) AS events, COALESCE(SUM(uniques),0) AS uniques, COALESCE(SUM(sessions),0) AS sessions
                    FROM `" . self::EVENT_DAILY_TABLE . "` WHERE {$where['sql']}";
            $stmt = $this->wire('database')->prepare($sql);
            $stmt->execute($where['params']);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['events' => 0, 'uniques' => 0, 'sessions' => 0];
        }
        $where = $this->buildEventWhere($filters, $range, $group);
        $sql = "SELECT COUNT(*) AS events, COUNT(DISTINCT visitor_hash) AS uniques, COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::EVENTS_TABLE . "` WHERE {$where['sql']}";
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['events' => 0, 'uniques' => 0, 'sessions' => 0];
    }

    public function getTopEvents($days = 30, $limit = 15, array $filters = [], $group = '') {
        $range = $this->getDateRangeForDays($days);
        if(!empty($this->highTrafficMode)) {
            $where = $this->buildEventDailyWhere($filters, $range, $group);
            $sql = "SELECT event_group, event_name, event_label, event_target,
                           COALESCE(SUM(events),0) AS events, COALESCE(SUM(uniques),0) AS uniques, COALESCE(SUM(sessions),0) AS sessions
                    FROM `" . self::EVENT_DAILY_TABLE . "`
                    WHERE {$where['sql']}
                    GROUP BY event_group, event_name, event_label, event_target
                    ORDER BY events DESC, sessions DESC
                    LIMIT " . (int) $limit;
            $stmt = $this->wire('database')->prepare($sql);
            $stmt->execute($where['params']);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
        $where = $this->buildEventWhere($filters, $range, $group);
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
        $range = $this->getDateRangeForDays($days);
        if(!empty($this->highTrafficMode)) {
            $where = $this->buildEventDailyWhere($filters, $range, $group, ["event_target != ''"]);
            $sql = "SELECT event_group, event_target, COALESCE(SUM(events),0) AS events, COALESCE(SUM(sessions),0) AS sessions
                    FROM `" . self::EVENT_DAILY_TABLE . "`
                    WHERE {$where['sql']}
                    GROUP BY event_group, event_target
                    ORDER BY events DESC, sessions DESC
                    LIMIT " . (int) $limit;
            $stmt = $this->wire('database')->prepare($sql);
            $stmt->execute($where['params']);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
        $where = $this->buildEventWhere($filters, $range, $group, ["event_target != ''"]);
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
        if(!empty($this->highTrafficMode)) {
            $where = $this->buildEventDailyWhere($filters, $range, $group);
            $sql = "SELECT day, COALESCE(SUM(events),0) AS views, COALESCE(SUM(uniques),0) AS uniques, COALESCE(SUM(sessions),0) AS sessions
                    FROM `" . self::EVENT_DAILY_TABLE . "`
                    WHERE {$where['sql']}
                    GROUP BY day
                    ORDER BY day ASC";
        } else {
            $where = $this->buildEventWhere($filters, $range, $group);
            $sql = "SELECT created_date AS day, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques, COUNT(DISTINCT session_hash) AS sessions
                    FROM `" . self::EVENTS_TABLE . "`
                    WHERE {$where['sql']}
                    GROUP BY created_date
                    ORDER BY created_date ASC";
        }
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
        if(empty($filters['include_bots'])) $where[] = 'is_bot = 0';
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
        if(empty($filters['include_bots'])) $where[] = 'is_bot = 0';
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


    protected function buildEventDailyWhere(array $filters, array $range, $group = '', array $extra = []) {
        $where = ['day >= :start_day', 'day <= :end_day'];
        $params = [':start_day' => $range['start_date'], ':end_day' => $range['end_date']];
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
        if($selected !== '' && $selected !== 'site_default') return $selected;
        // $config->dateFormat is a datetime format (PW core default "Y-m-d H:i:s"),
        // so strip the time component to keep this a date-only format.
        $siteFormat = (string) ($this->wire('config')->dateFormat ?? '');
        if($siteFormat === '') $siteFormat = 'd M Y';
        return $this->stripTimeFromDateFormat($siteFormat);
    }

    protected function stripTimeFromDateFormat($format) {
        // A configured date format may include a time component (e.g. "Y-m-d H:i:s").
        // Drop time/timezone tokens plus any now-orphaned separators for a pure date.
        $dateOnly = preg_replace('/[aABgGhHisuveIOPTZ]/', '', $format);
        $dateOnly = trim(preg_replace('/\s{2,}/', ' ', $dateOnly), " \t:,.\/-");
        return $dateOnly !== '' ? $dateOnly : 'Y-m-d';
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

    /**
     * Consent version configured in the PrivacyWire module, or 0 when PrivacyWire
     * is not installed / has no version. Version 0 disables stale-consent checks.
     */
    protected function getPrivacyWireConsentVersion() {
        $modules = $this->wire('modules');
        if(!$modules->isInstalled('PrivacyWire')) return 0;
        $data = $modules->getModuleConfigData('PrivacyWire');
        return isset($data['version']) ? (int) $data['version'] : 0;
    }

    public function getRequestPathForStorage() {
        $page = $this->wire('page');
        if($page && $page->id && !$this->is404Page($page)) {
            return $this->getCanonicalPagePath($page);
        }
        $path = $this->getRequestedPathWithoutQuery();
        // Don't let the analytics endpoints leak in as a fallback path.
        return $this->isEndpointPath($path) ? '/' : $path;
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

    /**
     * Record first-touch acquisition attribution for a session.
     *
     * Called once per hit; INSERT IGNORE on the session_hash primary key means
     * only the FIRST hit of a session persists, capturing the channel/campaign
     * that originally brought the visitor. Later pageviews in the same session
     * (which usually carry no UTM params) do not overwrite it, so reports can
     * attribute the whole session to its true acquisition source.
     *
     * @param array $row A hit row as built by the tracking paths.
     */
    protected function recordSessionAttribution(array $row) {
        $sessionHash = (string) ($row['session_hash'] ?? '');
        if($sessionHash === '') return;

        $channel = $this->classifyChannel(
            (string) ($row['utm_medium'] ?? ''),
            (string) ($row['utm_source'] ?? ''),
            (string) ($row['referrer_host'] ?? '')
        );

        $data = [
            'session_hash' => $sessionHash,
            'visitor_hash' => (string) ($row['visitor_hash'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
            'created_date' => (string) ($row['created_date'] ?? date('Y-m-d')),
            'channel' => $channel,
            'utm_source' => (string) ($row['utm_source'] ?? ''),
            'utm_medium' => (string) ($row['utm_medium'] ?? ''),
            'utm_campaign' => (string) ($row['utm_campaign'] ?? ''),
            'utm_term' => (string) ($row['utm_term'] ?? ''),
            'utm_content' => (string) ($row['utm_content'] ?? ''),
            'referrer_host' => (string) ($row['referrer_host'] ?? ''),
            'landing_path' => $this->trimValue((string) ($row['path'] ?? ''), 767),
            'landing_path_hash' => (string) ($row['path_hash'] ?? ''),
            'is_bot' => (int) ($row['is_bot'] ?? 0),
        ];

        try {
            $db = $this->wire('database');
            $columns = array_keys($data);
            $placeholders = array_map(function($c) { return ':' . $c; }, $columns);
            $sql = "INSERT IGNORE INTO `" . self::ATTRIBUTION_TABLE . "` (`"
                . implode('`,`', $columns) . "`) VALUES (" . implode(',', $placeholders) . ")";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_combine($placeholders, array_values($data)));
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Attribution insert failed: ' . $e->getMessage());
        }
    }

    /**
     * Classify a hit into a marketing channel, first-touch style.
     *
     * Precedence: explicit UTM medium/source, then referrer host, else Direct.
     * Deliberately conservative and dependency-free (no external lists).
     */
    public function classifyChannel($utmMedium, $utmSource, $referrerHost) {
        $medium = strtolower(trim((string) $utmMedium));
        $source = strtolower(trim((string) $utmSource));
        $host = strtolower(trim((string) $referrerHost));

        if($medium !== '') {
            if(in_array($medium, ['cpc', 'ppc', 'paid', 'paidsearch', 'paid-search', 'paid_search', 'cpm', 'cpv', 'banner', 'display', 'retargeting'], true)) return 'Paid';
            if(in_array($medium, ['email', 'e-mail', 'newsletter', 'mail'], true)) return 'Email';
            if(in_array($medium, ['social', 'social-network', 'social_network', 'social-media', 'social_media', 'sm', 'social network'], true)) return 'Social';
            if($medium === 'affiliate') return 'Affiliate';
            if($medium === 'organic') return 'Organic Search';
            if($medium === 'referral') return 'Referral';
            return 'Campaign';
        }

        if($host !== '') {
            $searchEngines = ['google.', 'bing.', 'duckduckgo.', 'yahoo.', 'yandex.', 'baidu.', 'ecosia.', 'brave.', 'startpage.', 'qwant.', 'ask.'];
            foreach($searchEngines as $needle) {
                if(strpos($host, $needle) !== false) return 'Organic Search';
            }
            $social = ['facebook.', 'fb.', 'instagram.', 'l.instagram.', 't.co', 'twitter.', 'x.com', 'linkedin.', 'lnkd.in', 'youtube.', 'youtu.be', 'reddit.', 'pinterest.', 'tiktok.', 'mastodon.', 'threads.', 'whatsapp.', 'telegram.', 't.me'];
            foreach($social as $needle) {
                if(strpos($host, $needle) !== false) return 'Social';
            }
            return 'Referral';
        }

        return 'Direct';
    }


    public function getGoals($includeInactive = false) {
        $sql = "SELECT * FROM `" . self::GOALS_TABLE . "`" . ($includeInactive ? '' : ' WHERE `active` = 1') . " ORDER BY `active` DESC, `title` ASC, `id` ASC";
        try {
            return $this->wire('database')->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Goal list failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getGoal($id) {
        $stmt = $this->wire('database')->prepare("SELECT * FROM `" . self::GOALS_TABLE . "` WHERE `id` = :id");
        $stmt->execute([':id' => (int) $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    public function saveGoal(array $data) {
        $id = (int) ($data['id'] ?? 0);
        $title = $this->cleanTextValue((string) ($data['title'] ?? ''), 191);
        if($title === '') $title = 'Untitled goal';
        $goalType = in_array((string) ($data['goal_type'] ?? 'event'), ['event', 'page'], true) ? (string) $data['goal_type'] : 'event';
        $base = in_array((string) ($data['conversion_base'] ?? 'sessions'), ['sessions', 'uniques'], true) ? (string) $data['conversion_base'] : 'sessions';
        $now = date('Y-m-d H:i:s');
        $row = [
            'title' => $title,
            'goal_type' => $goalType,
            'event_group' => $this->sanitizeEventGroup((string) ($data['event_group'] ?? '')),
            'event_name' => $this->sanitizeEventName((string) ($data['event_name'] ?? '')),
            'event_label_contains' => $this->cleanTextValue((string) ($data['event_label_contains'] ?? ''), 191),
            'event_target_contains' => $this->cleanTextValue((string) ($data['event_target_contains'] ?? ''), 191),
            'path_contains' => $this->cleanTextValue((string) ($data['path_contains'] ?? ''), 191),
            'conversion_base' => $base,
            'active' => !empty($data['active']) ? 1 : 0,
            'updated_at' => $now,
        ];

        $db = $this->wire('database');
        if($id > 0) {
            $sets = [];
            $params = [':id' => $id];
            foreach($row as $key => $value) {
                $sets[] = "`{$key}` = :{$key}";
                $params[':' . $key] = $value;
            }
            $stmt = $db->prepare("UPDATE `" . self::GOALS_TABLE . "` SET " . implode(', ', $sets) . " WHERE `id` = :id");
            $stmt->execute($params);
            return $id;
        }

        $row['created_at'] = $now;
        $columns = array_keys($row);
        $params = [];
        foreach($row as $key => $value) $params[':' . $key] = $value;
        $stmt = $db->prepare("INSERT INTO `" . self::GOALS_TABLE . "` (`" . implode('`,`', $columns) . "`) VALUES (:" . implode(',:', $columns) . ")");
        $stmt->execute($params);
        return (int) $db->lastInsertId();
    }

    public function deleteGoal($id) {
        $id = (int) $id;
        if($id < 1) return;
        $db = $this->wire('database');
        $stmt = $db->prepare("DELETE FROM `" . self::GOAL_DAILY_TABLE . "` WHERE `goal_id` = :id");
        $stmt->execute([':id' => $id]);
        $stmt = $db->prepare("DELETE FROM `" . self::GOALS_TABLE . "` WHERE `id` = :id");
        $stmt->execute([':id' => $id]);
    }

    public function getGoalStats($days = 30, array $filters = [], $includeInactive = true) {
        $range = $this->getDateRangeForDays($days);
        $base = $this->getSummary($range, $filters);
        $rows = [];
        foreach($this->getGoals($includeInactive) as $goal) {
            $stats = !empty($goal['active']) ? $this->calculateSingleGoalStats($goal, $range, $filters) : ['conversions' => 0, 'uniques' => 0, 'sessions' => 0];
            $baseMetric = (string) ($goal['conversion_base'] ?? 'sessions') === 'uniques' ? 'uniques' : 'sessions';
            $denominator = max(0, (int) ($base[$baseMetric] ?? 0));
            $numerator = max(0, (int) ($stats[$baseMetric] ?? 0));
            $rate = $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : 0.0;
            $goal['conversions'] = (int) ($stats['conversions'] ?? 0);
            $goal['uniques'] = (int) ($stats['uniques'] ?? 0);
            $goal['sessions'] = (int) ($stats['sessions'] ?? 0);
            $goal['conversion_rate'] = $rate;
            $goal['conversion_base_label'] = $baseMetric === 'uniques' ? 'unique visitors' : 'sessions';
            $goal['base_total'] = $denominator;
            $rows[] = $goal;
        }
        return $rows;
    }

    protected function calculateSingleGoalStats(array $goal, array $range, array $filters = []) {
        $type = (string) ($goal['goal_type'] ?? 'event');
        if($type === 'page') return $this->calculatePageGoalStats($goal, $range, $filters);
        return $this->calculateEventGoalStats($goal, $range, $filters);
    }

    protected function calculateEventGoalStats(array $goal, array $range, array $filters = []) {
        $where = $this->buildEventWhere($filters, $range);
        $params = $where['params'];
        $clauses = [$where['sql']];
        if((string) ($goal['event_group'] ?? '') !== '') {
            $clauses[] = 'event_group = :goal_event_group';
            $params[':goal_event_group'] = (string) $goal['event_group'];
        }
        if((string) ($goal['event_name'] ?? '') !== '') {
            $clauses[] = 'event_name = :goal_event_name';
            $params[':goal_event_name'] = (string) $goal['event_name'];
        }
        if((string) ($goal['event_label_contains'] ?? '') !== '') {
            $clauses[] = 'event_label LIKE :goal_event_label';
            $params[':goal_event_label'] = '%' . (string) $goal['event_label_contains'] . '%';
        }
        if((string) ($goal['event_target_contains'] ?? '') !== '') {
            $clauses[] = 'event_target LIKE :goal_event_target';
            $params[':goal_event_target'] = '%' . (string) $goal['event_target_contains'] . '%';
        }
        $sql = "SELECT COUNT(*) AS conversions, COUNT(DISTINCT visitor_hash) AS uniques, COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::EVENTS_TABLE . "` WHERE " . implode(' AND ', $clauses);
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['conversions' => 0, 'uniques' => 0, 'sessions' => 0];
    }

    protected function calculatePageGoalStats(array $goal, array $range, array $filters = []) {
        $where = $this->buildWhere($filters, $range, ['status_code <> 404']);
        $params = $where['params'];
        $clauses = [$where['sql']];
        if((string) ($goal['path_contains'] ?? '') !== '') {
            $clauses[] = 'path LIKE :goal_path';
            $params[':goal_path'] = '%' . (string) $goal['path_contains'] . '%';
        }
        $sql = "SELECT COUNT(*) AS conversions, COUNT(DISTINCT visitor_hash) AS uniques, COUNT(DISTINCT session_hash) AS sessions
                FROM `" . self::HITS_TABLE . "` WHERE " . implode(' AND ', $clauses);
        $stmt = $this->wire('database')->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['conversions' => 0, 'uniques' => 0, 'sessions' => 0];
    }

    public function getGoalDailySeries($days = 30, array $filters = []) {
        $range = $this->getDateRangeForDays($days);
        $indexed = [];
        if(!empty($this->highTrafficMode) && empty($filters)) {
            $stmt = $this->wire('database')->prepare("SELECT day, COALESCE(SUM(conversions),0) AS conversions, COALESCE(SUM(uniques),0) AS uniques, COALESCE(SUM(sessions),0) AS sessions FROM `" . self::GOAL_DAILY_TABLE . "` WHERE day >= :start_day AND day <= :end_day GROUP BY day ORDER BY day ASC");
            $stmt->execute([':start_day' => $range['start_date'], ':end_day' => $range['end_date']]);
            foreach(($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $row) $indexed[$row['day']] = $row;
        }

        $goals = $this->getGoals(false);
        $series = [];
        $cursor = strtotime($range['start_date']);
        $end = strtotime($range['end_date']);
        while($cursor <= $end) {
            $day = date('Y-m-d', $cursor);
            if(isset($indexed[$day])) {
                $conversions = (int) $indexed[$day]['conversions'];
                $uniques = (int) $indexed[$day]['uniques'];
                $sessions = (int) $indexed[$day]['sessions'];
            } else {
                $conversions = 0;
                $uniques = 0;
                $sessions = 0;
                $dayRange = $this->getDateRangeBetween($day, $day, 1);
                foreach($goals as $goal) {
                    $stats = $this->calculateSingleGoalStats($goal, $dayRange, $filters);
                    $conversions += (int) ($stats['conversions'] ?? 0);
                    $uniques += (int) ($stats['uniques'] ?? 0);
                    $sessions += (int) ($stats['sessions'] ?? 0);
                }
            }
            $series[] = [
                'day' => $day,
                'label' => $this->formatDisplayDate($cursor),
                'time_label' => 'All day',
                'views' => $conversions,
                'uniques' => $uniques,
                'sessions' => $sessions,
            ];
            $cursor = strtotime('+1 day', $cursor);
        }
        return $series;
    }

    public function getChartGeometry() {
        $width = 920;
        $height = 260;
        $padX = 40;
        $padY = 20;
        $plotWidth = $width - ($padX * 2);
        $plotHeight = $height - ($padY * 2) - 24;
        return [
            'width' => $width,
            'height' => $height,
            'padX' => $padX,
            'padY' => $padY,
            'plotWidth' => $plotWidth,
            'plotHeight' => $plotHeight,
        ];
    }

    public function getChartLatestSlots($rangeSpec, array $filters = []) {
        $today = date('Y-m-d');
        $slotDay = is_array($rangeSpec) ? (string) ($rangeSpec['end_date'] ?? $today) : $today;
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $slotDay)) $slotDay = $today;
        $oneDay = ['start_date' => $slotDay, 'end_date' => $slotDay];

        $pickLast = function(array $series) {
            $row = end($series);
            return is_array($row) ? $row : null;
        };
        $toSlot = function($row, $key) {
            if(!is_array($row)) return null;
            return [
                'key' => (string) $key,
                'label' => (string) ($row['label'] ?? ''),
                'time' => (string) ($row['time_label'] ?? ''),
                'views' => (int) ($row['views'] ?? 0),
                'uniques' => (int) ($row['uniques'] ?? 0),
                'sessions' => (int) ($row['sessions'] ?? 0),
            ];
        };

        $slots = [];

        $dailyRow = $pickLast($this->getDailySeries($oneDay, $filters));
        if($dailyRow) $slots['daily'] = $toSlot($dailyRow, (string) ($dailyRow['day'] ?? $slotDay));

        $hourly = $this->getHourlySeries($slotDay, $filters);
        $currentHour = ($slotDay === $today) ? (int) date('G') : 23;
        if(isset($hourly[$currentHour])) {
            $hourlySlot = $toSlot($hourly[$currentHour], (string) $currentHour);
            if($hourlySlot) $slots['hourly'] = $hourlySlot;
        }

        $eventsRow = $pickLast($this->getEventDailySeries($oneDay, $filters));
        if($eventsRow) $slots['events'] = $toSlot($eventsRow, (string) ($eventsRow['day'] ?? $slotDay));

        $goalsRow = $pickLast($this->getGoalDailySeries($oneDay, $filters));
        if($goalsRow) $slots['goals'] = $toSlot($goalsRow, (string) ($goalsRow['day'] ?? $slotDay));

        return $slots;
    }

    public function getLiveChartHtml($rangeSpec, array $filters = [], array $chartIds = []) {
        $allowed = ['daily', 'hourly', 'events', 'goals'];
        $chartIds = $chartIds ? array_values(array_intersect($allowed, $chartIds)) : $allowed;
        $html = [];
        foreach($chartIds as $id) {
            if($id === 'daily') {
                $html[$id] = $this->renderLineChart($this->getDailySeries($rangeSpec, $filters), 'views', 'Traffic trend by day', [], 'daily');
            } elseif($id === 'hourly') {
                $endDay = is_array($rangeSpec) ? (string) ($rangeSpec['end_date'] ?? date('Y-m-d')) : date('Y-m-d');
                $html[$id] = $this->renderLineChart($this->getHourlySeries($endDay, $filters), 'views', 'Traffic by hour for selected day', [], 'hourly');
            } elseif($id === 'events') {
                $html[$id] = $this->renderLineChart($this->getEventDailySeries($rangeSpec, $filters), 'views', 'Tracked actions by day', ['views' => 'Events', 'uniques' => 'Uniques', 'sessions' => 'Sessions'], 'events');
            } elseif($id === 'goals') {
                $html[$id] = $this->renderLineChart($this->getGoalDailySeries($rangeSpec, $filters), 'views', 'Goal conversions by day', ['views' => 'Conversions', 'uniques' => 'Uniques', 'sessions' => 'Sessions'], 'goals');
            }
        }
        return $html;
    }

    /**
     * Read-only diagnostics used by the module settings health panel.
     * No visitor data is created by this method.
     */
    public function getTrackingDiagnostics() {
        $rawMode = (string) ($this->trackingMode ?? '');
        $rawStorage = (string) ($this->trackingStorageMode ?? '');
        $mode = $this->getTrackingMode();
        $storage = $this->getTrackingStorageMode();
        $health = $this->getHealthSnapshot();
        $issues = [];
        $warnings = [];

        if(empty($this->trackingEnabled)) $issues[] = 'Tracking is disabled.';
        if(!in_array($rawMode, ['js_first', 'js_only', 'both', 'server_only'], true)) $issues[] = 'The persisted tracking mode is missing or invalid; save the module settings again.';
        if(!in_array($rawStorage, ['cookie', 'cookieless'], true)) $issues[] = 'The persisted storage mode is missing or invalid; save the module settings again.';
        if((int) ($health['hits_count'] ?? -1) < 0) $issues[] = 'The raw hits table cannot be read.';
        if((int) ($health['sessions_count'] ?? -1) < 0) $issues[] = 'The realtime sessions table cannot be read.';
        if(!empty($this->requireConsent) && trim((string) $this->consentCookieName) === '') $issues[] = 'Consent is required but no consent cookie name is configured.';
        if(($mode === 'js_first' || $mode === 'js_only') && !is_file(__DIR__ . '/assets/tracker.js')) $issues[] = 'Browser tracker asset is missing.';
        if((int) ($health['hits_count'] ?? 0) === 0 && !empty($this->trackingEnabled)) $warnings[] = 'No pageviews have been stored yet.';
        if($this->isRoleExcluded()) $warnings[] = 'Your current logged-in role is excluded from tracking; this is normal for administrators.';
        if(!empty($this->respectDnt)) $warnings[] = 'Do Not Track requests are ignored by analytics as configured.';
        if(!empty($this->requireConsent)) $warnings[] = 'Tracking waits for the configured consent cookie.';

        return [
            'ok' => empty($issues),
            'mode' => $mode,
            'storage' => $storage,
            'endpoint' => $this->getTrackEndpointUrl(),
            'tracker_asset' => is_file(__DIR__ . '/assets/tracker.js'),
            'issues' => $issues,
            'warnings' => $warnings,
            'health' => $health,
        ];
    }

    /**
     * Verify that the analytics schema is writable without polluting reports.
     * A realistic row is inserted inside a transaction and always rolled back.
     */
    /**
     * Render a one-time, self-contained diagnostic result dialog for module config.
     * Output is fully escaped and CSS/JS are scoped to the NativeAnalytics modal.
     */
    protected static function renderTrackingDiagnosticResultModal(array $result) {
        $wire = wire();
        $sanitizer = $wire->sanitizer;
        $level = (string) ($result['level'] ?? (!empty($result['ok']) ? 'success' : 'error'));
        if(!in_array($level, ['success', 'warning', 'error'], true)) $level = 'error';
        $ok = !empty($result['ok']);
        $title = $level === 'warning' ? 'Tracking test passed with a warning' : ($ok ? 'Tracking test passed' : 'Tracking test failed');
        $icon = $level === 'warning' ? 'fa-exclamation-triangle' : ($ok ? 'fa-check-circle' : 'fa-times-circle');
        $message = trim((string) ($result['message'] ?? ''));
        $checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];

        $checksHtml = '';
        foreach($checks as $check) {
            if(!is_array($check)) continue;
            $status = (string) ($check['status'] ?? 'info');
            if(!in_array($status, ['success', 'warning', 'error', 'info'], true)) $status = 'info';
            $label = $sanitizer->entities((string) ($check['label'] ?? 'Check'));
            $detail = $sanitizer->entities((string) ($check['detail'] ?? ''));
            $checkIcon = $status === 'success' ? 'fa-check' : ($status === 'warning' ? 'fa-exclamation-triangle' : ($status === 'error' ? 'fa-times' : 'fa-info-circle'));
            $checksHtml .= '<div class="pwna-diag-check pwna-diag-check--' . $status . '"><span class="pwna-diag-check-icon"><i class="fa ' . $checkIcon . '"></i></span><div><strong>' . $label . '</strong>' . ($detail !== '' ? '<div>' . $detail . '</div>' : '') . '</div></div>';
        }

        // Native HTML <dialog> deliberately avoids dependencies on AdminThemeUikit,
        // jQuery UI or theme-specific modal APIs. A visible fallback card remains in
        // the DOM if showModal() is unavailable or blocked, so a result can never be
        // lost again.
        $html = '<div class="pwna-diag-result-fallback" data-pwna-diag-fallback data-level="' . $level . '">'
            . '<div class="pwna-diag-fallback-title"><i class="fa ' . $icon . '"></i> <strong>' . $sanitizer->entities($title) . '</strong></div>'
            . '<p>Diagnostic completed. If the result dialog did not open automatically, <button type="button" class="pwna-diag-text-button" data-pwna-diag-open>open the result</button>.</p>'
            . '</div>'
            . '<dialog id="pwna-diagnostic-dialog" class="pwna-diag-dialog" data-level="' . $level . '" aria-labelledby="pwna-diag-title">'
            . '<div class="pwna-diag-dialog-inner">'
            . '<button type="button" class="pwna-diag-close" data-pwna-diag-close aria-label="Close"><i class="fa fa-times"></i></button>'
            . '<div class="pwna-diag-heading"><span class="pwna-diag-heading-icon"><i class="fa ' . $icon . '"></i></span><div><div class="pwna-diag-kicker">NativeAnalytics diagnostics</div><h2 id="pwna-diag-title">' . $sanitizer->entities($title) . '</h2></div></div>'
            . ($checksHtml !== '' ? '<div class="pwna-diag-checks">' . $checksHtml . '</div>' : '')
            . ($message !== '' ? '<p class="pwna-diag-summary">' . $sanitizer->entities($message) . '</p>' : '')
            . '<div class="pwna-diag-actions"><button type="button" class="ui-button ui-priority-primary pwna-diag-button" data-pwna-diag-close>Close</button></div>'
            . '</div></dialog>';

        $html .= '<style>'
            . '.pwna-diag-result-fallback{box-sizing:border-box;margin:16px 0 0;padding:12px 14px;border:1px solid rgba(127,127,127,.28);border-left:4px solid #2e7d32;border-radius:4px;background:rgba(127,127,127,.06)}.pwna-diag-result-fallback[data-level=warning]{border-left-color:#b26a00}.pwna-diag-result-fallback[data-level=error]{border-left-color:#b42318}.pwna-diag-result-fallback p{margin:.35em 0 0}.pwna-diag-text-button{font:inherit;color:inherit;text-decoration:underline;border:0;background:none;padding:0;cursor:pointer}'
            . '.pwna-diag-dialog{box-sizing:border-box;width:min(680px,calc(100vw - 32px));max-width:680px;max-height:calc(100vh - 32px);margin:auto;padding:0;border:0;border-radius:10px;background:#fff;color:#222;box-shadow:0 20px 70px rgba(0,0,0,.4);overflow:auto}.pwna-diag-dialog::backdrop{background:rgba(0,0,0,.58)}.pwna-diag-dialog-inner{position:relative;box-sizing:border-box;padding:28px}.pwna-diag-close{position:absolute;right:12px;top:12px;border:0;background:transparent;color:inherit;opacity:.65;font-size:18px;cursor:pointer;padding:8px}.pwna-diag-close:hover{opacity:1}.pwna-diag-heading{display:flex;gap:14px;align-items:center;padding-right:34px;margin-bottom:22px}.pwna-diag-heading-icon{font-size:32px}.pwna-diag-dialog[data-level=success] .pwna-diag-heading-icon,.pwna-diag-check--success .pwna-diag-check-icon{color:#2e7d32}.pwna-diag-dialog[data-level=warning] .pwna-diag-heading-icon,.pwna-diag-check--warning .pwna-diag-check-icon{color:#b26a00}.pwna-diag-dialog[data-level=error] .pwna-diag-heading-icon,.pwna-diag-check--error .pwna-diag-check-icon{color:#b42318}.pwna-diag-kicker{text-transform:uppercase;letter-spacing:.08em;font-size:11px;font-weight:700;opacity:.6}.pwna-diag-heading h2{margin:2px 0 0;font-size:22px;color:inherit}.pwna-diag-checks{border:1px solid rgba(127,127,127,.25);border-radius:8px;overflow:hidden}.pwna-diag-check{display:flex;gap:12px;padding:13px 15px;border-bottom:1px solid rgba(127,127,127,.18)}.pwna-diag-check:last-child{border-bottom:0}.pwna-diag-check-icon{width:20px;text-align:center;flex:0 0 20px}.pwna-diag-check div div{font-size:13px;opacity:.75;margin-top:2px}.pwna-diag-summary{margin:18px 0 0;line-height:1.55}.pwna-diag-actions{display:flex;justify-content:flex-end;margin-top:22px}.pwna-diag-button{cursor:pointer}@media(prefers-color-scheme:dark){.pwna-diag-dialog{background:#222;color:#f5f5f5}}@media(max-width:600px){.pwna-diag-dialog{width:calc(100vw - 20px);max-height:calc(100vh - 20px)}.pwna-diag-dialog-inner{padding:22px 18px}.pwna-diag-heading h2{font-size:19px}}'
            . '</style>';
        $html .= '<script>(function(){function init(){var d=document.getElementById("pwna-diagnostic-dialog"),f=document.querySelector("[data-pwna-diag-fallback]");if(!d)return;var previous=document.activeElement;function open(){if(typeof d.showModal==="function"){try{if(!d.open)d.showModal();if(f)f.style.display="none";}catch(e){if(f)f.style.display="block";}}else if(f){f.style.display="block";}}function close(){if(d.open&&typeof d.close==="function")d.close();if(previous&&previous.focus)try{previous.focus();}catch(e){}}document.querySelectorAll("[data-pwna-diag-open]").forEach(function(el){el.addEventListener("click",open);});d.querySelectorAll("[data-pwna-diag-close]").forEach(function(el){el.addEventListener("click",close);});d.addEventListener("click",function(e){if(e.target===d)close();});d.addEventListener("cancel",function(e){e.preventDefault();close();});open();}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",init,{once:true});else init();})();</script>';
        return $html;
    }

    public function runTrackingDiagnosticTest() {
        $db = $this->wire('database');
        $started = false;
        try {
            if($db->inTransaction()) {
                return ['ok' => false, 'level' => 'error', 'message' => 'A database transaction is already active. Please reload the module settings and run the test again.', 'checks' => [['label' => 'Database', 'status' => 'error', 'detail' => 'Another database transaction is already active.']]];
            }

            // 1) Verify the same endpoint path normalisation used by real requests.
            // This catches subdirectory installations without making an HTTP request.
            $endpointPath = (string) parse_url($this->getTrackEndpointUrl(), PHP_URL_PATH);
            $internalRouteOk = $this->getSpecialEndpointFromUri($endpointPath) === 'track';
            if(!$internalRouteOk) {
                return ['ok' => false, 'level' => 'error', 'message' => 'Internal endpoint routing test failed for ' . $endpointPath . '. The tracking endpoint would not be recognised by NativeAnalytics.', 'checks' => [['label' => 'Internal endpoint routing', 'status' => 'error', 'detail' => 'NativeAnalytics did not recognise ' . $endpointPath . ' as its tracking endpoint.']]];
            }

            // 2) Verify schema/write capability with a transaction that is always
            // rolled back so diagnostics can never alter analytics reports.
            $db->beginTransaction();
            $started = true;
            $now = date('Y-m-d H:i:s');
            $path = '/__pwna_diagnostic__';
            $row = [
                'created_at' => $now, 'created_date' => date('Y-m-d'), 'created_hour' => (int) date('G'),
                'page_id' => 0, 'page_title' => 'NativeAnalytics diagnostic', 'template' => 'diagnostic',
                'url' => $this->wire('config')->urls->root . ltrim($path, '/'), 'path' => $path, 'path_hash' => md5($path),
                'referrer_host' => '', 'referrer_url' => '', 'search_term' => '',
                'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => '',
                'device_type' => 'diagnostic', 'browser' => 'diagnostic', 'os' => 'diagnostic', 'user_agent' => 'NativeAnalytics diagnostic',
                'visitor_hash' => hash('sha256', 'pwna-diagnostic-visitor'), 'session_hash' => hash('sha256', 'pwna-diagnostic-session'),
                'ip_hash' => hash('sha256', 'pwna-diagnostic-ip'), 'is_bot' => 0, 'status_code' => 200,
            ];
            $this->insert(self::HITS_TABLE, $row);
            $stmt = $db->prepare("SELECT COUNT(*) FROM `" . self::HITS_TABLE . "` WHERE path=:path");
            $stmt->execute([':path' => $path]);
            $count = (int) $stmt->fetchColumn();
            if($started && $db->inTransaction()) $db->rollBack();
            $started = false;
            if($count < 1) return ['ok' => false, 'level' => 'error', 'message' => 'Database accepted no diagnostic row.', 'checks' => [['label' => 'Internal endpoint routing', 'status' => 'success', 'detail' => 'Tracking route recognised correctly.'], ['label' => 'Raw hits database write', 'status' => 'error', 'detail' => 'The diagnostic row could not be verified.']]];

            // 3) Optional public loopback check. This is deliberately advisory:
            // many production hosts/WAFs block a server from requesting its own
            // public hostname. That does not mean browser tracking is broken.
            $endpointCode = 0;
            $endpointUrl = $this->getTrackEndpointHttpUrl();
            $endpointError = '';
            if($endpointUrl !== '') {
                try {
                    $http = $this->wire(new WireHttp());
                    $http->setTimeout(8);
                    $http->get($endpointUrl . (strpos($endpointUrl, '?') === false ? '?' : '&') . 'pwna_diagnostic=1');
                    $endpointCode = (int) $http->getHttpCode();
                } catch(\Throwable $e) {
                    $endpointError = $e->getMessage();
                    $this->wire('log')->save('native-analytics', 'Optional public endpoint loopback diagnostic failed: ' . $endpointError);
                }
            } else {
                $endpointError = 'Could not construct an absolute endpoint URL for the server-side loopback check.';
            }

            if(in_array($endpointCode, [204, 422], true)) {
                return ['ok' => true, 'level' => 'success', 'message' => 'All core tracking diagnostics passed. The database test row was rolled back and is not included in reports.', 'checks' => [['label' => 'Internal endpoint routing', 'status' => 'success', 'detail' => 'Tracking route recognised correctly.'], ['label' => 'Public tracking endpoint', 'status' => 'success', 'detail' => 'Endpoint responded with HTTP ' . $endpointCode . '.'], ['label' => 'Raw hits database write', 'status' => 'success', 'detail' => 'Test write succeeded and was safely rolled back.']]];
            }

            $loopback = $endpointCode > 0 ? 'HTTP ' . $endpointCode : 'HTTP 0';
            return [
                'ok' => true,
                'level' => 'warning',
                'message' => 'Core tracking checks passed. The optional server-to-self public endpoint check could not be confirmed; this does not by itself mean visitor tracking is broken.',
                'checks' => [
                    ['label' => 'Internal endpoint routing', 'status' => 'success', 'detail' => 'Tracking route recognised correctly.'],
                    ['label' => 'Public tracking endpoint', 'status' => 'warning', 'detail' => 'Optional loopback check returned ' . $loopback . '. Hosting, DNS, SSL, reverse proxy, Cloudflare/WAF, or firewall rules can block server-to-self requests.'],
                    ['label' => 'Raw hits database write', 'status' => 'success', 'detail' => 'Test write succeeded and was safely rolled back.'],
                ]
            ];
        } catch(\Throwable $e) {
            if($started && $db->inTransaction()) $db->rollBack();
            $this->wire('log')->save('native-analytics', 'Tracking diagnostic failed: ' . $e->getMessage());
            return ['ok' => false, 'level' => 'error', 'message' => $e->getMessage(), 'checks' => [['label' => 'Diagnostic execution', 'status' => 'error', 'detail' => 'The test stopped because an exception occurred.']]];
        }
    }

    public function getHealthSnapshot() {
        $db = $this->wire('database');
        $snapshot = [
            'module_dir' => $this->getModuleDirName(),
            'admin_css_url' => $this->getAssetUrl('assets/admin.css') . '?v=' . rawurlencode($this->getAssetVersion('assets/admin.css')),
            'tracker_js_url' => $this->getAssetUrl('assets/tracker.js') . '?v=' . rawurlencode(self::VERSION),
            'track_endpoint_url' => $this->getTrackEndpointUrl(),
            'realtime_endpoint_url' => $this->getRealtimeEndpointUrl(),
            'hits_count' => 0,
            'sessions_count' => 0,
            'daily_count' => 0,
            'events_count' => 0,
            'event_daily_count' => 0,
            'goals_count' => 0,
            'goal_daily_count' => 0,
            'last_hit_at' => '',
            'last_session_at' => '',
            'last_event_at' => '',
        ];
        foreach([
            'hits_count' => self::HITS_TABLE,
            'sessions_count' => self::SESSIONS_TABLE,
            'daily_count' => self::DAILY_TABLE,
            'events_count' => self::EVENTS_TABLE,
            'event_daily_count' => self::EVENT_DAILY_TABLE,
            'goals_count' => self::GOALS_TABLE,
            'goal_daily_count' => self::GOAL_DAILY_TABLE,
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
        if(!$this->shouldRenderPageEditAnalyticsBox()) return;
        if(!$this->wire('user')->hasPermission('nativeanalytics-view')) return;
        $form = $event->return;
        if(!is_object($form) || !method_exists($form, 'add')) return;
        try {
            $editedPage = $event->process->getPage();
        } catch(\Throwable $e) {
            return;
        }
        if(!$editedPage || !$editedPage->id || ($editedPage->template && $editedPage->template->name === 'admin')) return;

        // Only show the widget on pages that are actually viewable and have a
        // real front-end URL — i.e. pages that can accumulate stats. Skip
        // non-viewable pages (no template file, hidden/unpublished, or pages
        // that merely back a Page Reference field), where stats are meaningless.
        if(!$this->pageHasViewableStats($editedPage)) return;

        $summary7 = $this->getPageSummary($editedPage->id, 7);
        $summary30 = $this->getPageSummary($editedPage->id, 30);
        $current = $this->getCurrentVisitorsSummary((int) $this->realtimeWindowMinutes, ['page_id' => (int) $editedPage->id]);

        $this->wire('config')->styles->add($this->getAssetUrl('assets/admin.css') . '?v=' . rawurlencode($this->getAssetVersion('assets/admin.css')));

        $field = $this->wire('modules')->get('InputfieldMarkup');
        $field->name = 'nativeAnalyticsPageSummary';
        $field->label = 'Analytics';
        $field->icon = 'line-chart';
        $field->wrapClass = trim((string) $field->wrapClass . ' pwna-page-edit-analytics-field');
        $field->collapsed = Inputfield::collapsedNo;
        $field->value = $this->renderMiniStatsBox($summary7, $summary30, $current, $editedPage);
        $form->add($field);
    }

    /**
     * ProcessWire also uses ProcessPageEdit for field/file/image scoped dialogs,
     * for example image edit modals. Those forms are intentionally limited to one
     * field or file, so the full-page analytics widget must not be injected there.
     */
    protected function shouldRenderPageEditAnalyticsBox() {
        $input = $this->wire('input');
        foreach(['field', 'fields', 'field_id', 'file', 'filename', 'InputfieldFileAjax', 'InputfieldImageAjax'] as $key) {
            $value = $input->get($key);
            if($value !== null && $value !== '') return false;
        }
        return true;
    }

    /**
     * Whether a page can meaningfully accumulate front-end stats: it must have
     * a template with a file, be viewable, and resolve to a real http URL.
     * Pages that only back a Page Reference field (no template file) return
     * false, so the analytics widget is hidden for them.
     */
    protected function pageHasViewableStats(Page $page) {
        try {
            $tpl = $page->template;
            if(!$tpl) return false;
            // No template file => not a front-end-rendered page.
            if(!$tpl->filenameExists()) return false;
            if(!$page->viewable()) return false;
            $url = (string) $page->httpUrl();
            if($url === '') return false;
        } catch(\Throwable $e) {
            return false;
        }
        return true;
    }

    public function renderMiniStatsBox(array $summary7, array $summary30, array $current, Page $page) {
        $url = $this->wire('config')->urls->admin . 'native-analytics/?range=30d&page_id=' . (int) $page->id;
        $url = $this->wire('sanitizer')->entities($url);
        $minutes = max(1, (int) $this->realtimeWindowMinutes);
        $currentVisitors = (int) ($current['current_uniques'] ?? $current['current_visitors'] ?? 0);

        $html = '<div class="pwna-mini">';
        $html .= '<div class="pwna-mini-grid">';
        $html .= $this->renderMiniStatCard('Last 7 days', (int) $summary7['views'], 'views', (int) $summary7['uniques'] . ' uniques');
        $html .= $this->renderMiniStatCard('Last 30 days', (int) $summary30['views'], 'views', (int) $summary30['uniques'] . ' uniques');
        $html .= $this->renderMiniStatCard('Current visitors', $currentVisitors, 'active', 'window: ' . $minutes . ' min');
        $html .= $this->renderMiniStatCard('Sessions', (int) $summary30['sessions'], 'in 30 days', 'page ID ' . (int) $page->id);
        $html .= '</div>';
        $html .= '<div class="pwna-mini-footer"><a class="ui-button ui-priority-secondary pwna-mini-button" href="' . $url . '"><i class="fa fa-line-chart"></i> Open full analytics</a></div>';
        $html .= '</div>';
        return $html;
    }

    protected function renderMiniStatCard($label, $value, $suffix, $meta) {
        return '<div class="pwna-mini-card">'
            . '<div class="pwna-mini-label">' . $this->wire('sanitizer')->entities((string) $label) . '</div>'
            . '<div class="pwna-mini-number"><strong>' . (int) $value . '</strong><span>' . $this->wire('sanitizer')->entities((string) $suffix) . '</span></div>'
            . '<div class="pwna-mini-meta">' . $this->wire('sanitizer')->entities((string) $meta) . '</div>'
            . '</div>';
    }

    public function renderComparisonChart(array $primarySeries, array $comparisonSeries, $metric = 'views', $chartLabel = 'Comparison chart', $primaryLabel = 'Selected period', $secondaryLabel = 'Comparison period', array $metricLabels = []) {
        $metric = in_array($metric, ['views', 'uniques', 'sessions'], true) ? $metric : 'views';
        $metricLabels = array_merge(['views' => 'Views', 'uniques' => 'Uniques', 'sessions' => 'Sessions'], $metricLabels);
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
            $circles[] = '<circle class="pwna-point" cx="' . $x . '" cy="' . $yPrimary . '" r="4" data-label="' . $sanitizer->entities($primaryLabelText) . '" data-time="' . $sanitizer->entities($primaryTime) . '" data-views="' . (int) ($primary['views'] ?? 0) . '" data-uniques="' . (int) ($primary['uniques'] ?? 0) . '" data-sessions="' . (int) ($primary['sessions'] ?? 0) . '" data-compare-label="' . $sanitizer->entities($secondaryLabel . ': ' . $compareLabelText) . '" data-compare-views="' . (int) ($compare['views'] ?? 0) . '" data-compare-uniques="' . (int) ($compare['uniques'] ?? 0) . '" data-compare-sessions="' . (int) ($compare['sessions'] ?? 0) . '"></circle>';
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
        $html .= '<text x="' . ($padX + $plotWidth) . '" y="' . ($height - 6) . '" class="pwna-label" text-anchor="end">' . $sanitizer->entities($lastLabel) . '</text>';
        $html .= '</svg>';
        $html .= '<div class="pwna-chart-tooltip" hidden><div class="pwna-chart-tooltip-day"></div><div class="pwna-chart-tooltip-time" hidden></div><div class="pwna-chart-tooltip-grid"><span>' . $sanitizer->entities($metricLabels['views']) . '</span><strong data-pwna-tip="views">0</strong><span>' . $sanitizer->entities($metricLabels['uniques']) . '</span><strong data-pwna-tip="uniques">0</strong><span>' . $sanitizer->entities($metricLabels['sessions']) . '</span><strong data-pwna-tip="sessions">0</strong></div><div class="pwna-chart-tooltip-compare" hidden><div class="pwna-chart-tooltip-day" data-pwna-tip="compare-day"></div><div class="pwna-chart-tooltip-grid"><span>' . $sanitizer->entities($metricLabels['views']) . '</span><strong data-pwna-tip="compare-views">0</strong><span>' . $sanitizer->entities($metricLabels['uniques']) . '</span><strong data-pwna-tip="compare-uniques">0</strong><span>' . $sanitizer->entities($metricLabels['sessions']) . '</span><strong data-pwna-tip="compare-sessions">0</strong></div></div></div>';
        $html .= '</div>';
        return $html;
    }

    public function renderLineChart(array $series, $metric = 'views', $chartLabel = 'Analytics chart', array $metricLabels = [], $liveId = '') {
        $metric = in_array($metric, ['views', 'uniques', 'sessions'], true) ? $metric : 'views';
        $metricLabels = array_merge(['views' => 'Views', 'uniques' => 'Uniques', 'sessions' => 'Sessions'], $metricLabels);
        if(!$series) return '<p>No data yet.</p>';

        $geometry = $this->getChartGeometry();
        $width = $geometry['width'];
        $height = $geometry['height'];
        $padX = $geometry['padX'];
        $padY = $geometry['padY'];
        $plotWidth = $geometry['plotWidth'];
        $plotHeight = $geometry['plotHeight'];
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
            $key = isset($row['hour']) ? (string) $row['hour'] : (string) ($row['day'] ?? $index);
            $circles[] = '<circle class="pwna-point" cx="' . $x . '" cy="' . $y . '" r="4" data-key="' . $sanitizer->entities($key) . '" data-label="' . $sanitizer->entities($label) . '" data-time="' . $sanitizer->entities($timeLabel) . '" data-views="' . (int) ($row['views'] ?? 0) . '" data-uniques="' . (int) ($row['uniques'] ?? 0) . '" data-sessions="' . (int) ($row['sessions'] ?? 0) . '"></circle>';
        }

        $first = reset($series);
        $last = end($series);
        $firstLabel = isset($first['hour']) ? '00:00' : $this->formatDisplayDate($first['day']);
        $lastLabel = isset($last['hour']) ? '23:00' : $this->formatDisplayDate($last['day']);
        $liveAttr = $liveId !== '' ? ' data-pwna-chart-live="' . $sanitizer->entities($liveId) . '"' : '';

        $html  = '<div class="pwna-chart-wrap"' . $liveAttr . '>';
        $html .= '<svg class="pwna-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . $sanitizer->entities($chartLabel) . '">';
        $html .= '<line x1="' . $padX . '" y1="' . ($padY + $plotHeight) . '" x2="' . ($padX + $plotWidth) . '" y2="' . ($padY + $plotHeight) . '" class="pwna-axis" />';
        $html .= '<line x1="' . $padX . '" y1="' . $padY . '" x2="' . $padX . '" y2="' . ($padY + $plotHeight) . '" class="pwna-axis" />';
        for($i = 0; $i <= 4; $i++) {
            $v = (int) round(($max / 4) * $i);
            $y = $padY + $plotHeight - (($v / $max) * $plotHeight);
            $html .= '<line x1="' . $padX . '" y1="' . round($y, 2) . '" x2="' . ($padX + $plotWidth) . '" y2="' . round($y, 2) . '" class="pwna-grid" data-pwna-grid="' . $i . '" />';
            $html .= '<text x="8" y="' . (round($y, 2) + 4) . '" class="pwna-label" data-pwna-grid-label="' . $i . '">' . $v . '</text>';
        }
        $html .= '<polyline fill="none" class="pwna-line" points="' . implode(' ', $points) . '" />';
        $html .= implode('', $circles);
        $html .= '<text x="' . $padX . '" y="' . ($height - 6) . '" class="pwna-label">' . $sanitizer->entities($firstLabel) . '</text>';
        $html .= '<text x="' . ($padX + $plotWidth) . '" y="' . ($height - 6) . '" class="pwna-label" text-anchor="end">' . $sanitizer->entities($lastLabel) . '</text>';
        $html .= '</svg>';
        $html .= '<div class="pwna-chart-tooltip" hidden><div class="pwna-chart-tooltip-day"></div><div class="pwna-chart-tooltip-time" hidden></div><div class="pwna-chart-tooltip-grid"><span>' . $sanitizer->entities($metricLabels['views']) . '</span><strong data-pwna-tip="views">0</strong><span>' . $sanitizer->entities($metricLabels['uniques']) . '</span><strong data-pwna-tip="uniques">0</strong><span>' . $sanitizer->entities($metricLabels['sessions']) . '</span><strong data-pwna-tip="sessions">0</strong></div><div class="pwna-chart-tooltip-compare" hidden><div class="pwna-chart-tooltip-day" data-pwna-tip="compare-day"></div><div class="pwna-chart-tooltip-grid"><span>' . $sanitizer->entities($metricLabels['views']) . '</span><strong data-pwna-tip="compare-views">0</strong><span>' . $sanitizer->entities($metricLabels['uniques']) . '</span><strong data-pwna-tip="compare-uniques">0</strong><span>' . $sanitizer->entities($metricLabels['sessions']) . '</span><strong data-pwna-tip="compare-sessions">0</strong></div></div></div>';
        $html .= '</div>';
        return $html;
    }


    protected function maybeHandleMonthlyReportToolRequest() {
        if(!$this->wire('config')->admin) return;

        $input = $this->wire('input');
        $action = (string) $input->get('pwna_monthly_report_action');
        if($action !== 'send_test') return;

        $session = $this->wire('session');
        $user = $this->wire('user');
        $redirectUrl = $this->getModuleConfigUrl();

        if(!$user->isSuperuser() && !$user->hasPermission('nativeanalytics-manage')) {
            $session->error('NativeAnalytics: You do not have permission to send test reports.');
            $session->redirect($redirectUrl);
        }

        $tokenName = $session->CSRF->getTokenName();
        $expectedTokenValue = (string) $session->CSRF->getTokenValue();
        $requestTokenValue = (string) $input->get($tokenName);
        if($requestTokenValue === '' || !hash_equals($expectedTokenValue, $requestTokenValue)) {
            $session->error('NativeAnalytics: Security token expired. Please try again.');
            $session->redirect($redirectUrl);
        }

        $result = $this->sendMonthlyReportTest();
        if(!empty($result['success'])) {
            $session->message($result['message']);
        } else {
            $session->error($result['message']);
        }
        $session->redirect($redirectUrl);
    }

    protected function maybeHandleMaintenanceToolRequest() {
        if(!$this->wire('config')->admin) return;

        $input = $this->wire('input');
        $action = (string) $input->get('pwna_maintenance_action');
        if(!in_array($action, ['purge_now', 'optimize_now', 'tracking_test'], true)) return;

        $session = $this->wire('session');
        $user = $this->wire('user');
        $redirectUrl = $this->getModuleConfigUrl();

        if(!$user->isSuperuser() && !$user->hasPermission('nativeanalytics-manage')) {
            $session->error('NativeAnalytics: You do not have permission to run maintenance.');
            $session->redirect($redirectUrl);
        }

        $tokenName = $session->CSRF->getTokenName();
        $expectedTokenValue = (string) $session->CSRF->getTokenValue();
        $requestTokenValue = (string) $input->get($tokenName);
        if($requestTokenValue === '' || !hash_equals($expectedTokenValue, $requestTokenValue)) {
            $session->error('NativeAnalytics: Security token expired. Please try again.');
            $session->redirect($redirectUrl);
        }

        try {
            if($action === 'tracking_test') {
                $result = $this->runTrackingDiagnosticTest();
                // Store the complete result for a one-time modal on the settings page.
                // A normal ProcessWire notice is intentionally not used here: the
                // diagnostic contains several independent checks and is much easier
                // to understand as a structured result. getModuleConfigInputfields()
                // consumes and removes this value after the redirect.
                $session->setFor('NativeAnalytics', 'trackingDiagnosticResult', $result);
            } elseif($action === 'purge_now') {
                $deleted = $this->runManualPurge();
                $msg = 'NativeAnalytics: Cleanup complete. Removed ' . number_format($deleted) . ' old raw rows.';
                if(!empty($this->optimizeAfterPurge) && $deleted > 0) {
                    $this->optimizeTables();
                    $msg .= ' Tables rebuilt to reclaim disk space.';
                }
                $session->message($msg);
            } else {
                $this->optimizeTables();
                $session->message('NativeAnalytics: Tables rebuilt. Freed space returned to the filesystem.');
            }
        } catch(\Throwable $e) {
            $session->error('NativeAnalytics: Maintenance failed: ' . $e->getMessage());
        }
        $session->redirect($redirectUrl);
    }

    protected function getModuleConfigUrl() {
        return $this->wire('config')->urls->admin . 'module/edit?name=NativeAnalytics';
    }

    public function sendMonthlyReportTest() {
        $recipients = $this->getMonthlyReportRecipients();
        if(!$recipients) {
            return [
                'success' => false,
                'message' => 'NativeAnalytics: Test report was not sent because no valid monthly report recipient email address is configured.',
            ];
        }

        $range = $this->getMonthlyReportPreviewRange();
        try {
            $sent = $this->sendMonthlyReportEmail($range, $recipients, true);
            if($sent) {
                $this->wire('log')->save('native-analytics', 'Monthly test report sent to ' . implode(', ', $recipients) . '.');
                return [
                    'success' => true,
                    'message' => 'NativeAnalytics: Test monthly report sent to ' . implode(', ', $recipients) . '.',
                ];
            }
            $this->wire('log')->save('native-analytics', 'Monthly test report could not be sent.');
            return [
                'success' => false,
                'message' => 'NativeAnalytics: Test report could not be sent. Please check your WireMail/SMTP configuration.',
            ];
        } catch(\Throwable $e) {
            return [
                'success' => false,
                'message' => 'NativeAnalytics: Test report failed: ' . $e->getMessage(),
            ];
        }
    }

    public function maybeSendMonthlyReport($force = false) {
        if(!$force && empty($this->monthlyReportsEnabled)) return false;

        $recipients = $this->getMonthlyReportRecipients();
        if(!$recipients) {
            if(!empty($this->monthlyReportsEnabled)) {
                $this->wire('log')->save('native-analytics', 'Monthly report skipped: no valid recipient email address configured.');
            }
            return false;
        }

        $sendDay = max(1, min(28, (int) ($this->monthlyReportSendDay ?: 1)));
        if(!$force && (int) date('j') < $sendDay) return false;

        $range = $this->getPreviousMonthReportRange();
        $period = (string) $range['period'];
        if(!$force && (string) $this->monthlyReportLastSentPeriod === $period) return false;

        try {
            $sent = $this->sendMonthlyReportEmail($range, $recipients);
            if($sent) {
                $this->saveMonthlyReportLastSentPeriod($period);
                $this->wire('log')->save('native-analytics', 'Monthly report sent for ' . $period . ' to ' . implode(', ', $recipients) . '.');
                return true;
            }
            $this->wire('log')->save('native-analytics', 'Monthly report could not be sent for ' . $period . '.');
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics', 'Monthly report failed: ' . $e->getMessage());
        }

        return false;
    }

    protected function getMonthlyReportRecipients() {
        $recipients = [];
        $raw = (string) ($this->monthlyReportRecipients ?? '');
        foreach(preg_split('/[\s,;]+/', $raw) as $email) {
            $email = trim((string) $email);
            if($email === '') continue;
            $email = $this->wire('sanitizer')->email($email);
            if($email !== '') $recipients[$email] = $email;
        }
        return array_values($recipients);
    }

    protected function getPreviousMonthReportRange($timestamp = null) {
        $timestamp = $timestamp ?: time();
        $thisMonthStart = strtotime(date('Y-m-01 00:00:00', $timestamp));
        $previousMonthStart = strtotime('-1 month', $thisMonthStart);
        $startDate = date('Y-m-01', $previousMonthStart);
        $endDate = date('Y-m-t', $previousMonthStart);
        return [
            'period' => date('Y-m', $previousMonthStart),
            'title' => date('F Y', $previousMonthStart),
            'start' => $startDate . ' 00:00:00',
            'end' => $endDate . ' 23:59:59',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    protected function getCurrentMonthReportRange($timestamp = null) {
        $timestamp = $timestamp ?: time();
        $startDate = date('Y-m-01', $timestamp);
        $endDate = date('Y-m-d', $timestamp);
        return [
            'period' => date('Y-m', $timestamp),
            'title' => date('F Y', $timestamp) . ' to date',
            'start' => $startDate . ' 00:00:00',
            'end' => $endDate . ' 23:59:59',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    protected function getLast30DaysReportRange($timestamp = null) {
        $timestamp = $timestamp ?: time();
        $startDate = date('Y-m-d', strtotime('-29 days', $timestamp));
        $endDate = date('Y-m-d', $timestamp);
        return [
            'period' => 'last-30-days',
            'title' => 'Last 30 days',
            'start' => $startDate . ' 00:00:00',
            'end' => $endDate . ' 23:59:59',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    protected function getMonthlyReportPreviewRange() {
        $previousMonth = $this->getPreviousMonthReportRange();
        if($this->monthlyReportRangeHasData($previousMonth)) return $previousMonth;

        $currentMonth = $this->getCurrentMonthReportRange();
        if($this->monthlyReportRangeHasData($currentMonth)) {
            $currentMonth['report_note'] = 'The previous calendar month has no recorded data yet, so this test/preview uses the current month to date.';
            return $currentMonth;
        }

        $last30Days = $this->getLast30DaysReportRange();
        if($this->monthlyReportRangeHasData($last30Days)) {
            $last30Days['report_note'] = 'The previous calendar month has no recorded data yet, so this test/preview uses the last 30 days.';
            return $last30Days;
        }

        $previousMonth['report_note'] = 'No analytics data was found for the previous calendar month, current month, or last 30 days yet.';
        return $previousMonth;
    }

    protected function monthlyReportRangeHasData(array $range) {
        try {
            $summary = $this->getSummary($range, []);
            $summary404 = $this->get404Summary($range);
            $eventSummary = $this->getEventSummary($range, []);
            return ((int) ($summary['views'] ?? 0) + (int) ($summary404['views'] ?? 0) + (int) ($eventSummary['events'] ?? 0)) > 0;
        } catch(\Throwable $e) {
            return false;
        }
    }

    protected function sendMonthlyReportEmail(array $range, array $recipients, $isTest = false) {
        $summary = $this->getSummary($range, []);
        $quality = $this->getSessionQuality($range, []);
        $summary404 = $this->get404Summary($range);
        $topPages = !empty($this->monthlyReportIncludeTopPages) ? $this->getTopPages($range, 10, []) : [];
        $referrers = !empty($this->monthlyReportIncludeReferrers) ? $this->getTopReferrers($range, 10, []) : [];
        $eventSummary = !empty($this->monthlyReportIncludeEvents) ? $this->getEventSummary($range, []) : ['events' => 0, 'uniques' => 0, 'sessions' => 0];
        $topEvents = !empty($this->monthlyReportIncludeEvents) ? $this->getTopEvents($range, 10, []) : [];

        $siteName = $this->getReportSiteName();
        $subject = ($isTest ? '[TEST] ' : '') . 'NativeAnalytics monthly report - ' . $siteName . ' - ' . (string) $range['title'];
        $html = $this->renderMonthlyReportHtml($range, $summary, $quality, $summary404, $topPages, $referrers, $eventSummary, $topEvents, $isTest);
        $text = $this->renderMonthlyReportText($range, $summary, $quality, $summary404, $topPages, $referrers, $eventSummary, $topEvents, $isTest);

        $mail = wireMail();
        foreach($recipients as $recipient) {
            $mail->to($recipient);
        }
        $from = $this->wire('sanitizer')->email((string) ($this->monthlyReportFromEmail ?? ''));
        if($from === '' && !empty($this->wire('config')->adminEmail)) {
            $from = $this->wire('sanitizer')->email((string) $this->wire('config')->adminEmail);
        }
        if($from !== '') $mail->from($from);
        $mail->subject($subject);
        $mail->body($text);
        if(method_exists($mail, 'bodyHTML')) $mail->bodyHTML($html);

        $pdfPath = '';
        if(!empty($this->monthlyReportAttachPdf) && method_exists($mail, 'attachment')) {
            try {
                $pdf = $this->createMonthlyReportPdfAttachment($range, $summary, $quality, $summary404, $topPages, $referrers, $eventSummary, $topEvents, $isTest);
                $pdfPath = (string) ($pdf['path'] ?? '');
                if($pdfPath !== '' && is_file($pdfPath)) {
                    try {
                        $mail->attachment($pdfPath, (string) ($pdf['name'] ?? basename($pdfPath)));
                    } catch(\Throwable $e) {
                        // Older/custom WireMail adapters may only accept the file path.
                        $mail->attachment($pdfPath);
                    }
                }
            } catch(\Throwable $e) {
                $this->wire('log')->save('native-analytics', 'Monthly report PDF attachment could not be created: ' . $e->getMessage());
            }
        }

        try {
            return ((int) $mail->send()) > 0;
        } finally {
            if($pdfPath !== '' && is_file($pdfPath)) @unlink($pdfPath);
        }
    }

    protected function getReportSiteName() {
        $config = $this->wire('config');
        if(!empty($config->siteName)) return (string) $config->siteName;
        if(!empty($config->httpHost)) return (string) $config->httpHost;
        if(!empty($_SERVER['HTTP_HOST'])) return (string) $_SERVER['HTTP_HOST'];
        return 'ProcessWire site';
    }

    protected function getAnalyticsDashboardUrl() {
        try {
            $page = $this->wire('pages')->get('template=admin, name=native-analytics, include=all');
            if($page && $page->id) return $page->httpUrl;
        } catch(\Throwable $e) {
        }
        return $this->wire('config')->urls->admin . 'native-analytics/';
    }

    protected function formatMonthlyReportDisplayValue($value) {
        $value = trim((string) $value);
        if($value === '') return '';

        if(preg_match('#^https?://#i', $value)) {
            $host = parse_url($value, PHP_URL_HOST);
            $host = $host ? preg_replace('/^www\./i', '', (string) $host) : '';
            if($host !== '') return 'External URL (' . str_replace('.', ' ', $host) . ')';
            return 'External URL';
        }

        return $value;
    }

    protected function formatMonthlyReportEventMeta(array $row) {
        $label = $this->formatMonthlyReportDisplayValue($row['event_label'] ?? '');
        $target = $this->formatMonthlyReportDisplayValue($row['event_target'] ?? '');
        return trim($label . ($label !== '' && $target !== '' ? ' · ' : '') . $target);
    }

    protected function createMonthlyReportPdfAttachment(array $range, array $summary, array $quality, array $summary404, array $topPages, array $referrers, array $eventSummary, array $topEvents, $isTest = false) {
        $siteName = $this->getReportSiteName();
        $safeSite = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $siteName));
        $safeSite = trim($safeSite, '-');
        if($safeSite === '') $safeSite = 'site';
        $safePeriod = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) ($range['period'] ?? date('Y-m'))));
        $safePeriod = trim($safePeriod, '-');
        if($safePeriod === '') $safePeriod = date('Y-m');
        $filename = 'nativeanalytics-report-' . $safeSite . '-' . $safePeriod . ($isTest ? '-test' : '') . '.pdf';

        $cacheDir = $this->wire('config')->paths->cache . 'NativeAnalytics/';
        if(!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        if(!is_dir($cacheDir) || !is_writable($cacheDir)) {
            throw new \RuntimeException('Cache directory is not writable: ' . $cacheDir);
        }

        $path = $cacheDir . uniqid('monthly-report-', true) . '.pdf';
        $pdf = $this->renderMonthlyReportPdf($range, $summary, $quality, $summary404, $topPages, $referrers, $eventSummary, $topEvents, $isTest);
        if(file_put_contents($path, $pdf) === false) {
            throw new \RuntimeException('Could not write PDF report file.');
        }

        return ['path' => $path, 'name' => $filename];
    }

    protected function renderMonthlyReportPdf(array $range, array $summary, array $quality, array $summary404, array $topPages, array $referrers, array $eventSummary, array $topEvents, $isTest = false) {
        $metrics = [
            ['Page views', (string) (int) ($summary['views'] ?? 0)],
            ['Unique visitors', (string) (int) ($summary['uniques'] ?? 0)],
            ['Sessions', (string) (int) ($summary['sessions'] ?? 0)],
            ['Events', (string) (int) ($eventSummary['events'] ?? 0)],
            ['404 hits', (string) (int) ($summary404['views'] ?? 0)],
            ['Avg. pages/session', number_format((float) ($quality['avg_pages_per_session'] ?? 0), 2)],
        ];

        $elements = [];
        $elements[] = [
            'type' => 'header',
            'title' => ($isTest ? '[TEST] ' : '') . 'NativeAnalytics monthly report',
            'site' => $this->getReportSiteName(),
            'period' => (string) ($range['title'] ?? '') . ' · ' . $this->formatDisplayRange($range),
        ];
        if($isTest) {
            $elements[] = ['type' => 'note', 'text' => 'This is a manually sent test report. The normal monthly send marker was not updated.'];
        }
        if(!empty($range['report_note'])) {
            $elements[] = ['type' => 'note', 'text' => (string) $range['report_note']];
        }
        $elements[] = ['type' => 'metrics', 'items' => $metrics];

        if(!empty($this->monthlyReportIncludeTopPages)) {
            $rows = [];
            foreach($topPages as $row) {
                $label = ($row['page_title'] ?? '') !== '' ? (string) $row['page_title'] : (string) ($row['path'] ?? '');
                $path = (string) ($row['path'] ?? '');
                if($path !== '' && $path !== $label) $label .= ' (' . $path . ')';
                $rows[] = [
                    $label,
                    (string) (int) ($row['views'] ?? 0),
                    (string) (int) ($row['uniques'] ?? 0),
                    (string) (int) ($row['sessions'] ?? 0),
                ];
            }
            $elements[] = ['type' => 'section', 'title' => 'Top pages'];
            $elements[] = ['type' => 'table', 'headers' => ['Page', 'Views', 'Uniques', 'Sessions'], 'widths' => [0.58, 0.14, 0.14, 0.14], 'rows' => $rows, 'empty' => 'No page data for this period.'];
        }

        if(!empty($this->monthlyReportIncludeReferrers)) {
            $rows = [];
            foreach($referrers as $row) {
                $rows[] = [
                    (string) ($row['referrer_host'] ?? ''),
                    (string) (int) ($row['views'] ?? 0),
                ];
            }
            $elements[] = ['type' => 'section', 'title' => 'Top referrers'];
            $elements[] = ['type' => 'table', 'headers' => ['Referrer', 'Views'], 'widths' => [0.78, 0.22], 'rows' => $rows, 'empty' => 'No referrer data for this period.'];
        }

        if(!empty($this->monthlyReportIncludeChannels)) {
            $rows = [];
            foreach($this->getTopChannels($range, 8, []) as $row) {
                $rows[] = [
                    (string) ($row['label'] ?? ''),
                    (string) (int) ($row['sessions'] ?? 0),
                    (string) (int) ($row['uniques'] ?? 0),
                ];
            }
            $elements[] = ['type' => 'section', 'title' => 'Channels (first-touch sessions)'];
            $elements[] = ['type' => 'table', 'headers' => ['Channel', 'Sessions', 'Unique visitors'], 'widths' => [0.58, 0.21, 0.21], 'rows' => $rows, 'empty' => 'No acquisition data for this period.'];
        }

        if(!empty($this->monthlyReportIncludeEvents)) {
            $rows = [];
            foreach($topEvents as $row) {
                $meta = $this->formatMonthlyReportEventMeta($row);
                $event = trim((string) ($row['event_group'] ?? '') . ' / ' . (string) ($row['event_name'] ?? ''), ' /');
                if($meta !== '') $event .= ' · ' . $meta;
                $rows[] = [
                    $event,
                    (string) (int) ($row['events'] ?? 0),
                ];
            }
            $elements[] = ['type' => 'section', 'title' => 'Top engagement events'];
            $elements[] = ['type' => 'table', 'headers' => ['Event', 'Count'], 'widths' => [0.78, 0.22], 'rows' => $rows, 'empty' => 'No event data for this period.'];
        }

        $elements[] = ['type' => 'footer', 'text' => 'Dashboard: ' . $this->getAnalyticsDashboardUrl()];
        return $this->buildStyledMonthlyReportPdf($elements);
    }

    protected function buildStyledMonthlyReportPdf(array $elements) {
        $pageWidth = 595;
        $pageHeight = 842;
        $marginLeft = 42;
        $marginRight = 42;
        $marginTop = 42;
        $marginBottom = 44;
        $contentWidth = $pageWidth - $marginLeft - $marginRight;
        $pages = [];
        $stream = '';
        $y = $pageHeight - $marginTop;

        $rgb = function($r, $g, $b) {
            return sprintf('%.3F %.3F %.3F', $r / 255, $g / 255, $b / 255);
        };
        $setFill = function($r, $g, $b) use ($rgb) {
            return $rgb($r, $g, $b) . " rg\n";
        };
        $setStroke = function($r, $g, $b) use ($rgb) {
            return $rgb($r, $g, $b) . " RG\n";
        };
        $rect = function($x, $bottom, $w, $h, $fill = null, $stroke = null) use (&$stream, $setFill, $setStroke) {
            if(is_array($fill)) $stream .= $setFill($fill[0], $fill[1], $fill[2]);
            if(is_array($stroke)) $stream .= $setStroke($stroke[0], $stroke[1], $stroke[2]);
            $stream .= sprintf('%.2F %.2F %.2F %.2F re ', $x, $bottom, $w, $h);
            if(is_array($fill) && is_array($stroke)) {
                $stream .= "B\n";
            } elseif(is_array($fill)) {
                $stream .= "f\n";
            } elseif(is_array($stroke)) {
                $stream .= "S\n";
            }
        };
        $line = function($x1, $y1, $x2, $y2, $color = [217, 225, 234]) use (&$stream, $setStroke) {
            $stream .= $setStroke($color[0], $color[1], $color[2]);
            $stream .= sprintf('%.2F %.2F m %.2F %.2F l S' . "\n", $x1, $y1, $x2, $y2);
        };
        $text = function($x, $baseline, $value, $size = 10, $bold = false, $color = [47, 66, 79]) use (&$stream, $setFill) {
            $font = $bold ? 'F2' : 'F1';
            $stream .= $setFill($color[0], $color[1], $color[2]);
            $stream .= 'BT /' . $font . ' ' . (int) $size . ' Tf ' . sprintf('%.2F %.2F', $x, $baseline) . ' Td (' . $this->pdfEscapeText((string) $value) . ") Tj ET\n";
        };
        $estimateTextWidth = function($value, $size = 10) {
            return strlen((string) $value) * ((int) $size * 0.52);
        };
        $wrappedLines = function($value, $width, $size = 10) {
            $maxChars = max(8, (int) floor($width / max(4, ((int) $size * 0.52))));
            return $this->wrapPdfText($value, $maxChars);
        };
        $finishPage = function() use (&$pages, &$stream) {
            $pages[] = $stream;
            $stream = '';
        };
        $ensureSpace = function($height) use (&$y, $pageHeight, $marginTop, $marginBottom, &$finishPage) {
            if($y - $height < $marginBottom) {
                $finishPage();
                $y = $pageHeight - $marginTop;
            }
        };

        foreach($elements as $element) {
            $type = (string) ($element['type'] ?? '');

            if($type === 'header') {
                $height = 92;
                $ensureSpace($height);
                $top = $y;
                $bottom = $top - $height;
                $rect($marginLeft, $bottom, $contentWidth, $height, [246, 248, 250], [217, 225, 234]);
                $rect($marginLeft, $bottom, 5, $height, [47, 111, 159], null);
                $text($marginLeft + 18, $top - 28, (string) ($element['title'] ?? 'NativeAnalytics monthly report'), 19, true, [47, 66, 79]);
                $text($marginLeft + 18, $top - 51, (string) ($element['site'] ?? ''), 11, true, [96, 115, 132]);
                $text($marginLeft + 18, $top - 70, (string) ($element['period'] ?? ''), 10, false, [96, 115, 132]);
                $y = $bottom - 18;
                continue;
            }

            if($type === 'note') {
                $lines = $wrappedLines((string) ($element['text'] ?? ''), $contentWidth - 22, 9);
                $height = 20 + (count($lines) * 12);
                $ensureSpace($height + 8);
                $top = $y;
                $bottom = $top - $height;
                $rect($marginLeft, $bottom, $contentWidth, $height, [255, 252, 238], [232, 212, 138]);
                $ty = $top - 17;
                foreach($lines as $part) {
                    $text($marginLeft + 11, $ty, $part, 9, false, [95, 74, 0]);
                    $ty -= 12;
                }
                $y = $bottom - 14;
                continue;
            }

            if($type === 'metrics') {
                $items = $element['items'] ?? [];
                if(!is_array($items)) $items = [];
                $cols = 3;
                $gap = 10;
                $cardW = ($contentWidth - (($cols - 1) * $gap)) / $cols;
                $cardH = 55;
                $rows = max(1, (int) ceil(count($items) / $cols));
                $height = ($rows * $cardH) + (($rows - 1) * $gap) + 8;
                $ensureSpace($height + 4);
                foreach($items as $i => $item) {
                    $col = $i % $cols;
                    $row = (int) floor($i / $cols);
                    $x = $marginLeft + ($col * ($cardW + $gap));
                    $top = $y - ($row * ($cardH + $gap));
                    $bottom = $top - $cardH;
                    $rect($x, $bottom, $cardW, $cardH, [255, 255, 255], [217, 225, 234]);
                    $text($x + 10, $top - 17, (string) ($item[0] ?? ''), 8, true, [96, 115, 132]);
                    $text($x + 10, $top - 38, (string) ($item[1] ?? '0'), 18, true, [47, 66, 79]);
                }
                $y -= $height + 10;
                continue;
            }

            if($type === 'section') {
                $ensureSpace(34);
                $text($marginLeft, $y - 2, (string) ($element['title'] ?? ''), 13, true, [47, 66, 79]);
                $line($marginLeft, $y - 13, $marginLeft + $contentWidth, $y - 13, [217, 225, 234]);
                $y -= 28;
                continue;
            }

            if($type === 'table') {
                $headers = $element['headers'] ?? [];
                $widths = $element['widths'] ?? [];
                $rows = $element['rows'] ?? [];
                if(!is_array($headers)) $headers = [];
                if(!is_array($widths)) $widths = [];
                if(!is_array($rows)) $rows = [];
                $colCount = count($headers);
                if($colCount < 1) continue;
                $sum = array_sum($widths) ?: $colCount;
                $colWidths = [];
                for($i = 0; $i < $colCount; $i++) {
                    $colWidths[$i] = $contentWidth * (((float) ($widths[$i] ?? (1 / $colCount))) / $sum);
                }

                $headerH = 24;
                $ensureSpace($headerH + 30);
                $rect($marginLeft, $y - $headerH, $contentWidth, $headerH, [239, 244, 248], [217, 225, 234]);
                $x = $marginLeft;
                foreach($headers as $i => $h) {
                    $text($x + 7, $y - 16, (string) $h, 8, true, [96, 115, 132]);
                    $x += $colWidths[$i];
                }
                $y -= $headerH;

                if(!$rows) {
                    $rowH = 28;
                    $ensureSpace($rowH + 6);
                    $rect($marginLeft, $y - $rowH, $contentWidth, $rowH, [255, 255, 255], [217, 225, 234]);
                    $text($marginLeft + 7, $y - 18, (string) ($element['empty'] ?? 'No data for this period.'), 9, false, [96, 115, 132]);
                    $y -= $rowH + 14;
                    continue;
                }

                foreach($rows as $rowIndex => $row) {
                    if(!is_array($row)) $row = [];
                    $cellLines = [];
                    $rowLineCount = 1;
                    for($i = 0; $i < $colCount; $i++) {
                        $lines = $wrappedLines((string) ($row[$i] ?? ''), $colWidths[$i] - 14, 8);
                        $cellLines[$i] = $lines;
                        $rowLineCount = max($rowLineCount, count($lines));
                    }
                    $rowH = max(24, 13 + ($rowLineCount * 11));
                    $ensureSpace($rowH + 6);
                    $fill = ($rowIndex % 2 === 0) ? [255, 255, 255] : [250, 252, 253];
                    $rect($marginLeft, $y - $rowH, $contentWidth, $rowH, $fill, [217, 225, 234]);
                    $x = $marginLeft;
                    for($i = 0; $i < $colCount; $i++) {
                        $ty = $y - 15;
                        foreach($cellLines[$i] as $part) {
                            $color = $i === 0 ? [47, 66, 79] : [96, 115, 132];
                            $text($x + 7, $ty, $part, 8, false, $color);
                            $ty -= 11;
                        }
                        $x += $colWidths[$i];
                    }
                    $y -= $rowH;
                }
                $y -= 16;
                continue;
            }

            if($type === 'footer') {
                $ensureSpace(28);
                $line($marginLeft, $y, $marginLeft + $contentWidth, $y, [217, 225, 234]);
                $text($marginLeft, $y - 16, (string) ($element['text'] ?? ''), 8, false, [96, 115, 132]);
                $y -= 28;
            }
        }

        if($stream !== '') $finishPage();
        if(!$pages) $pages[] = '';

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = ''; // Filled after page objects are known.
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $pageRefs = [];
        $pageCount = count($pages);
        foreach($pages as $i => $pageStream) {
            $footer = '';
            $footer .= $setFill(132, 146, 160);
            $footer .= 'BT /F1 8 Tf ' . sprintf('%.2F %.2F', $marginLeft, 24) . ' Td (' . $this->pdfEscapeText('NativeAnalytics') . ") Tj ET\n";
            $pageLabel = 'Page ' . ($i + 1) . ' of ' . $pageCount;
            $footerX = $pageWidth - $marginRight - max(40, $estimateTextWidth($pageLabel, 8));
            $footer .= 'BT /F1 8 Tf ' . sprintf('%.2F %.2F', $footerX, 24) . ' Td (' . $this->pdfEscapeText($pageLabel) . ") Tj ET\n";
            $pageStream .= $footer;
            $contentId = count($objects) + 1;
            $objects[] = '<< /Length ' . strlen($pageStream) . " >>\nstream\n" . $pageStream . 'endstream';
            $pageId = count($objects) + 1;
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $pageWidth . ' ' . $pageHeight . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
            $pageRefs[] = $pageId . ' 0 R';
        }

        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . count($pageRefs) . ' >>';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach($objects as $i => $object) {
            $offsets[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    protected function wrapPdfText($text, $maxChars = 96) {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
        if($text === '') return [''];
        $words = preg_split('/\s+/', $text);
        $lines = [];
        $line = '';
        foreach($words as $word) {
            if($line === '') {
                $line = $word;
                continue;
            }
            if(strlen($line . ' ' . $word) > $maxChars) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line .= ' ' . $word;
            }
        }
        if($line !== '') $lines[] = $line;
        return $lines ?: [''];
    }

    protected function pdfEscapeText($text) {
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtr($text, [
            '→' => '->', '←' => '<-', '–' => '-', '—' => '-', '·' => '-', '…' => '...',
            '“' => '"', '”' => '"', '„' => '"', '‘' => "'", '’' => "'",
            "\xc2\xa0" => ' ',
        ]);
        if(function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if($converted !== false) $text = $converted;
        } else {
            $text = strtr($text, [
                'Č' => 'C', 'Š' => 'S', 'Ž' => 'Z', 'Ć' => 'C', 'Đ' => 'D',
                'č' => 'c', 'š' => 's', 'ž' => 'z', 'ć' => 'c', 'đ' => 'd',
            ]);
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    protected function renderMonthlyReportHtml(array $range, array $summary, array $quality, array $summary404, array $topPages, array $referrers, array $eventSummary, array $topEvents, $isTest = false) {
        $s = $this->wire('sanitizer');
        $dashboardUrl = $this->getAnalyticsDashboardUrl();
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#222;line-height:1.45;">';
        $html .= '<h1 style="font-size:22px;margin:0 0 6px;">' . ($isTest ? '[TEST] ' : '') . 'NativeAnalytics monthly report</h1>';
        if($isTest) $html .= '<p style="margin:0 0 12px;padding:8px 10px;background:#fff8e1;border:1px solid #e6d48a;color:#5f4a00;">This is a manually sent test report. The normal monthly send marker was not updated.</p>';
        if(!empty($range['report_note'])) $html .= '<p style="margin:0 0 12px;padding:8px 10px;background:#eef6fb;border:1px solid #c7dbe8;color:#2b5368;">' . $s->entities((string) $range['report_note']) . '</p>';
        $html .= '<p style="margin:0 0 18px;color:#666;">' . $s->entities($this->getReportSiteName()) . ' · ' . $s->entities((string) $range['title']) . ' · ' . $s->entities($this->formatDisplayRange($range)) . '</p>';
        $html .= '<table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;margin:0 0 18px;">';
        $html .= '<tr>';
        $html .= $this->renderMonthlyReportMetricCell('Page views', (int) ($summary['views'] ?? 0));
        $html .= $this->renderMonthlyReportMetricCell('Unique visitors', (int) ($summary['uniques'] ?? 0));
        $html .= $this->renderMonthlyReportMetricCell('Sessions', (int) ($summary['sessions'] ?? 0));
        $html .= $this->renderMonthlyReportMetricCell('Current 404 hits', (int) ($summary404['views'] ?? 0));
        $html .= '</tr>';
        $html .= '<tr>';
        $html .= $this->renderMonthlyReportMetricCell('Events', (int) ($eventSummary['events'] ?? 0));
        $html .= $this->renderMonthlyReportMetricCell('Avg. pages/session', number_format((float) ($quality['avg_pages_per_session'] ?? 0), 2));
        $html .= $this->renderMonthlyReportMetricCell('Single-page rate', (string) ($quality['single_page_rate'] ?? 0) . '%');
        $html .= $this->renderMonthlyReportMetricCell('Event sessions', (int) ($eventSummary['sessions'] ?? 0));
        $html .= '</tr>';
        $html .= '</table>';

        if(!empty($this->monthlyReportIncludeTopPages)) {
            $html .= $this->renderMonthlyReportTable('Top pages', ['Page', 'Views', 'Uniques', 'Sessions'], $topPages, function($row) {
                $title = (string) ($row['page_title'] ?? '');
                $path = (string) ($row['path'] ?? '');
                return [
                    $title !== '' ? $title . ' (' . $path . ')' : $path,
                    (int) ($row['views'] ?? 0),
                    (int) ($row['uniques'] ?? 0),
                    (int) ($row['sessions'] ?? 0),
                ];
            });
        }

        if(!empty($this->monthlyReportIncludeReferrers)) {
            $html .= $this->renderMonthlyReportTable('Top referrers', ['Referrer', 'Views'], $referrers, function($row) {
                return [
                    $row['referrer_host'] ?? '',
                    (int) ($row['views'] ?? 0),
                ];
            });
        }

        if(!empty($this->monthlyReportIncludeChannels)) {
            $channels = $this->getTopChannels($range, 8, []);
            $html .= $this->renderMonthlyReportTable('Channels (first-touch sessions)', ['Channel', 'Sessions', 'Unique visitors'], $channels, function($row) {
                return [
                    $row['label'] ?? '',
                    (int) ($row['sessions'] ?? 0),
                    (int) ($row['uniques'] ?? 0),
                ];
            });
        }

        if(!empty($this->monthlyReportIncludeEvents)) {
            $html .= $this->renderMonthlyReportTable('Top engagement events', ['Group', 'Event', 'Label / target', 'Events'], $topEvents, function($row) {
                return [
                    $row['event_group'] ?? '',
                    $row['event_name'] ?? '',
                    $this->formatMonthlyReportEventMeta($row),
                    (int) ($row['events'] ?? 0),
                ];
            });
        }

        $html .= '<p style="margin:20px 0 0;"><a href="' . $s->entities($dashboardUrl) . '" style="display:inline-block;padding:9px 14px;border:1px solid #2f6f9f;text-decoration:none;color:#2f6f9f;">Open full analytics dashboard</a></p>';
        $html .= '</body></html>';
        return $html;
    }

    protected function renderMonthlyReportMetricCell($label, $value) {
        $s = $this->wire('sanitizer');
        return '<td style="border:1px solid #ddd;background:#f7f7f7;"><div style="font-size:12px;color:#666;">' . $s->entities((string) $label) . '</div><strong style="font-size:20px;">' . $s->entities((string) $value) . '</strong></td>';
    }

    protected function renderMonthlyReportTable($title, array $headers, array $rows, callable $mapRow) {
        $s = $this->wire('sanitizer');
        $html = '<h2 style="font-size:17px;margin:22px 0 8px;">' . $s->entities((string) $title) . '</h2>';
        if(!$rows) return $html . '<p style="color:#777;margin:0 0 12px;">No data for this period.</p>';
        $html .= '<table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:0 0 14px;">';
        $html .= '<tr>';
        foreach($headers as $header) {
            $html .= '<th align="left" style="border-bottom:1px solid #ddd;background:#f3f3f3;">' . $s->entities((string) $header) . '</th>';
        }
        $html .= '</tr>';
        foreach($rows as $row) {
            $html .= '<tr>';
            foreach($mapRow($row) as $cell) {
                $html .= '<td style="border-bottom:1px solid #eee;vertical-align:top;">' . $s->entities((string) $cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        return $html;
    }

    protected function renderMonthlyReportText(array $range, array $summary, array $quality, array $summary404, array $topPages, array $referrers, array $eventSummary, array $topEvents, $isTest = false) {
        $lines = [];
        $lines[] = ($isTest ? '[TEST] ' : '') . 'NativeAnalytics monthly report';
        if($isTest) $lines[] = 'This is a manually sent test report. The normal monthly send marker was not updated.';
        if(!empty($range['report_note'])) $lines[] = (string) $range['report_note'];
        $lines[] = $this->getReportSiteName() . ' - ' . (string) $range['title'] . ' - ' . $this->formatDisplayRange($range);
        $lines[] = '';
        $lines[] = 'Page views: ' . (int) ($summary['views'] ?? 0);
        $lines[] = 'Unique visitors: ' . (int) ($summary['uniques'] ?? 0);
        $lines[] = 'Sessions: ' . (int) ($summary['sessions'] ?? 0);
        $lines[] = '404 hits: ' . (int) ($summary404['views'] ?? 0);
        $lines[] = 'Events: ' . (int) ($eventSummary['events'] ?? 0);
        $lines[] = 'Avg. pages/session: ' . number_format((float) ($quality['avg_pages_per_session'] ?? 0), 2);
        $lines[] = 'Single-page rate: ' . (string) ($quality['single_page_rate'] ?? 0) . '%';
        $lines[] = '';

        if(!empty($this->monthlyReportIncludeTopPages)) {
            $lines[] = 'Top pages:';
            foreach($topPages as $row) {
                $label = ($row['page_title'] ?? '') !== '' ? ($row['page_title'] . ' (' . ($row['path'] ?? '') . ')') : ($row['path'] ?? '');
                $lines[] = '- ' . $label . ': ' . (int) ($row['views'] ?? 0) . ' views';
            }
            if(!$topPages) $lines[] = '- No data';
            $lines[] = '';
        }

        if(!empty($this->monthlyReportIncludeReferrers)) {
            $lines[] = 'Top referrers:';
            foreach($referrers as $row) {
                $lines[] = '- ' . ($row['referrer_host'] ?? '') . ': ' . (int) ($row['views'] ?? 0) . ' views';
            }
            if(!$referrers) $lines[] = '- No data';
            $lines[] = '';
        }

        if(!empty($this->monthlyReportIncludeChannels)) {
            $channels = $this->getTopChannels($range, 8, []);
            $lines[] = 'Channels (first-touch sessions):';
            foreach($channels as $row) {
                $lines[] = '- ' . ($row['label'] ?? '') . ': ' . (int) ($row['sessions'] ?? 0) . ' sessions';
            }
            if(!$channels) $lines[] = '- No data';
            $lines[] = '';
        }

        if(!empty($this->monthlyReportIncludeEvents)) {
            $lines[] = 'Top engagement events:';
            foreach($topEvents as $row) {
                $meta = $this->formatMonthlyReportEventMeta($row);
                $lines[] = '- ' . ($row['event_group'] ?? '') . ' / ' . ($row['event_name'] ?? '') . ($meta !== '' ? ' / ' . $meta : '') . ': ' . (int) ($row['events'] ?? 0) . ' events';
            }
            if(!$topEvents) $lines[] = '- No data';
            $lines[] = '';
        }

        $lines[] = 'Open full analytics dashboard: ' . $this->getAnalyticsDashboardUrl();
        return implode("\n", $lines);
    }

    protected function renderMonthlyReportAdminPreview($isTest = true) {
        $range = $this->getMonthlyReportPreviewRange();
        $summary = $this->getSummary($range, []);
        $quality = $this->getSessionQuality($range, []);
        $summary404 = $this->get404Summary($range);
        $topPages = !empty($this->monthlyReportIncludeTopPages) ? $this->getTopPages($range, 10, []) : [];
        $referrers = !empty($this->monthlyReportIncludeReferrers) ? $this->getTopReferrers($range, 10, []) : [];
        $eventSummary = !empty($this->monthlyReportIncludeEvents) ? $this->getEventSummary($range, []) : ['events' => 0, 'uniques' => 0, 'sessions' => 0];
        $topEvents = !empty($this->monthlyReportIncludeEvents) ? $this->getTopEvents($range, 10, []) : [];
        $html = $this->renderMonthlyReportHtml($range, $summary, $quality, $summary404, $topPages, $referrers, $eventSummary, $topEvents, $isTest);

        if(preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $matches)) {
            $html = $matches[1];
        }

        return '<div class="pwna-monthly-report-preview" style="max-width:980px;background:#fff;border:1px solid #d9e1e8;padding:18px;margin-top:8px;">' . $html . '</div>';
    }

    protected function saveMonthlyReportLastSentPeriod($period) {
        $period = trim((string) $period);
        if($period === '') return;
        $this->monthlyReportLastSentPeriod = $period;
        try {
            $this->wire('modules')->saveConfig($this, ['monthlyReportLastSentPeriod' => $period]);
        } catch(\Throwable $e) {
            try {
                $this->wire('modules')->saveConfig('NativeAnalytics', ['monthlyReportLastSentPeriod' => $period]);
            } catch(\Throwable $e2) {
                $this->wire('log')->save('native-analytics', 'Could not save monthly report marker: ' . $e2->getMessage());
            }
        }
    }


    /**
     * Returns info about the currently loaded matomo/device-detector and,
     * if available (and cached), the latest released version on GitHub.
     *
     * Result keys:
     *   - current        : version string in use (e.g. "6.4.2") or ''
     *   - latest         : latest release tag from GitHub or ''
     *   - latest_url     : release page URL or ''
     *   - update_available : bool
     *   - check_failed   : bool (true if we tried and couldn't reach GitHub)
     *   - checked_at     : timestamp of last successful check
     *
     * The GitHub lookup is cached for 24h via WireCache to respect rate limits.
     * Pass $forceRefresh=true to bypass the cache.
     */
    public function getDeviceDetectorVersionInfo($forceRefresh = false) {
        $info = [
            'current' => '',
            'latest' => '',
            'latest_url' => '',
            'update_available' => false,
            'check_failed' => false,
            'checked_at' => 0,
        ];

        // Make sure the library is loaded so we can read its VERSION constant.
        $this->getDeviceDetector('Mozilla/5.0');
        if(defined('DeviceDetector\\DeviceDetector::VERSION')) {
            $info['current'] = (string) \DeviceDetector\DeviceDetector::VERSION;
        }

        if($info['current'] === '') return $info;

        $cacheKey = 'NativeAnalytics.dd_latest_version';
        $cache = $this->wire('cache');
        $cached = null;
        if(!$forceRefresh && $cache) {
            $cached = $cache->get($cacheKey);
            if(is_array($cached) && isset($cached['latest'])) {
                $info['latest'] = (string) $cached['latest'];
                $info['latest_url'] = (string) ($cached['latest_url'] ?? '');
                $info['checked_at'] = (int) ($cached['checked_at'] ?? 0);
                $info['update_available'] = $this->versionGreaterThan($info['latest'], $info['current']);
                return $info;
            }
        }

        // Fetch from GitHub API
        $latest = $this->fetchLatestDeviceDetectorRelease();
        if($latest === null) {
            $info['check_failed'] = true;
            // Negative cache for 1 hour so we don't keep retrying on every page load.
            if($cache) $cache->save($cacheKey, ['latest' => '', 'latest_url' => '', 'checked_at' => time(), 'failed' => true], 3600);
            return $info;
        }

        $info['latest'] = $latest['tag'];
        $info['latest_url'] = $latest['url'];
        $info['checked_at'] = time();
        $info['update_available'] = $this->versionGreaterThan($info['latest'], $info['current']);

        // Cache for 24h
        if($cache) {
            $cache->save($cacheKey, [
                'latest' => $info['latest'],
                'latest_url' => $info['latest_url'],
                'checked_at' => $info['checked_at'],
                'failed' => false,
            ], 86400);
        }

        return $info;
    }

    /**
     * Fetches the latest released tag of matomo/device-detector from the GitHub API.
     * Returns ['tag' => '6.4.3', 'url' => 'https://...'] or null on failure.
     * Network errors, missing extensions and rate-limit responses all fall through
     * as null — the caller treats this as "couldn't check, don't show anything".
     */
    protected function fetchLatestDeviceDetectorRelease() {
        $url = 'https://api.github.com/repos/matomo-org/device-detector/releases/latest';
        $body = null;

        // 1) Prefer ProcessWire's WireHttp when available
        if(class_exists('\\ProcessWire\\WireHttp')) {
            try {
                $http = $this->wire(new \ProcessWire\WireHttp());
                $http->setHeader('Accept', 'application/vnd.github+json');
                $http->setHeader('User-Agent', 'NativeAnalytics-ProcessWire-Module');
                $resp = $http->get($url);
                if(is_string($resp) && $resp !== '') $body = $resp;
            } catch(\Throwable $e) {
                $body = null;
            }
        }

        // 2) Fallback: cURL directly
        if($body === null && function_exists('curl_init')) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_USERAGENT => 'NativeAnalytics-ProcessWire-Module',
                    CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $resp = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if($status === 200 && is_string($resp) && $resp !== '') $body = $resp;
            } catch(\Throwable $e) {
                $body = null;
            }
        }

        // 3) Last-resort: file_get_contents (only if allow_url_fopen)
        if($body === null && function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
            try {
                $ctx = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'header' => "User-Agent: NativeAnalytics-ProcessWire-Module\r\nAccept: application/vnd.github+json\r\n",
                        'timeout' => 5,
                        'ignore_errors' => true,
                    ],
                ]);
                $resp = @file_get_contents($url, false, $ctx);
                if(is_string($resp) && $resp !== '') $body = $resp;
            } catch(\Throwable $e) {
                $body = null;
            }
        }

        if(!$body) return null;

        $data = json_decode((string) $body, true);
        if(!is_array($data) || empty($data['tag_name'])) return null;

        return [
            'tag' => (string) $data['tag_name'],
            'url' => (string) ($data['html_url'] ?? 'https://github.com/matomo-org/device-detector/releases'),
        ];
    }

    /**
     * Simple semver-ish version comparison; returns true if $a > $b.
     * Strips leading 'v', then defers to PHP's version_compare.
     */
    protected function versionGreaterThan($a, $b) {
        $a = ltrim((string) $a, 'vV');
        $b = ltrim((string) $b, 'vV');
        if($a === '' || $b === '') return false;
        return version_compare($a, $b, '>');
    }

    protected function parseUserAgent($ua) {
        $ua = (string) $ua;

        // Prefer matomo/device-detector when available — much more accurate
        // browser/OS/device detection than the regex fallback below.
        $detector = $this->getDeviceDetector($ua);
        if($detector !== null) {
            try {
                $detector->parse();
                $clientType = method_exists($detector, 'getClient') ? (string) $detector->getClient('type') : '';
                if(!$detector->isBot() && $clientType !== 'library' && $clientType !== 'feed reader') {
                    $client = $detector->getClient('name');
                    $osName = $detector->getOs('name');
                    $deviceTypeName = method_exists($detector, 'getDeviceName') ? (string) $detector->getDeviceName() : '';
                    $browser = $client ? (string) $client : 'Other';
                    $os = $osName ? (string) $osName : 'Other';
                    $device = 'desktop';
                    $dt = strtolower($deviceTypeName);
                    if(in_array($dt, ['smartphone', 'phablet', 'feature phone'], true)) $device = 'mobile';
                    elseif($dt === 'tablet') $device = 'tablet';
                    elseif($dt === 'desktop') $device = 'desktop';
                    elseif($dt === 'television' || $dt === 'smart display' || $dt === 'console') $device = 'tv';
                    return ['browser' => $browser, 'os' => $os, 'device_type' => $device];
                }
            } catch(\Throwable $e) {
                // fall through to regex
            }
        }

        $browser = 'Other';
        $os = 'Other';
        $device = 'desktop';

        if(preg_match('/ipad|tablet/i', $ua)) $device = 'tablet';
        elseif(preg_match('/mobi|android|iphone/i', $ua)) $device = 'mobile';

        foreach([
            'Edge' => '/edg/i',
            'Opera' => '/opera|opr\//i',
            'Chrome' => '/chrome|crios/i',
            'Firefox' => '/firefox|fxios/i',
            'Safari' => '/safari/i',
        ] as $label => $regex) {
            if(!preg_match($regex, $ua)) continue;
            if($label === 'Safari' && preg_match('/chrome|crios|android|edg|opr\//i', $ua)) continue;
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

    /**
     * Detect bot user-agents.
     *
     * Strategy:
     *  1. If matomo/device-detector is available (Composer or manually placed in
     *     /site/modules/NativeAnalytics/vendor/), use its Bot class — thousands of
     *     up-to-date patterns maintained by the Matomo team.
     *  2. Otherwise fall back to a broad regex that covers generic markers,
     *     scripting libraries, social previewers, AI/LLM scrapers, SEO crawlers,
     *     search engine bots, uptime monitors and common scanners.
     *
     * Results are statically cached per UA per request to avoid repeated work.
     */
    protected function isBotUserAgent($ua) {
        if(!$ua) return false;

        static $cache = [];
        if(isset($cache[$ua])) return $cache[$ua];

        // 1) matomo/device-detector if available
        $detector = $this->getDeviceDetector($ua);
        if($detector !== null) {
            try {
                $detector->parse();
                if(method_exists($detector, 'isBot') && $detector->isBot()) {
                    return $cache[$ua] = true;
                }
                // matomo classifies tools like curl, wget, python-requests, GuzzleHttp,
                // libwww-perl, Java HTTP client etc. as "library" (a client type, not a bot).
                // For analytics purposes these are non-human traffic and should be filtered.
                $clientType = method_exists($detector, 'getClient') ? (string) $detector->getClient('type') : '';
                if($clientType === 'library' || $clientType === 'feed reader') {
                    return $cache[$ua] = true;
                }
            } catch(\Throwable $e) {
                // fall through to regex
            }
        }

        // 2) Regex fallback (broad, 2026 updated)
        $pattern = '/'
            // Generic markers
            . 'bot\b|crawl|spider|slurp|fetch(?!er-cit)|preview|headless|monitor|scanner|archiver|indexer|validator|checker|analyzer|inspector|harvester|extractor|parser\b'
            // Tooling / scripts / HTTP libraries
            . '|python-requests|python-urllib|python\/[\d\.]+|aiohttp|httpx|node-fetch|undici|got\/[\d\.]+|axios|guzzlehttp|reqwest|okhttp|libwww-perl|java\/[\d\.]+|apache-httpclient'
            . '|wget|curl|go-http-client|ruby|scrapy|phantomjs|selenium|puppeteer|playwright|chrome-lighthouse|httrack|wkhtmltopdf|katana|colly|crawlee|nutch'
            // Social previewers
            . '|facebookexternalhit|facebot|twitterbot|linkedinbot|whatsapp|telegrambot|slackbot|discordbot|skypeuripreview|embedly|tumblr|pinterest|vkshare|redditbot|mastodon|threads\b|bluesky'
            // AI / LLM scrapers (2025-2026 updated)
            . '|gptbot|chatgpt-user|oai-searchbot|searchgpt|claudebot|claude-web|anthropic-ai|perplexitybot|perplexity-user|youbot|cohere-ai|cohere-training-data-crawler'
            . '|bytespider|amazonbot|applebot(?:-extended)?|googleother|google-extended|meta-externalagent|meta-externalfetcher|diffbot|ccbot|kagibot|mistralai-user|deepseekbot|qwantbot'
            . '|ai2bot|datalbench|panscient|webzio|webz\.io|omgilibot|omgili|trendkite|peer39|magpie-crawler|netestate|tineye|webcopier|imagesift|brightedge|websparker'
            // SEO crawlers
            . '|ahrefsbot|semrushbot|mj12bot|dotbot|rogerbot|exabot|seznambot|petalbot|barkrowler|serpstatbot|sogou|opensiteexplorer|linkdexbot|gigabot|ia_archiver|screaming\s?frog|sitebulb|oncrawl|botify|sitechecker|coccocbot|moatbot'
            // Search engine bots
            . '|googlebot|adsbot-google|mediapartners-google|bingbot|adidxbot|duckduckbot|yandex(?:bot|images)|baiduspider|naverbot|yeti|sogou|360spider|so\.com'
            // Uptime / monitoring / synthetics
            . '|uptimerobot|pingdom|statuscake|gtmetrix|newrelicpinger|datadogsynthetics|site24x7|hetrix|better\s?uptime|jetmon|monitisbot|cloudwatchsynthetics|pulsepoint|prtg|catchpoint'
            // Security scanners / misc / probes
            . '|masscan|nmap|nikto|zgrab|netcraft|qwantify|mojeekbot|duckduckgo-favicons-bot|wpscan|sqlmap|acunetix|nessus|qualys|burp\s?suite|w3af|netsparker'
            // Headless / automated-browser signatures sometimes used as full UA
            . '|headlesschrome|chrome-headless|jsdom|axe-core|google\s?page\s?speed|lighthouse|pagespeed|search\s?console|chrome-privacy-preserving-prefetch'
            . '/i';

        return $cache[$ua] = (bool) preg_match($pattern, $ua);
    }

    /**
     * Return a matomo/device-detector DeviceDetector instance for the given UA,
     * or null if the library cannot be loaded at all.
     *
     * Library resolution order:
     *   1. matomo/device-detector already loaded (some other module / autoloader)
     *   2. Site-wide Composer autoloader  (/site/vendor/ or project root /vendor/)
     *   3. Bundled copy under /site/modules/NativeAnalytics/lib/
     *
     * The first source that successfully exposes the class wins. Result is
     * cached per UA per request.
     */
    protected function getDeviceDetector($ua) {
        static $autoloaded = null;
        static $available = null;
        static $cache = [];

        if(isset($cache[$ua])) return $cache[$ua];

        if($autoloaded === null) {
            $autoloaded = true;

            // 1) Already loaded?
            if(!class_exists('DeviceDetector\\DeviceDetector')) {
                // 2) Site-wide Composer autoloader (preferred — gets bug fixes via composer update)
                $composerCandidates = [
                    $this->wire('config')->paths->site . 'vendor/autoload.php',
                    $this->wire('config')->paths->root . 'vendor/autoload.php',
                ];
                foreach($composerCandidates as $path) {
                    if(is_file($path)) {
                        try { require_once $path; } catch(\Throwable $e) {}
                        if(class_exists('DeviceDetector\\DeviceDetector')) break;
                    }
                }
            }

            // 3) Bundled fallback shipped with the module
            if(!class_exists('DeviceDetector\\DeviceDetector')) {
                $bundled = __DIR__ . '/lib/bootstrap.php';
                if(is_file($bundled)) {
                    try { require_once $bundled; } catch(\Throwable $e) {}
                }
            }

            $available = class_exists('DeviceDetector\\DeviceDetector');
        }

        if(!$available) return $cache[$ua] = null;

        try {
            $dd = new \DeviceDetector\DeviceDetector($ua);
            if(method_exists($dd, 'skipBotDetection')) {
                $dd->skipBotDetection(false);
            }
            return $cache[$ua] = $dd;
        } catch(\Throwable $e) {
            return $cache[$ua] = null;
        }
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

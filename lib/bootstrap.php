<?php

/**
 * NativeAnalytics — bundled library bootstrap.
 *
 * Registers a PSR-4 autoloader for the bundled copies of:
 *   - matomo/device-detector  (DeviceDetector\* namespace)
 *   - mustangostang/spyc      (Spyc class, global namespace)
 *
 * This file is included only as a FALLBACK when the host site does not
 * already provide these libraries via Composer. NativeAnalytics first probes
 * site-wide /vendor/autoload.php and /site/vendor/autoload.php; if either of
 * those already exposes DeviceDetector\DeviceDetector, this bootstrap is
 * never loaded.
 *
 * The autoloader is namespaced to NativeAnalytics so it never collides with
 * another autoloader on the same site.
 */

if(!class_exists('NativeAnalyticsBundledAutoloader', false)) {

    final class NativeAnalyticsBundledAutoloader {

        /** @var string */
        private $libRoot;

        public function __construct($libRoot) {
            $this->libRoot = rtrim($libRoot, '/\\');
        }

        public function register() {
            spl_autoload_register([$this, 'loadClass']);
        }

        public function loadClass($class) {
            // DeviceDetector\* → lib/matomo-device-detector/<rest>.php
            if(strncmp($class, 'DeviceDetector\\', 15) === 0) {
                $relative = substr($class, 15);
                $path = $this->libRoot . '/matomo-device-detector/'
                    . str_replace('\\', '/', $relative) . '.php';
                if(is_file($path)) {
                    require_once $path;
                }
                return;
            }

            // Spyc (global namespace, no \) → lib/spyc/Spyc.php
            if($class === 'Spyc') {
                $path = $this->libRoot . '/spyc/Spyc.php';
                if(is_file($path)) {
                    require_once $path;
                }
                return;
            }
        }
    }
}

// Register exactly once per request, regardless of how many times this file is included.
if(empty($GLOBALS['__NATIVEANALYTICS_BUNDLED_LOADED'])) {
    $GLOBALS['__NATIVEANALYTICS_BUNDLED_LOADED'] = true;
    $loader = new NativeAnalyticsBundledAutoloader(__DIR__);
    $loader->register();
}

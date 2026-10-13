<?php namespace ProcessWire;

if(!class_exists(__NAMESPACE__ . '\\NativeAnalyticsDeviceDetectorCache', false)) {

    /**
     * Persistent cache for matomo/device-detector's parsed regex lists.
     *
     * Without one, DeviceDetector falls back to its StaticCache, which lives only as long as
     * the PHP process's request: every tracking beacon re-parses ~2.6 MB of YAML with a
     * pure-PHP parser, about 370ms, before matching a user agent in a few ms. Each entry is
     * stored once as a PHP file returning its value, so OPcache keeps it in shared memory and
     * a request loads it almost for free. DeviceDetector's cache keys carry its version, so
     * an upgraded library starts a fresh set of files.
     *
     * Writes go to a temporary name and are renamed into place, so a request never includes
     * a half-written file while another request is filling the cache.
     */
    class NativeAnalyticsDeviceDetectorCache implements \DeviceDetector\Cache\CacheInterface {

        /** @var string */
        protected $dir;

        public function __construct($dir) {
            $this->dir = rtrim((string) $dir, '/') . '/';
        }

        protected function path($id) {
            return $this->dir . md5((string) $id) . '.php';
        }

        public function fetch(string $id) {
            $path = $this->path($id);
            if(!is_file($path)) return false;
            try {
                return include $path;
            } catch(\Throwable $e) {
                return false;
            }
        }

        public function contains(string $id): bool {
            return is_file($this->path($id));
        }

        public function save(string $id, $data, int $lifeTime = 0): bool {
            if(!is_dir($this->dir) && !wireMkdir($this->dir, true)) return false;
            $path = $this->path($id);
            $tmp = $path . '.' . getmypid() . '.tmp';
            if(file_put_contents($tmp, '<?php return ' . var_export($data, true) . ';' . "\n") === false) return false;
            if(!rename($tmp, $path)) {
                @unlink($tmp);
                return false;
            }
            wireChmod($path);
            return true;
        }

        public function delete(string $id): bool {
            $path = $this->path($id);
            return !is_file($path) || unlink($path);
        }

        public function flushAll(): bool {
            foreach(glob($this->dir . '*.php') ?: [] as $file) @unlink($file);
            return true;
        }
    }
}

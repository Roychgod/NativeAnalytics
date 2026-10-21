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
     *
     * Requires device-detector 5.0+ (typed CacheInterface). NativeAnalytics checks this via
     * NativeAnalytics::getDeviceDetectorCache() BEFORE this file is loaded, so an older
     * Composer-provided library never hits an incompatible class declaration.
     *
     * Originally contributed by adrianbj (PR #22).
     */
    class NativeAnalyticsDeviceDetectorCache implements \DeviceDetector\Cache\CacheInterface {

        /** @var string */
        protected $dir;

        /** @var bool|null Whether the directory is usable for writes (resolved lazily, once). */
        protected $writable = null;

        public function __construct($dir) {
            $this->dir = rtrim((string) $dir, '/\\') . '/';
        }

        public function getDirectory() {
            return $this->dir;
        }

        protected function path($id) {
            return $this->dir . md5((string) $id) . '.php';
        }

        public function fetch(string $id) {
            $path = $this->path($id);
            if(!is_file($path)) return false;
            try {
                $data = include $path;
            } catch(\Throwable $e) {
                // Corrupt or unreadable entry: drop it so the next save can replace it.
                @unlink($path);
                return false;
            }
            return $data;
        }

        public function contains(string $id): bool {
            return is_file($this->path($id));
        }

        public function save(string $id, $data, int $lifeTime = 0): bool {
            if(!$this->isWritable()) return false;
            $path = $this->path($id);
            $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
            $code = '<?php return ' . var_export($data, true) . ';' . "\n";
            if(@file_put_contents($tmp, $code, LOCK_EX) === false) {
                @unlink($tmp);
                $this->writable = false; // disk full / permissions: stop retrying this request
                return false;
            }
            if(!@rename($tmp, $path)) {
                @unlink($tmp);
                return false;
            }
            wireChmod($path);
            if(function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
            return true;
        }

        public function delete(string $id): bool {
            $path = $this->path($id);
            return !is_file($path) || @unlink($path);
        }

        public function flushAll(): bool {
            if(!is_dir($this->dir)) return true;
            $ok = true;
            $files = array_merge(glob($this->dir . '*.php') ?: [], glob($this->dir . '*.tmp') ?: []);
            foreach($files as $file) {
                if(is_file($file) && !@unlink($file)) $ok = false;
            }
            return $ok;
        }

        protected function isWritable() {
            if($this->writable === null) {
                if(!is_dir($this->dir)) @wireMkdir($this->dir, true);
                $this->writable = is_dir($this->dir) && is_writable($this->dir);
            }
            return $this->writable;
        }
    }
}

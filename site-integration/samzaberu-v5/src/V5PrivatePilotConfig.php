<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5ParallelHttpEntry.php';

use Closure;
use RuntimeException;
use Throwable;

/**
 * Private, V5-only pilot configuration on the existing site. No DB connection,
 * native bootstrap, credentials issued, or writes by loading this file.
 * A pilot key admits a REQUEST, never authenticates an ACCOUNT. The real
 * factory must still enforce every existing password/factor/session check.
 * This is trusted deployment configuration, not a sandbox for hostile PHP.
 */
final readonly class V5PrivatePilotConfig
{
    private function __construct(
        private string $directory,
        private string $configHash,
        private string $factoryHash,
        private string $keyDigest,
        private int $expiresAt,
        private array $peers,
        private int $owner,
    ) {}

    /** Paths come ONLY from a fixed V5 shim, never Host, headers or JSON. */
    public static function load(string $directory, string $documentRoot): ?self
    {
        try {
            clearstatcache();
            $directory = rtrim($directory, '/');
            $documentRoot = rtrim($documentRoot, '/');
            if ($directory === '' || $documentRoot === ''
                || $directory[0] !== '/' || $documentRoot[0] !== '/'
                || realpath($directory) !== $directory
                || realpath($documentRoot) !== $documentRoot
                || !is_dir($documentRoot)
                || $directory === $documentRoot
                || str_starts_with($directory, $documentRoot . '/')) return null;
            $dir = @lstat($directory);
            // Private dir: no world access or group/world write. The deployment
            // must ALSO check ancestors, aliases, ownership and open_basedir.
            if (!is_array($dir) || ($dir['mode'] & 0170000) !== 0040000
                || ($dir['mode'] & 0027) !== 0) return null;
            $owner = $dir['uid'];
            $config = $directory . '/site-config.json';
            $factory = $directory . '/site-factory.php';
            if (!self::safeFile($config, $owner, 8192)) return null;
            $raw = @file_get_contents($config, false, null, 0, 8193);
            if (!is_string($raw) || $raw === '' || strlen($raw) > 8192) return null;
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) return null;
            $keys = array_keys($data); sort($keys);
            if ($keys !== ['allowed_peer_ips','expires_at','mode','pilot_key_sha256']
                || ($data['mode'] ?? null) !== 'pilot'
                || !is_string($data['pilot_key_sha256'])
                || preg_match('/\A[a-f0-9]{64}\z/D', $data['pilot_key_sha256']) !== 1
                || !is_int($data['expires_at']) || $data['expires_at'] <= time()
                || $data['expires_at'] > time() + 86400
                || !is_array($data['allowed_peer_ips']) || !array_is_list($data['allowed_peer_ips'])
                || count($data['allowed_peer_ips']) < 1 || count($data['allowed_peer_ips']) > 16
                || !self::safeFile($factory, $owner, 262144)) return null;
            $peers = [];
            foreach ($data['allowed_peer_ips'] as $peer) {
                if (!is_string($peer) || filter_var($peer, FILTER_VALIDATE_IP) === false) return null;
                $binary = inet_pton($peer);
                if (!is_string($binary) || in_array($binary, $peers, true)) return null;
                $peers[] = $binary;
            }
            $factoryHash = @hash_file('sha256', $factory);
            if (!is_string($factoryHash)) return null;
            return new self($directory, hash('sha256', $raw), $factoryHash,
                $data['pilot_key_sha256'], $data['expires_at'], $peers, $owner);
        } catch (Throwable) {
            return null; // No path/config/exception content in a public response.
        }
    }

    public function permitsRequest(array $server): bool
    {
        $key = $server['HTTP_X_EVRASIA_V5_PILOT'] ?? null;
        $peer = $server['REMOTE_ADDR'] ?? null;
        $https = $server['HTTPS'] ?? null;
        if (!is_string($https) || !in_array(strtolower($https), ['on','1'], true)
            || !is_string($key) || preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1
            || !is_string($peer) || filter_var($peer, FILTER_VALIDATE_IP) === false
            || !hash_equals($this->keyDigest, hash('sha256', $key))
            || !in_array(inet_pton($peer), $this->peers, true)) return false;
        // Forwarded/X-Forwarded-For are deliberately ignored. Behind a proxy,
        // the pilot key remains mandatory; an allowed shared proxy is NOT a user.
        return $this->unchangedAndCurrent();
    }

    /** Only the admitted V5 entry may invoke this after body validation. */
    public function transportFactory(): Closure
    {
        return function (): V5HttpTransportBoundary {
            if (!$this->unchangedAndCurrent()) throw new RuntimeException('v5_private_config_unavailable');
            // Isolated PHP scope, fixed filename. No include path from a client.
            // This reviewed file returns the fully wired EXISTING transport;
            // merely inspecting config never executes its native composition.
            $transport = (static function (string $path): mixed {
                return require $path;
            })($this->directory . '/site-factory.php');
            if (!$transport instanceof V5HttpTransportBoundary)
                throw new RuntimeException('v5_private_factory_unavailable');
            return $transport;
        };
    }

    private function unchangedAndCurrent(): bool
    {
        try {
            clearstatcache();
            $dir = @lstat($this->directory);
            if (time() >= $this->expiresAt || !is_array($dir)
                || realpath($this->directory) !== $this->directory
                || ($dir['mode'] & 0170000) !== 0040000
                || $dir['uid'] !== $this->owner || ($dir['mode'] & 0027) !== 0) return false;
            foreach (['site-config.json' => [$this->configHash,8192],
                'site-factory.php' => [$this->factoryHash,262144]] as $name => [$hash,$max]) {
                $path = $this->directory . '/' . $name;
                if (!self::safeFile($path, $this->owner, $max)
                    || @hash_file('sha256', $path) !== $hash) return false;
            }
            return true;
        } catch (Throwable) { return false; }
    }

    private static function safeFile(string $path, int $owner, int $max): bool
    {
        clearstatcache(true, $path);
        $s = @lstat($path);
        return is_array($s) && ($s['mode'] & 0170000) === 0100000
            && ($s['mode'] & 0027) === 0 && $s['uid'] === $owner
            && $s['nlink'] === 1 && $s['size'] > 0 && $s['size'] <= $max
            && realpath($path) === $path && is_readable($path);
    }
}

<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

final class CredentialVault
{
    private const KEY_BYTES = 32;
    private const CIPHER = 'aes-256-gcm';

    /** @return array<int, array<string, string>> */
    public function listCredentials(): array
    {
        return $this->withLock(LOCK_SH, function (): array {
            return array_map(static function (array $credential): array {
                unset($credential['secret']);
                return $credential;
            }, $this->readVault());
        });
    }

    /** @param array<string, string> $input */
    public function saveCredential(array $input): array
    {
        return $this->withLock(LOCK_EX, function () use ($input): array {
            $credentials = $this->readVault();
            $id = trim($input['id'] ?? '');
            $existingIndex = null;
            foreach ($credentials as $index => $credential) {
                if (($credential['id'] ?? '') === $id && $id !== '') {
                    $existingIndex = $index;
                    break;
                }
            }

            $existing = $existingIndex !== null ? $credentials[$existingIndex] : [];
            $secret = trim($input['secret'] ?? '');
            if ($secret === '') {
                $secret = (string) ($existing['secret'] ?? '');
            }

            $name = trim($input['name'] ?? (string) ($existing['name'] ?? ''));
            $registry = self::normalizeRegistry($input['registry'] ?? (string) ($existing['registry'] ?? ''));
            $username = trim($input['username'] ?? (string) ($existing['username'] ?? ''));
            $provider = strtolower(trim($input['provider'] ?? (string) ($existing['provider'] ?? 'generic')));
            // Auth provenance is set at creation time (manual entry vs. OAuth device flow)
            // and must not be overridable by a client-supplied field on update.
            $authMethod = strtolower((string) ($existing['authMethod'] ?? trim($input['authMethod'] ?? 'manual')));
            if ($name === '' || $registry === '' || $username === '' || $secret === '') {
                throw new InvalidArgumentException('名称、仓库地址、用户名和令牌均为必填。');
            }
            if (!in_array($provider, ['github', 'docker', 'gitlab', 'quay', 'aws', 'azure', 'gcr', 'generic', 'git', 'git-ssh'], true)) {
                throw new InvalidArgumentException('不支持的凭据提供方。');
            }
            // A stack using the credential would break at its next run: a git stack needs a git
            // credential, and a registry login is never a git one. Changes among registry kinds
            // (generic to docker, say) stay allowed.
            $existingProvider = (string) ($existing['provider'] ?? '');
            $gitKinds = ['git', 'git-ssh'];
            if ($existing !== [] && $existingProvider !== $provider
                && (in_array($existingProvider, $gitKinds, true) || in_array($provider, $gitKinds, true))) {
                throw new InvalidArgumentException('凭据不能在“注册表登录”与“git 凭据”之间切换，请改为新建凭据。');
            }
            // A git repository credential (an HTTPS token, or a git stack's ssh deploy key in
            // "git-ssh") is for one host, such as github.com.
            if (($provider === 'git' || $provider === 'git-ssh') && preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]{1,5})?$/', $registry) !== 1) {
                throw new InvalidArgumentException('git 凭据只需填写仓库主机地址，例如 github.com。');
            }
            if (!in_array($authMethod, ['manual', 'oauth_device'], true)) {
                throw new InvalidArgumentException('不支持的凭据认证方式。');
            }

            $now = gmdate('c');
            $credential = [
                // Only an id that matched an existing record is honored; otherwise the
                // vault always generates the id so clients cannot choose their own.
                'id' => $existingIndex !== null ? $id : bin2hex(random_bytes(16)),
                'name' => $name,
                'provider' => $provider,
                'authMethod' => $authMethod,
                'registry' => $registry,
                'username' => $username,
                'secret' => $secret,
                'createdAt' => (string) ($existing['createdAt'] ?? $now),
                'updatedAt' => $now,
            ];


            if ($existingIndex === null) {
                $credentials[] = $credential;
            } else {
                $credentials[$existingIndex] = $credential;
            }
            $this->writeVault($credentials);

            unset($credential['secret']);
            return $credential;
        });
    }

    public function deleteCredential(string $id): bool
    {
        return $this->withLock(LOCK_EX, function () use ($id): bool {
            $credentials = $this->readVault();
            $filtered = array_values(array_filter($credentials, static fn(array $credential): bool => ($credential['id'] ?? '') !== $id));
            if (count($filtered) === count($credentials)) {
                return false;
            }
            $this->writeVault($filtered);
            return true;
        });
    }

    /** @param callable $callback @return mixed */
    public static function withCredentialAssignmentLock(callable $callback)
    {
        $lockDir = dirname(COMPOSE_CREDENTIAL_VAULT_FILE);
        if (!is_dir($lockDir) && !mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
            throw new RuntimeException('无法创建凭据存储目录。');
        }
        $handle = fopen($lockDir . '/credential-assignments.lock', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('无法获取凭据分配锁。');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function hasCredential(string $id): bool
    {
        if ($id === '') {
            return false;
        }
        return $this->withLock(LOCK_SH, function () use ($id): bool {
            foreach ($this->readVault() as $credential) {
                if (($credential['id'] ?? '') === $id) {
                    return true;
                }
            }
            return false;
        });
    }

    /** @return array<string, string> */
    public function getCredentialSummary(string $id): array
    {
        return $this->withLock(LOCK_SH, function () use ($id): array {
            $credential = $this->findCredential($id);
            unset($credential['secret']);
            return $credential;
        });
    }

    /**
     * Verifies a credential still authenticates against its registry using the
     * standard Docker Registry v2 handshake. Never throws; failures are returned
     * as an invalid result so the UI can surface a renewal prompt.
     * @return array{id: string, name: string, registry: string, valid: bool, message: string}
     */
    public function testCredential(string $id): array
    {
        $credential = $this->withLock(LOCK_SH, fn(): array => $this->findCredential($id));
        [$valid, $message] = self::checkRegistryAuth($credential['registry'], $credential['username'], $credential['secret']);
        return [
            'id' => $credential['id'],
            'name' => $credential['name'],
            'registry' => $credential['registry'],
            'valid' => $valid,
            'message' => $message,
        ];
    }

    /**
     * Hand one credential, secret included, to a callback, and return what it returns.
     *
     * The one place code reads a secret out of the vault, for code that has to pass it on
     * to another program: git stacks write it to a short-lived file and remove it after
     * the run. Whatever the callback returns, the secret included, goes back to the caller.
     *
     * @template T
     * @param callable(array<string, string>): T $callback
     * @return T
     */
    public function useCredential(string $id, callable $callback)
    {
        $credential = $this->withLock(LOCK_SH, fn(): array => $this->findCredential($id));
        return $callback($credential);
    }

    public function materializeDockerConfig(string $id): string
    {
        $credential = $this->withLock(LOCK_SH, fn(): array => $this->findCredential($id));
        // A git token or deploy key is never a registry login. Stack settings refuse to assign one, but a
        // hand-edited or restored credential_id could still name one: never write it out as a Docker login.
        if (in_array($credential['provider'] ?? '', ['git', 'git-ssh'], true)) {
            throw new RuntimeException("The credential '{$credential['name']}' is a git repository credential, not a registry login.");
        }
        // Confirms which named credential a compose operation is using, without ever logging the secret.
        composeLogger(
            "Using registry credential '{$credential['name']}' ({$credential['registry']}) for this operation",
            null,
            'user',
            'info',
            'credential'
        );
        $baseDir = rtrim(COMPOSE_DOCKER_CONFIG_DIR, '/');
        if (!is_dir($baseDir) && !mkdir($baseDir, 0700, true) && !is_dir($baseDir)) {
            throw new RuntimeException('无法创建 Docker 凭据目录。');
        }
        chmod($baseDir, 0700);
        self::sweepStaleDockerConfigs($baseDir);

        $directory = $baseDir . '/' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('无法创建临时 Docker 配置。');
        }

        $auth = base64_encode($credential['username'] . ':' . $credential['secret']);
        // Merge onto the operator's existing Docker CLI config so other registries'
        // auths and proxy settings still apply during the run. Docker's configured
        // credential stores take precedence over inline auths, so remove the global
        // store and this registry's helper from the temporary config.
        $config = self::loadExistingDockerConfig();
        unset($config['credsStore']);
        if (isset($config['credHelpers']) && is_array($config['credHelpers'])) {
            unset($config['credHelpers'][$credential['registry']]);
        }
        if (!isset($config['auths']) || !is_array($config['auths'])) {
            $config['auths'] = [];
        }
        $config['auths'][$credential['registry']] = ['auth' => $auth];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($directory . '/config.json', $json, LOCK_EX) === false) {
            @rmdir($directory);
            throw new RuntimeException('无法写入临时 Docker 配置。');
        }
        chmod($directory . '/config.json', 0600);
        return $directory;
    }

    /** @return array<string, mixed> */
    private static function loadExistingDockerConfig(): array
    {
        $configDir = getenv('DOCKER_CONFIG');
        $existingPath = $configDir !== false && $configDir !== ''
            ? rtrim($configDir, '/') . '/config.json'
            : rtrim((string) (getenv('HOME') ?: '/root'), '/') . '/.docker/config.json';
        if (!is_file($existingPath)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($existingPath), true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function removeDockerConfig(string $directory): void
    {
        $baseDir = realpath(COMPOSE_DOCKER_CONFIG_DIR);
        $target = realpath($directory);
        if ($baseDir === false || $target === false || dirname($target) !== $baseDir) {
            return;
        }
        @unlink($target . '/config.json');
        @rmdir($target);
    }

    /**
     * Remove orphaned Docker config directories left behind by crashed or killed
     * compose operations that never reached their cleanup trap.
     */
    private static function sweepStaleDockerConfigs(string $baseDir, int $maxAgeSeconds = 3600): void
    {
        foreach (glob($baseDir . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (self::isDockerConfigInUse($directory)) {
                continue;
            }
            $mtime = @filemtime($directory);
            if ($mtime !== false && (time() - $mtime) > $maxAgeSeconds) {
                self::removeDockerConfig($directory);
            }
        }
    }

    private static function isDockerConfigInUse(string $directory): bool
    {
        foreach (glob('/proc/[0-9]*/environ') ?: [] as $environmentFile) {
            $environment = @file_get_contents($environmentFile);
            if ($environment !== false && preg_match(
                '/(?:^|\0)DOCKER_CONFIG=' . preg_quote($directory, '/') . '(?:\0|$)/',
                $environment
            ) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function normalizeRegistry(string $registry): string
    {
        $registry = strtolower(trim($registry));
        $registry = preg_replace('#^https?://#', '', $registry) ?? '';
        $registry = rtrim($registry, '/');
        if ($registry === 'docker.io' || $registry === 'registry-1.docker.io') {
            return 'https://index.docker.io/v1/';
        }
        if ($registry === '' || preg_match('/[\s?#]/', $registry)) {
            return '';
        }
        return $registry;
    }

    /**
     * Performs the standard Docker Registry v2 auth handshake: ping /v2/, and if
     * challenged with a Bearer realm, request a token using the credential.
     * @return array{0: bool, 1: string}
     */
    private static function checkRegistryAuth(string $registry, string $username, string $secret): array
    {
        $host = $registry === 'https://index.docker.io/v1/' ? 'registry-1.docker.io' : $registry;
        if ($host === '') {
            return [false, '该凭据未配置注册表主机。'];
        }
        if (!function_exists('curl_init')) {
            return [false, 'Unable to verify credential: the PHP curl extension is unavailable.'];
        }

        $ping = self::registryRequest('https://' . $host . '/v2/', $username, $secret);
        if ($ping['status'] === 200) {
            return [true, '注册表已接受该凭据。'];
        }
        if ($ping['status'] === 401 && $ping['authHeader'] !== '') {
            $challenge = self::parseBearerChallenge($ping['authHeader']);
            if ($challenge === null) {
                return [false, '注册表返回了无法识别的身份验证质询。'];
            }
            $query = array_filter([
                'service' => $challenge['service'] ?? null,
                'scope' => $challenge['scope'] ?? null,
            ]);
            $tokenUrl = $challenge['realm'] . ($query ? '?' . http_build_query($query) : '');
            $tokenResponse = self::registryRequest($tokenUrl, $username, $secret);
            if ($tokenResponse['status'] === 200) {
                return [true, '注册表已接受该凭据。'];
            }
            if ($tokenResponse['status'] === 401 || $tokenResponse['status'] === 403) {
                return [false, 'Registry rejected the credential (invalid or expired token).'];
            }
            return [false, 'Unable to verify credential (auth service returned HTTP ' . $tokenResponse['status'] . ').'];
        }
        if ($ping['status'] === 401 || $ping['status'] === 403) {
            return [false, 'Registry rejected the credential (invalid or expired token).'];
        }
        if ($ping['status'] === 0) {
            return [false, 'Could not reach the registry: ' . $ping['error']];
        }
        return [false, 'Unable to verify credential (registry returned HTTP ' . $ping['status'] . ').'];
    }

    /** @return array{status: int, authHeader: string, error: string} */
    private static function registryRequest(string $url, string $username, string $secret): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERPWD => $username . ':' . $secret,
            CURLOPT_HTTPHEADER => ['User-Agent: Compose-Manager-Credential-Test'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        $authHeader = '';
        if (is_string($response) && preg_match('/^www-authenticate:\s*(.+)$/mi', $response, $matches)) {
            $authHeader = trim($matches[1]);
        }
        return ['status' => $status, 'authHeader' => $authHeader, 'error' => $error];
    }

    /** @return array{realm: string, service?: string, scope?: string}|null */
    private static function parseBearerChallenge(string $header): ?array
    {
        if (stripos($header, 'Bearer') !== 0) {
            return null;
        }
        $params = [];
        if (preg_match_all('/(realm|service|scope)="([^"]*)"/i', $header, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $params[strtolower($match[1])] = $match[2];
            }
        }
        if (empty($params['realm'])) {
            return null;
        }
        return $params;
    }

    /** @return array<string, string> */
    private function findCredential(string $id): array
    {
        foreach ($this->readVault() as $credential) {
            if (($credential['id'] ?? '') === $id) {
                return $credential;
            }
        }
        throw new RuntimeException('所选凭据已不存在。');
    }

    /** @return array<int, array<string, string>> */
    private function readVault(): array
    {
        if (!is_file(COMPOSE_CREDENTIAL_VAULT_FILE)) {
            return [];
        }
        $payload = json_decode((string) file_get_contents(COMPOSE_CREDENTIAL_VAULT_FILE), true);
        if (!is_array($payload) || !isset($payload['ciphertext'])) {
            throw new RuntimeException('凭据库无效。');
        }
        $ciphertext = base64_decode((string) $payload['ciphertext'], true);
        if ($ciphertext === false) {
            throw new RuntimeException('凭据库无效。');
        }

        if (($payload['algorithm'] ?? '') === self::CIPHER) {
            $iv = base64_decode((string) ($payload['iv'] ?? ''), true);
            $tag = base64_decode((string) ($payload['tag'] ?? ''), true);
            if ($iv === false || $tag === false || !function_exists('openssl_decrypt')) {
                throw new RuntimeException('凭据库无效。');
            }
            $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->loadKey(), OPENSSL_RAW_DATA, $iv, $tag);
        } else {
            $nonce = base64_decode((string) ($payload['nonce'] ?? ''), true);
            if ($nonce === false || !function_exists('sodium_crypto_secretbox_open')) {
                throw new RuntimeException('旧版凭据库需要 PHP sodium 扩展。');
            }
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->loadKey());
        }
        if (!is_string($plaintext)) {
            throw new RuntimeException('凭据库无法解密。');
        }
        $credentials = json_decode($plaintext, true);
        return is_array($credentials) ? array_values($credentials) : [];
    }

    /** @param array<int, array<string, string>> $credentials */
    private function writeVault(array $credentials): void
    {
        $directory = dirname(COMPOSE_CREDENTIAL_VAULT_FILE);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建凭据存储目录。');
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('凭据加密需要 PHP OpenSSL 扩展。');
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) {
            throw new RuntimeException('凭据加密不可用。');
        }
        $iv = random_bytes($ivLength);
        $plaintext = json_encode(array_values($credentials), JSON_UNESCAPED_SLASHES);
        if ($plaintext === false) {
            throw new RuntimeException('无法编码凭据库。');
        }
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->loadKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('无法加密凭据库。');
        }
        $payload = json_encode([
            'version' => 2,
            'algorithm' => self::CIPHER,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('无法编码凭据库。');
        }
        self::atomicWrite(COMPOSE_CREDENTIAL_VAULT_FILE, $payload, 0600, '无法保存凭据库。');
    }

    private function loadKey(): string
    {
        if (is_file(COMPOSE_CREDENTIAL_KEY_FILE)) {
            $key = base64_decode(trim((string) file_get_contents(COMPOSE_CREDENTIAL_KEY_FILE)), true);
            if ($key !== false && strlen($key) === self::KEY_BYTES) {
                return $key;
            }
            throw new RuntimeException('凭据加密密钥无效。');
        }
        $directory = dirname(COMPOSE_CREDENTIAL_KEY_FILE);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建凭据存储目录。');
        }
        $key = random_bytes(self::KEY_BYTES);
        self::atomicWrite(COMPOSE_CREDENTIAL_KEY_FILE, base64_encode($key), 0600, '无法保存凭据加密密钥。');
        return $key;
    }

    /**
     * Write via a same-directory temp file + rename so a crash or power loss
     * mid-write cannot corrupt the existing vault/key file.
     */
    private static function atomicWrite(string $path, string $contents, int $mode, string $errorMessage): void
    {
        $tmpPath = $path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($tmpPath, 'x+b');
        if ($handle === false) {
            throw new RuntimeException($errorMessage);
        }

        try {
            // Set permissions before writing any secret bytes. The exclusive
            // create prevents collisions with another writer's temporary file.
            if (!@chmod($tmpPath, $mode)) {
                throw new RuntimeException($errorMessage);
            }
            $length = strlen($contents);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException($errorMessage);
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException($errorMessage);
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($tmpPath);
            throw new RuntimeException($errorMessage, 0, $error);
        }

        if (!fclose($handle)) {
            @unlink($tmpPath);
            throw new RuntimeException($errorMessage);
        }
        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException($errorMessage);
        }
    }

    /**
     * Execute a callback while holding a lock on the credential vault lockfile.
     *
     * @template T
     * @param int $lockType LOCK_SH or LOCK_EX
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(int $lockType, callable $callback)
    {
        $lockDir = dirname(COMPOSE_CREDENTIAL_VAULT_FILE);
        if (!is_dir($lockDir) && !mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
            throw new RuntimeException('无法创建凭据存储目录。');
        }
        $lockFile = $lockDir . '/credentials.lock';
        $handle = fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException('无法打开凭据锁。');
        }
        if (!flock($handle, $lockType)) {
            fclose($handle);
            throw new RuntimeException('无法获取凭据锁。');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
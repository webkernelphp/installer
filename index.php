<?php declare(strict_types=1);

/**
 * Webkernel Installer
 *
 * Single-file installer for the Webkernel framework.
 * Operates in both HTTP (browser) and CLI modes without code duplication.
 *
 * @package    webkernel/installer
 * @repository https://github.com/webkernelphp/webkernel
 * @packagist  https://packagist.org/packages/webkernel/webkernel
 * @license    Webkernel Unified License + EPL Eclipse v2
 * @requires   PHP 8.4+
 */

 /* --- Installer version & Webkernel codenames -------------------------------*/

const WEBKERNEL_INSTALLER_VERSION = '0.1.0';

/* --- Bootstrap: enforce PHP version before any class declarations -----------*/

if (PHP_VERSION_ID < 80400) {
    $msg = sprintf('Webkernel requires PHP 8.4+. Found: %s', PHP_VERSION);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, '[ERROR] ' . $msg . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $msg;
    exit(1);
}


/**
 * Returns the codename series for a given Webkernel major version.
 *
 * @param  int    $major  Major version number extracted from a semver string.
 * @return string         Human-readable codename series.
 */
function webkernelCodename(int $major): string
{
    return match ($major) {
        1       => 'Waterfall',
        2       => 'Greenfields',
        3       => 'Forester',
        4       => 'Wildlife',
        5       => 'Universe',
        default => 'Unknown',
    };
}

/**
 * Attempts to read the installed Webkernel package version from composer.lock
 * or the package composer.json inside vendor.
 *
 * @param  string      $targetDirectory  Absolute path to the installation target.
 * @return string|null                   Semver string or null when not determinable.
 */
function resolveWebkernelVersion(string $targetDirectory): ?string
{
    $lockFile = rtrim($targetDirectory, '/') . '/composer.lock';
    if (is_file($lockFile)) {
        $lock = json_decode((string) file_get_contents($lockFile), true);
        if (is_array($lock)) {
            foreach ((array) ($lock['packages'] ?? []) as $pkg) {
                if (($pkg['name'] ?? '') === 'webkernel/webkernel') {
                    $v = ltrim((string) ($pkg['version'] ?? ''), 'v');
                    return $v !== '' ? $v : null;
                }
            }
        }
    }
    return null;
}

/**
 * Strip ANSI escape sequences from a string.
 * Defined as a global function — callable from any class or context.
 *
 * @param  string $text  Raw text potentially containing ANSI codes.
 * @return string        Clean text.
 */
function stripAnsi(string $text): string
{
    return (string) preg_replace('/\x1B\[[0-9;]*[A-Za-z]|\x1B\[[0-9]*[A-Za-z]|\x1B\].*?\x07/u', '', $text);
}

/**
 * Detect whether Webkernel is already installed in a given directory.
 * Checks for the canonical markers that are only present after a successful install.
 *
 * @param  string $directory  Absolute path to the target directory.
 * @return bool
 */
function is_webkernel_installed(string $directory): bool
{
    $dir = rtrim($directory, '/');

    // Must have composer.json with correct package name
    $composerJson = $dir . '/composer.json';
    if (!is_file($composerJson)) {
        return false;
    }
    $decoded = json_decode((string) file_get_contents($composerJson), true);
    if (!is_array($decoded) || ($decoded['name'] ?? '') !== 'webkernel/webkernel') {
        return false;
    }

    // Must have vendor autoloader (Composer install completed)
    if (!is_file($dir . '/vendor/autoload.php')) {
        return false;
    }

    // Must have artisan (Laravel framework in place)
    if (!is_file($dir . '/artisan')) {
        return false;
    }

    return true;
}

// ---------------------------------------------------------------------------
// Interfaces
// ---------------------------------------------------------------------------

interface InstallerStageInterface
{
    /** @return non-empty-string */
    public function name(): string;

    /** @return non-empty-string */
    public function label(): string;

    public function execute(InstallerContext $context): StageResult;
}

interface SessionStorageInterface
{
    public function read(string $sessionId): ?InstallerSession;

    public function write(InstallerSession $session): void;

    public function delete(string $sessionId): void;

    public function exists(string $sessionId): bool;
}

interface OutputInterface
{
    public function info(string $message): void;

    public function error(string $message): void;

    public function success(string $message): void;

    public function warning(string $message): void;
}

// ---------------------------------------------------------------------------
// Enums
// ---------------------------------------------------------------------------

enum InstallerPhase: string
{
    case Preflight  = 'preflight';
    case Download   = 'download';
    case Verify     = 'verify';
    case Extract    = 'extract';
    case Configure  = 'configure';
    case Complete   = 'complete';
    case Failed     = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Preflight  => 'Preparation',
            self::Download   => 'Download',
            self::Verify     => 'Verification',
            self::Extract    => 'Extraction',
            self::Configure  => 'Configuration',
            self::Complete   => 'Complete',
            self::Failed     => 'Failed',
        };
    }

    public function ordinal(): int
    {
        return match ($this) {
            self::Preflight  => 0,
            self::Download   => 1,
            self::Verify     => 2,
            self::Extract    => 3,
            self::Configure  => 4,
            self::Complete   => 5,
            self::Failed     => 6,
        };
    }
}

enum StageStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed  = 'failed';
    case Skipped = 'skipped';
}

enum ComposerState: string
{
    case NotFound      = 'not_found';
    case OutdatedLocal = 'outdated_local';
    case PharAvailable = 'phar_available';
    case SystemOk      = 'system_ok';
}

// ---------------------------------------------------------------------------
// Value Objects
// ---------------------------------------------------------------------------

final class InstallPath
{
    private function __construct(
        public readonly string $target,
        public readonly string $userspace,
        public readonly string $sessionBase,
    ) {}

    public static function resolve(string $targetDirectory): self
    {
        $home = InstallerEnvironment::resolveHomeDirectory();
        $hash = hash('sha256', realpath($targetDirectory) ?: $targetDirectory);
        $userspace = $home . '/webkernel/installer/' . $hash;
        return new self(
            target:      $targetDirectory,
            userspace:   $userspace,
            sessionBase: $userspace . '/sessions',
        );
    }

    public function sessionDirectory(string $sessionId): string
    {
        return $this->sessionBase . '/' . $sessionId;
    }

    public function composerPharPath(): string
    {
        return $this->userspace . '/composer.phar';
    }

    public function ensure(): void
    {
        foreach ([$this->userspace, $this->sessionBase] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }
    }
}

final class SecurityToken
{
    private function __construct(
        public readonly string $value,
        public readonly int $createdAt,
    ) {}

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(32)), time()); // @disregard P1010
    }

    public static function fromRaw(string $value, int $createdAt): self
    {
        return new self($value, $createdAt);
    }

    public function verify(string $provided): bool
    {
        return hash_equals($this->value, $provided);
    }

    public function isExpired(int $ttlSeconds = 86400): bool
    {
        return (time() - $this->createdAt) > $ttlSeconds;
    }
}

// ---------------------------------------------------------------------------
// Stage Result
// ---------------------------------------------------------------------------

final class StageResult
{
    /** @param string[] $log */
    private function __construct(
        public readonly StageStatus $status,
        public readonly string $message,
        public readonly array $log = [],
    ) {}

    /** @param string[] $log */
    public static function success(string $message, array $log = []): self
    {
        return new self(StageStatus::Success, $message, $log);
    }

    /** @param string[] $log */
    public static function failure(string $message, array $log = []): self
    {
        return new self(StageStatus::Failed, $message, $log);
    }

    public static function skipped(string $message): self
    {
        return new self(StageStatus::Skipped, $message);
    }
}

// ---------------------------------------------------------------------------
// Installer Session
// ---------------------------------------------------------------------------

final class InstallerSession
{
    /** @param string[] $log @param array<string, mixed> $stageData */
    public function __construct(
        public readonly string $id,
        public InstallerPhase $phase,
        public readonly InstallPath $paths,
        public readonly SecurityToken $token,
        public readonly int $startedAt,
        public array $log,
        public array $stageData,
        public bool $locked,
        public int $lastActivity,
    ) {}

    public static function create(InstallPath $paths): self
    {
        $id = bin2hex(random_bytes(16)); // @disregard P1010
        return new self(
            id:           $id,
            phase:        InstallerPhase::Preflight,
            paths:        $paths,
            token:        SecurityToken::generate(),
            startedAt:    time(),
            log:          [],
            stageData:    [],
            locked:       false,
            lastActivity: time(),
        );
    }

    public function appendLog(string $line): void
    {
        $this->log[] = '[' . date('H:i:s') . '] ' . $line;
        $this->lastActivity = time();
    }

    public function advanceTo(InstallerPhase $phase): void
    {
        $this->phase = $phase;
        $this->lastActivity = time();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'phase'         => $this->phase->value,
            'token'         => $this->token->value,
            'token_at'      => $this->token->createdAt,
            'started_at'    => $this->startedAt,
            'log'           => $this->log,
            'stage_data'    => $this->stageData,
            'locked'        => $this->locked,
            'last_activity' => $this->lastActivity,
            'target'        => $this->paths->target,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, InstallPath $paths): self
    {
        return new self(
            id:           (string) $data['id'],
            phase:        InstallerPhase::from((string) $data['phase']),
            paths:        $paths,
            token:        SecurityToken::fromRaw((string) $data['token'], (int) $data['token_at']),
            startedAt:    (int) $data['started_at'],
            log:          (array) $data['log'],
            stageData:    (array) ($data['stage_data'] ?? []),
            locked:       (bool) $data['locked'],
            lastActivity: (int) $data['last_activity'],
        );
    }
}

// ---------------------------------------------------------------------------
// Session Storage
// ---------------------------------------------------------------------------

final class FilesystemSessionStorage implements SessionStorageInterface
{
    public function read(string $sessionId): ?InstallerSession
    {
        $file = $this->locateFile($sessionId);
        if ($file === null) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $paths = InstallPath::resolve((string) ($data['target'] ?? getcwd()));
        return InstallerSession::fromArray($data, $paths);
    }

    public function write(InstallerSession $session): void
    {
        $dir = $session->paths->sessionDirectory($session->id);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents(
            $dir . '/state.json',
            json_encode($session->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }

    public function delete(string $sessionId): void
    {
        $file = $this->locateFile($sessionId);
        if ($file !== null && is_file($file)) {
            unlink($file);
        }
    }

    public function exists(string $sessionId): bool
    {
        return $this->locateFile($sessionId) !== null;
    }

    private function locateFile(string $sessionId): ?string
    {
        $home = InstallerEnvironment::resolveHomeDirectory();
        $pattern = $home . '/webkernel/installer/*/sessions/' . $sessionId . '/state.json';
        $matches = glob($pattern);
        if (empty($matches)) {
            return null;
        }
        return is_file($matches[0]) ? $matches[0] : null;
    }
}

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------

final class InstallerEnvironment
{
    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    public static function resolveHomeDirectory(): string
    {
        $home = getenv('HOME');
        if ($home !== false && is_string($home) && is_dir($home)) {
            return rtrim($home, '/');
        }
        if (function_exists('posix_getuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_getuid());
            if (is_array($pw) && isset($pw['dir']) && is_dir($pw['dir'])) {
                return rtrim($pw['dir'], '/');
            }
        }
        $user = getenv('USER') ?: 'webkernel';
        foreach (['/home/' . $user, '/var/www/' . $user] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }
        $fallback = sys_get_temp_dir() . '/webkernel-userspace';
        if (!is_dir($fallback)) {
            mkdir($fallback, 0700, true);
        }
        return $fallback;
    }

    public static function resolveTargetDirectory(): string
    {
        if (self::isCli()) {
            global $argv; // @disregard P1008
            if (is_array($argv)) {
                foreach ($argv as $i => $arg) {
                    if ($arg === '--dir' && isset($argv[$i + 1])) {
                        return realpath($argv[$i + 1]) ?: $argv[$i + 1];
                    }
                }
            }
            return getcwd() ?: '/tmp/webkernel';
        }
        // Walk up from common web-root subdirectory names so the installer
        // targets the actual project root, not the public/ folder.
        $scriptDir = dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
        $webRoots  = ['public', 'public_html', 'htdocs', 'www', 'web'];
        if (in_array(strtolower(basename($scriptDir)), $webRoots, true)) {
            $parent = dirname($scriptDir);
            if ($parent !== $scriptDir && is_dir($parent)) {
                return $parent;
            }
        }
        return $scriptDir;
    }

    public static function phpBinary(): string
    {
        $binary = PHP_BINARY;
        if (!empty($binary) && is_executable($binary)) {
            return $binary;
        }
        return 'php';
    }

    public static function resolveComposerState(InstallPath $paths): ComposerState
    {
        // Use proc_open-safe approach: no shell_exec for detection, only which via PATH
        $composerInPath = null;
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, '/') . '/composer';
            if (is_executable($candidate)) {
                $composerInPath = $candidate;
                break;
            }
            $candidate2 = $candidate . '.phar';
            if (is_executable($candidate2)) {
                $composerInPath = $candidate2;
                break;
            }
        }

        if ($composerInPath !== null) {
            $result = SafeProcessRunner::run(
                [InstallerEnvironment::phpBinary(), $composerInPath, '--version', '--no-interaction'],
                null,
                [],
                10,
            );
            if ($result->successful() && str_contains($result->stdout, 'Composer version 2.')) {
                return ComposerState::SystemOk;
            }
            return ComposerState::OutdatedLocal;
        }

        if (is_file($paths->composerPharPath())) {
            return ComposerState::PharAvailable;
        }

        return ComposerState::NotFound;
    }
}

// ---------------------------------------------------------------------------
// Process Runner (proc_open only — no shell_exec / exec)
// ---------------------------------------------------------------------------

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }
}

final class SafeProcessRunner
{
    /**
     * @param string[]             $command
     * @param array<string,string> $env
     * @param callable|null        $outputCallback  fn(string $type, string $chunk): void
     */
    public static function run(
        array $command,
        ?string $cwd = null,
        array $env = [],
        int $timeoutSeconds = 300,
        ?callable $outputCallback = null,
    ): ProcessResult {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envFull = array_merge(getenv() ?: [], $env);

        $process = proc_open($command, $descriptors, $pipes, $cwd, $envFull);

        if (!is_resource($process)) {
            return new ProcessResult(1, '', 'Failed to open process', false);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout   = '';
        $stderr   = '';
        $timedOut = false;
        $deadline = time() + $timeoutSeconds;

        while (true) {
            if (time() > $deadline) {
                proc_terminate($process, 15);
                $timedOut = true;
                break;
            }

            $read   = [$pipes[1], $pipes[2]];
            $write  = null;
            $except = null;
            $ready  = stream_select($read, $write, $except, 0, 200000);

            if ($ready === false) {
                break;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 4096);
                if ($chunk !== false && $chunk !== '') {
                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                        if ($outputCallback !== null) {
                            ($outputCallback)('out', $chunk);
                        }
                    } else {
                        $stderr .= $chunk;
                        if ($outputCallback !== null) {
                            ($outputCallback)('err', $chunk);
                        }
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return new ProcessResult($exitCode, $stdout, $stderr, $timedOut);
    }
}

// ---------------------------------------------------------------------------
// Installer Context
// ---------------------------------------------------------------------------

final class InstallerContext
{
    public function __construct(
        public readonly InstallerSession $session,
        public readonly OutputInterface $output,
    ) {}
}

// ---------------------------------------------------------------------------
// Stages
// ---------------------------------------------------------------------------

final class PreflightStage implements InstallerStageInterface
{
    public function name(): string { return 'preflight'; }
    public function label(): string { return 'Preparation'; }

    public function execute(InstallerContext $context): StageResult
    {
        $log    = [];
        $errors = [];

        // PHP version
        $log[] = 'PHP version: ' . PHP_VERSION;
        if (PHP_VERSION_ID < 80400) {
            $errors[] = 'PHP 8.4+ required, found ' . PHP_VERSION;
        }

        // Required extensions
        foreach (['json', 'zip', 'openssl', 'curl', 'mbstring', 'tokenizer', 'pdo'] as $ext) {
            if (extension_loaded($ext)) {
                $log[] = 'Extension ' . $ext . ': OK';
            } else {
                $errors[] = 'Missing extension: ' . $ext;
                $log[] = 'Extension ' . $ext . ': MISSING';
            }
        }

        // Target directory
        $target = $context->session->paths->target;
        if (is_dir($target) && is_writable($target)) {
            $log[] = 'Target writable: ' . $target;
        } else {
            $errors[] = 'Target directory not writable: ' . $target;
        }

        // Userspace
        try {
            $context->session->paths->ensure();
            $log[] = 'Userspace ready: ' . $context->session->paths->userspace;
        } catch (\Throwable $e) {
            $errors[] = 'Cannot create userspace: ' . $e->getMessage();
        }

        // Composer state
        $composerState = InstallerEnvironment::resolveComposerState($context->session->paths);
        $log[] = 'Composer state: ' . $composerState->value;
        $context->session->stageData['composer_state'] = $composerState->value;

        if (!empty($errors)) {
            return StageResult::failure(
                'Preparation failed: ' . implode('; ', $errors),
                $log,
            );
        }

        return StageResult::success('All pre-flight checks passed.', $log);
    }
}

final class ComposerBootstrapStage implements InstallerStageInterface
{
    public function name(): string { return 'composer_bootstrap'; }
    public function label(): string { return 'Bootstrapping Composer'; }

    public function execute(InstallerContext $context): StageResult
    {
        $log = [];
        $stateValue = (string) ($context->session->stageData['composer_state'] ?? ComposerState::NotFound->value);
        $state = ComposerState::from($stateValue);

        if ($state === ComposerState::SystemOk) {
            $log[] = 'System Composer 2.x found; skipping download.';
            return StageResult::skipped('System Composer 2.x is available.');
        }

        if ($state === ComposerState::PharAvailable) {
            $log[] = 'composer.phar found in userspace; skipping download.';
            $context->session->stageData['composer_phar'] = $context->session->paths->composerPharPath();
            return StageResult::skipped('Existing composer.phar will be used.');
        }

        $pharPath  = $context->session->paths->composerPharPath();
        $log[]     = 'Downloading composer.phar installer...';

        $installerPath = sys_get_temp_dir() . '/composer-setup-' . bin2hex(random_bytes(4)) . '.php';

        $ch = curl_init('https://getcomposer.org/installer');
        if ($ch === false) {
            return StageResult::failure('Could not initialise cURL.', $log);
        }

        $fh = fopen($installerPath, 'wb');
        if ($fh === false) {
            curl_close($ch);
            return StageResult::failure('Could not open temp file for Composer installer.', $log);
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'WebkernelInstaller/1.0',
        ]);
        $downloaded = curl_exec($ch);
        $curlError  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($downloaded === false || !empty($curlError)) {
            @unlink($installerPath);
            return StageResult::failure('cURL error: ' . $curlError, $log);
        }

        $log[] = 'Installer downloaded; running setup...';

        $result = SafeProcessRunner::run(
            [
                InstallerEnvironment::phpBinary(),
                $installerPath,
                '--quiet',
                '--install-dir=' . dirname($pharPath),
                '--filename=composer.phar',
            ],
            null,
            [],
            180,
        );
        @unlink($installerPath);

        if (!$result->successful()) {
            $cleanErr = stripAnsi(trim($result->stderr ?: $result->stdout));
            return StageResult::failure(
                'Composer setup failed: ' . (empty($cleanErr) ? 'exit code ' . $result->exitCode : $cleanErr),
                $log,
            );
        }

        if (!is_file($pharPath)) {
            return StageResult::failure('composer.phar not present after setup.', $log);
        }

        $log[] = 'composer.phar installed at: ' . $pharPath;
        $context->session->stageData['composer_phar'] = $pharPath;
        return StageResult::success('Composer is ready.', $log);
    }
}

final class DownloadStage implements InstallerStageInterface
{
    public function name(): string { return 'download'; }
    public function label(): string { return 'Download'; }

    public function execute(InstallerContext $context): StageResult
    {
        $log       = [];
        $target    = rtrim($context->session->paths->target, '/');
        $userspace = $context->session->paths->userspace;
        $sessionId = $context->session->id;

        // Purge orphan staging dirs from previous sessions to keep userspace clean
        $this->purgeOrphanStagingDirs($userspace, $sessionId, $log);

        $stagingDir = $userspace . '/staging-' . substr($sessionId, 0, 8);

        // Resume logic:
        //  - staging/vendor exists  => Composer finished; skip straight to move
        //  - staging exists, no vendor => partial; wipe and restart
        //  - no staging dir         => fresh start
        $skipComposer = false;
        if (is_dir($stagingDir)) {
            if (is_dir($stagingDir . '/vendor')) {
                $log[] = 'Composer already completed in staging. Resuming at move step.';
                $skipComposer = true;
            } else {
                $log[] = 'Partial staging detected. Cleaning up for a fresh download.';
                $this->removeDirectory($stagingDir);
            }
        }

        if (!$skipComposer) {
            mkdir($stagingDir, 0755, true);
            $composerBin = $this->resolveComposerBin($context);
            $log[] = 'Composer: ' . $composerBin;

            // --no-scripts is critical here.
            // Post-install scripts (package:discover, etc.) call artisan which
            // requires bootstrap/app.php — that path is only valid in the FINAL
            // target location, not the staging directory.
            // We run scripts manually after the move, from the correct working dir.
            $result = SafeProcessRunner::run(
                [
                    InstallerEnvironment::phpBinary(),
                    $composerBin,
                    'create-project',
                    'webkernel/webkernel',
                    $stagingDir,
                    '--no-interaction',
                    '--prefer-dist',
                    '--no-progress',
                    '--no-scripts',
                    '--no-ansi',
                ],
                null,
                [
                    'COMPOSER_HOME'            => $userspace . '/composer-home',
                    'WEBKERNEL_INSTALLER_MODE' => '1',
                ],
                600,
                function (string $type, string $chunk) use (&$log): void {
                    foreach (explode(PHP_EOL, $chunk) as $line) {
                        $line = stripAnsi(trim($line));
                        if ($line !== '') {
                            $log[] = $line;
                        }
                    }
                },
            );

            if (!$result->successful()) {
                $clean = stripAnsi(trim($result->stderr ?: $result->stdout));
                return StageResult::failure(self::humanizeComposerError($clean), $log);
            }
        }

        // Move staged files into the real target
        $log[] = 'Moving files to: ' . $target;
        if (!$this->moveStaging($stagingDir, $target, $log)) {
            return StageResult::failure('Could not move files to target directory.', $log);
        }
        $this->removeDirectory($stagingDir);
        $log[] = 'Staging directory removed.';

        // Re-dump autoload from the final target so class maps are correct.
        // We do NOT run post-autoload-dump script here — it calls
        // "artisan package:discover" which requires bootstrap/app.php
        // to be fully bootstrapped. The ConfigureStage handles that
        // after .env is in place.
        $composerBin = $this->resolveComposerBin($context);
        $dumpResult  = SafeProcessRunner::run(
            [
                InstallerEnvironment::phpBinary(),
                $composerBin,
                'dump-autoload',
                '--optimize',
                '--no-ansi',
                '--no-interaction',
            ],
            $target,
            ['COMPOSER_HOME' => $userspace . '/composer-home'],
            60,
            function (string $type, string $chunk) use (&$log): void {
                foreach (explode(PHP_EOL, $chunk) as $line) {
                    $line = stripAnsi(trim($line));
                    if ($line !== '') {
                        $log[] = $line;
                    }
                }
            },
        );

        if (!$dumpResult->successful()) {
            $log[] = 'Warning: dump-autoload exited with code ' . $dumpResult->exitCode;
        } else {
            $log[] = 'Autoload map regenerated.';
        }

        return StageResult::success('Webkernel downloaded successfully.', $log);
    }

    /**
     * Remove staging directories that belong to other sessions.
     *
     * @param string[] $log
     */
    private function purgeOrphanStagingDirs(string $userspace, string $activeSessionId, array &$log): void
    {
        $myStub  = substr($activeSessionId, 0, 8);
        $entries = glob($userspace . '/staging-*') ?: [];
        foreach ($entries as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $stub = substr(basename($dir), strlen('staging-'));
            if ($stub !== $myStub) {
                $this->removeDirectory($dir);
                $log[] = 'Removed orphan staging: ' . basename($dir);
            }
        }
    }


    /**
     * Recursively move all contents of $src into $dst.
     *
     * @param string[] $log
     */
    private function moveStaging(string $src, string $dst, array &$log): bool
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        $items = scandir($src);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = $src . '/' . $item;
            $dstPath = $dst . '/' . $item;
            if (is_dir($srcPath)) {
                if (!is_dir($dstPath)) {
                    mkdir($dstPath, 0755, true);
                }
                if (!$this->moveStaging($srcPath, $dstPath, $log)) {
                    return false;
                }
            } else {
                if (!rename($srcPath, $dstPath)) {
                    if (!copy($srcPath, $dstPath)) {
                        $log[] = 'Warning: could not move ' . $item;
                    } else {
                        unlink($srcPath);
                    }
                }
            }
        }
        return true;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private static function humanizeComposerError(string $raw): string
    {
        $lower = strtolower($raw);
        if (str_contains($lower, 'not empty')) {
            return 'Target directory not empty. Retry — staging will be used automatically.';
        }
        if (str_contains($lower, 'could not find package') || str_contains($lower, 'package not found')) {
            return 'Package webkernel/webkernel not found on Packagist. Check your connection.';
        }
        if (str_contains($lower, 'minimum-stability') || str_contains($lower, 'no matching package')) {
            return 'No stable release of webkernel/webkernel available yet.';
        }
        if (str_contains($lower, 'permission denied') || str_contains($lower, 'cannot write')) {
            return 'Permission denied writing to target directory.';
        }
        if (str_contains($lower, 'curl') || str_contains($lower, 'connection') || str_contains($lower, 'network')) {
            return 'Network error during download. Check your connection and retry.';
        }
        if (str_contains($lower, 'your php version') || str_contains($lower, 'requires php')) {
            return 'PHP version mismatch — Webkernel requires a newer PHP version.';
        }
        if (str_contains($lower, 'out of memory') || str_contains($lower, 'memory limit')) {
            return 'PHP memory limit exceeded. Increase memory_limit in php.ini and retry.';
        }
        foreach (explode(PHP_EOL, $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return strlen($line) > 160 ? substr($line, 0, 157) . '…' : $line;
            }
        }
        return 'Composer create-project failed. Check the log for details.';
    }

    private function resolveComposerBin(InstallerContext $context): string
    {
        $stateValue = (string) ($context->session->stageData['composer_state'] ?? ComposerState::NotFound->value);
        $state = ComposerState::from($stateValue);

        if ($state === ComposerState::SystemOk) {
            foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
                $candidate = rtrim($dir, '/') . '/composer';
                if (is_executable($candidate)) {
                    return $candidate;
                }
            }
            return 'composer';
        }

        return (string) (
            $context->session->stageData['composer_phar']
            ?? $context->session->paths->composerPharPath()
        );
    }
}

final class VerifyStage implements InstallerStageInterface
{
    public function name(): string { return 'verify'; }
    public function label(): string { return 'Verifying installation'; }

    public function execute(InstallerContext $context): StageResult
    {
        $log     = [];
        $target  = rtrim($context->session->paths->target, '/');
        $missing = [];

        foreach (['composer.json', 'artisan'] as $file) {
            $path = $target . '/' . $file;
            if (file_exists($path)) {
                $log[] = 'Found: ' . $file;
            } else {
                $missing[] = $file;
                $log[] = 'Missing: ' . $file;
            }
        }

        if (!empty($missing)) {
            return StageResult::failure(
                'Missing files: ' . implode(', ', $missing),
                $log,
            );
        }

        return StageResult::success('Verification passed.', $log);
    }
}

final class ConfigureStage implements InstallerStageInterface
{
    public function name(): string { return 'configure'; }
    public function label(): string { return 'Configuring Webkernel'; }

    public function execute(InstallerContext $context): StageResult
    {
        $log    = [];
        $target = rtrim($context->session->paths->target, '/');

        // ── Step 1: .env ────────────────────────────────────────────────────
        $envExample = $target . '/.env.example';
        $envFile    = $target . '/.env';

        if (!file_exists($envFile) && file_exists($envExample)) {
            copy($envExample, $envFile)
                ? $log[] = 'Created .env from .env.example'
                : $log[] = 'Warning: could not copy .env.example';
        } else {
            $log[] = '.env already present.';
        }

        $artisan = $target . '/artisan';

        // ── Step 2: package:discover ─────────────────────────────────────────
        // Must run BEFORE key:generate so service providers are registered.
        // This is the call that failed during create-project because
        // bootstrap/app.php did not exist in the staging dir.
        // Now we are in the final target — .env is in place, bootstrap/ exists.
        if (is_file($artisan)) {
            $discover = SafeProcessRunner::run(
                [InstallerEnvironment::phpBinary(), $artisan, 'package:discover', '--ansi'],
                $target,
                ['APP_ENV' => 'local'],
                60,
            );
            if ($discover->successful()) {
                $log[] = 'Packages discovered.';
            } else {
                // Non-fatal: log the warning but continue
                $log[] = 'Warning: package:discover exit '
                    . $discover->exitCode . ': '
                    . stripAnsi(trim($discover->stderr ?: $discover->stdout));
            }
        }

        // ── Step 3: key:generate ─────────────────────────────────────────────
        if (is_file($artisan) && file_exists($envFile)) {
            $keygen = SafeProcessRunner::run(
                [InstallerEnvironment::phpBinary(), $artisan, 'key:generate', '--no-ansi'],
                $target,
                ['APP_ENV' => 'local'],
                60,
            );
            $log[] = $keygen->successful()
                ? 'Application key generated.'
                : 'Warning: key:generate exit ' . $keygen->exitCode . ': '
                    . stripAnsi(trim($keygen->stderr ?: $keygen->stdout));
        }

        // ── Step 4: sqlite db touch (common dev setup) ───────────────────────
        $dbPath = $target . '/database/database.sqlite';
        if (!file_exists($dbPath) && is_dir(dirname($dbPath))) {
            touch($dbPath);
            $log[] = 'Created database/database.sqlite';
        }

        return StageResult::success('Configuration complete.', $log);
    }
}

// ---------------------------------------------------------------------------
// Pipeline
// ---------------------------------------------------------------------------

final class InstallerPipeline
{
    /** @var InstallerStageInterface[] */
    private array $stages = [];

    public function register(InstallerStageInterface $stage): self
    {
        $this->stages[] = $stage;
        return $this;
    }

    /** @return InstallerStageInterface[] */
    public function all(): array
    {
        return $this->stages;
    }

    public function find(string $name): ?InstallerStageInterface
    {
        foreach ($this->stages as $stage) {
            if ($stage->name() === $name) {
                return $stage;
            }
        }
        return null;
    }

    public static function build(): self
    {
        return (new self())
            ->register(new PreflightStage())
            ->register(new ComposerBootstrapStage())
            ->register(new DownloadStage())
            ->register(new VerifyStage())
            ->register(new ConfigureStage());
    }
}

// ---------------------------------------------------------------------------
// Output Implementations
// ---------------------------------------------------------------------------

final class CliOutput implements OutputInterface
{
    public function info(string $message): void    { echo '[INFO]    ' . $message . PHP_EOL; }
    public function error(string $message): void   { fwrite(STDERR, '[ERROR]   ' . $message . PHP_EOL); }
    public function success(string $message): void { echo '[SUCCESS] ' . $message . PHP_EOL; }
    public function warning(string $message): void { echo '[WARNING] ' . $message . PHP_EOL; }
}

final class BufferedOutput implements OutputInterface
{
    /** @var array<array{level:string,message:string,time:int}> */
    private array $buffer = [];

    public function info(string $message): void    { $this->push('info',    $message); }
    public function error(string $message): void   { $this->push('error',   $message); }
    public function success(string $message): void { $this->push('success', $message); }
    public function warning(string $message): void { $this->push('warning', $message); }

    private function push(string $level, string $message): void
    {
        $this->buffer[] = ['level' => $level, 'message' => $message, 'time' => time()];
    }

    /** @return array<array{level:string,message:string,time:int}> */
    public function flush(): array
    {
        $out = $this->buffer;
        $this->buffer = [];
        return $out;
    }
}

// ---------------------------------------------------------------------------
// Access Gate
// ---------------------------------------------------------------------------

final class AccessGate
{
    private const COOKIE_NAME   = 'wk_access';
    private const HASH_FILENAME = '/access.hash';
    private const SESS_FILENAME = '/access.session';

    public static function isEnabled(InstallPath $paths): bool
    {
        return is_file($paths->userspace . self::HASH_FILENAME);
    }

    public static function setPassword(InstallPath $paths, string $password): void
    {
        $paths->ensure();
        // Argon2id preferred; bcrypt fallback for environments without argon2
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($password, $algo);
        if ($hash === false) {
            throw new \RuntimeException('password_hash() failed — check PHP configuration.');
        }
        file_put_contents($paths->userspace . self::HASH_FILENAME, $hash, LOCK_EX);
        chmod($paths->userspace . self::HASH_FILENAME, 0600);
    }

    public static function isAuthenticated(InstallPath $paths): bool
    {
        if (!self::isEnabled($paths)) {
            return true;
        }
        $token = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (empty($token) || !is_string($token)) {
            return false;
        }
        $stored = @file_get_contents($paths->userspace . self::SESS_FILENAME);
        return $stored !== false && hash_equals(trim($stored), $token);
    }

    public static function authenticate(InstallPath $paths, string $password): bool
    {
        $hashFile = $paths->userspace . self::HASH_FILENAME;
        if (!is_file($hashFile)) {
            return false;
        }
        $hash = (string) @file_get_contents($hashFile);
        if (!password_verify($password, $hash)) {
            return false;
        }
        $token = bin2hex(random_bytes(32));
        file_put_contents($paths->userspace . self::SESS_FILENAME, $token, LOCK_EX);
        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => time() + 7200,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        return true;
    }

    /**
     * Remove access protection entirely.
     * Only callable when already authenticated (enforced at API level).
     */
    public static function removePassword(InstallPath $paths): void
    {
        @unlink($paths->userspace . self::HASH_FILENAME);
        @unlink($paths->userspace . self::SESS_FILENAME);
        setcookie(self::COOKIE_NAME, '', time() - 3600, '/');
    }

    /**
     * Path to the emergency recovery key file.
     * This key is shown once at password-set time and allows removing the gate via CLI.
     */
    public static function recoveryKeyPath(InstallPath $paths): string
    {
        return $paths->userspace . '/recovery.key';
    }

    public static function generateRecoveryKey(InstallPath $paths): string
    {
    	/* @disregard */
        $key = strtoupper(implode('-', str_split(bin2hex(random_bytes(12)), 6)));
        // Store bcrypt of key so brute-force via filesystem is not trivial
        $keyHash = password_hash($key, PASSWORD_BCRYPT);
        if ($keyHash === false) {
            throw new \RuntimeException('password_hash() failed for recovery key.');
        }
        file_put_contents(
            self::recoveryKeyPath($paths),
            $keyHash,
            LOCK_EX,
        );
        chmod(self::recoveryKeyPath($paths), 0600);
        return $key;
    }

    public static function verifyRecoveryKey(InstallPath $paths, string $key): bool
    {
        $file = self::recoveryKeyPath($paths);
        if (!is_file($file)) {
            return false;
        }
        $hash = (string) @file_get_contents($file);
        return password_verify(strtoupper(trim($key)), $hash);
    }
}

// ---------------------------------------------------------------------------
// HTTP Helpers
// ---------------------------------------------------------------------------

final class HttpRequest
{
    /** @param array<string, mixed> $body */
    private function __construct(
        public readonly string $method,
        public readonly string $action,
        public readonly string $sessionId,
        public readonly array $body,
    ) {}

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body   = [];

        if ($method === 'POST') {
            $raw = file_get_contents('php://input');
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
            if (empty($body)) {
                $body = $_POST; // @disregard P1008
            }
        }

        return new self(
            method:    $method,
            action:    (string) ($body['action'] ?? $_GET['action'] ?? ''),
            sessionId: (string) ($body['session_id'] ?? $_COOKIE['wk_session'] ?? ''),
            body:      $body,
        );
    }
}

// ---------------------------------------------------------------------------
// Theme Configuration — edit these values to customise the installer palette.
// Colors follow Tailwind's gray scale conventions (950/900/850 for dark, 50-150 for light).
// ---------------------------------------------------------------------------

final class InstallerTheme
{
    private function __construct(
        // ── Dark mode ──────────────────────────────────────────────────────────
        public readonly string $darkBg        = '#030712',   // gray-950
        public readonly string $darkSurface   = '#111827',   // gray-900
        public readonly string $darkSurface2  = '#1f2937',   // gray-800
        public readonly string $darkSurface3  = '#374151',   // gray-700
        public readonly string $darkBorder    = '#1f2937',   // gray-800
        public readonly string $darkBorder2   = '#374151',   // gray-700
        public readonly string $darkText      = '#f9fafb',   // gray-50
        public readonly string $darkTextMuted = '#9ca3af',   // gray-400
        public readonly string $darkTextDim   = '#4b5563',   // gray-600
        // ── Light mode ─────────────────────────────────────────────────────────
        public readonly string $lightBg       = '#f9fafb',   // gray-50
        public readonly string $lightSurface  = '#ffffff',   // white
        public readonly string $lightSurface2 = '#f3f4f6',   // gray-100
        public readonly string $lightSurface3 = '#e5e7eb',   // gray-200 / ~gray-150
        public readonly string $lightBorder   = '#e5e7eb',   // gray-200
        public readonly string $lightBorder2  = '#d1d5db',   // gray-300
        public readonly string $lightText     = '#111827',   // gray-900
        public readonly string $lightTextMuted= '#6b7280',   // gray-500
        public readonly string $lightTextDim  = '#9ca3af',   // gray-400
        // ── Brand (shared across modes) ────────────────────────────────────────
        public readonly string $primary       = '#2563eb',   // blue-600
        public readonly string $primaryH      = '#1d4ed8',   // blue-700
        public readonly string $primaryDimDark= '#1e3a8a',   // blue-900 (dark bg)
        public readonly string $primaryDimLight='#dbeafe',   // blue-100 (light bg)
        public readonly string $accent        = '#0891b2',   // cyan-600
        public readonly string $success       = '#16a34a',   // green-600
        public readonly string $warning       = '#d97706',   // amber-600
        public readonly string $danger        = '#dc2626',   // red-600
        // ── Semantic overrides for dark mode readability ────────────────────────
        public readonly string $darkPrimaryH  = '#60a5fa',   // blue-400 (lighter on dark)
        public readonly string $darkAccent    = '#22d3ee',   // cyan-400
        public readonly string $darkSuccess   = '#4ade80',   // green-400
        public readonly string $darkWarning   = '#fbbf24',   // amber-400
        public readonly string $darkDanger    = '#f87171',   // red-400
    ) {}

    public static function defaults(): self
    {
        return new self();
    }

    public function darkCssVars(): string
    {
        return implode('', [
            "--bg:{$this->darkBg};",
            "--surface:{$this->darkSurface};",
            "--surface-2:{$this->darkSurface2};",
            "--surface-3:{$this->darkSurface3};",
            "--border:{$this->darkBorder};",
            "--border-2:{$this->darkBorder2};",
            "--primary:{$this->primary};",
            "--primary-h:{$this->darkPrimaryH};",
            "--primary-dim:{$this->primaryDimDark};",
            "--accent:{$this->darkAccent};",
            "--success:{$this->darkSuccess};",
            "--warning:{$this->darkWarning};",
            "--danger:{$this->darkDanger};",
            "--text:{$this->darkText};",
            "--text-muted:{$this->darkTextMuted};",
            "--text-dim:{$this->darkTextDim};",
            "--mono:'JetBrains Mono',monospace;",
            "--sans:'Inter',system-ui,sans-serif;",
            "--r:12px;--r-sm:8px;--r-xs:5px;",
            "--shadow:0 4px 32px rgba(0,0,0,.6);",
            "--glow:0 0 0 1px rgba(37,99,235,.35),0 0 20px rgba(37,99,235,.15);",
        ]);
    }

    public function lightCssVars(): string
    {
        return implode('', [
            "--bg:{$this->lightBg};",
            "--surface:{$this->lightSurface};",
            "--surface-2:{$this->lightSurface2};",
            "--surface-3:{$this->lightSurface3};",
            "--border:{$this->lightBorder};",
            "--border-2:{$this->lightBorder2};",
            "--primary:{$this->primary};",
            "--primary-h:{$this->primaryH};",
            "--primary-dim:{$this->primaryDimLight};",
            "--accent:{$this->accent};",
            "--success:{$this->success};",
            "--warning:{$this->warning};",
            "--danger:{$this->danger};",
            "--text:{$this->lightText};",
            "--text-muted:{$this->lightTextMuted};",
            "--text-dim:{$this->lightTextDim};",
            "--shadow:0 4px 24px rgba(0,0,0,.08);",
            "--glow:0 0 0 1px rgba(37,99,235,.4),0 0 16px rgba(37,99,235,.1);",
        ]);
    }
}

// ---------------------------------------------------------------------------
// HTML Component Helpers (pure functions — no duplication in templates)
// ---------------------------------------------------------------------------

/**
 * Render a styled button element as an HTML string.
 *
 * @param string      $label    Button text.
 * @param string      $variant  CSS class variant: primary | secondary | danger.
 * @param string      $attrs    Extra HTML attributes (e.g. Alpine directives).
 * @param string|null $icon     Inline SVG string or null.
 * @return string
 */
function htmlButton(string $label, string $variant = 'primary', string $attrs = '', ?string $icon = null): string
{
    $safeLabel = htmlspecialchars($label);
    $iconHtml  = $icon !== null ? $icon . ' ' : '';
    return '<button class="btn btn-' . $variant . '" ' . $attrs . '>'
        . $iconHtml
        . '<span>' . $safeLabel . '</span>'
        . '</button>';
}

/**
 * Render an SVG Lucide-style icon by name.
 * Only a small set of commonly-used paths are embedded here to avoid external deps.
 *
 * @param string $name  Icon identifier (play|refresh|reset|lock|check|x|warn|info|trash).
 * @param int    $size  Width/height in px.
 * @return string       Inline SVG string.
 */
function htmlIcon(string $name, int $size = 14): string
{
    $s = (string) $size;
    $base = '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
    $paths = match ($name) {
        'play'    => '<polygon points="6 3 20 12 6 21 6 3"/>',
        'play-all'=> '<polygon points="5 3 19 12 5 21 5 3"/><line x1="19" x2="19" y1="3" y2="21"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-6.219-8.56"/><polyline points="21 3 21 9 15 9"/>',
        'reset'   => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        'lock'    => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'shield'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'check'   => '<polyline points="20 6 9 17 4 12"/>',
        'x'       => '<line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/>',
        'warn'    => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'info'    => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'trash'   => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        default   => '<circle cx="12" cy="12" r="10"/>',
    };
    return $base . $paths . '</svg>';
}

/**
 * Render a requirement badge (check/cross + label + optional value).
 *
 * @param string      $label  Display label.
 * @param bool        $ok     Whether the requirement is satisfied.
 * @param string|null $value  Optional version/value string.
 * @return string
 */
function htmlRequirementBadge(string $label, bool $ok, ?string $value = null): string
{
    $iconClass = $ok ? 'req-ok' : 'req-fail';
    $icon      = htmlIcon($ok ? 'check' : 'x', 13);
    $valHtml   = $value !== null
        ? '<span class="req-val">' . htmlspecialchars($value) . '</span>'
        : '';
    return '<div class="req-item">'
        . '<span class="' . $iconClass . '">' . $icon . '</span>'
        . '<span class="req-label">' . htmlspecialchars($label) . '</span>'
        . $valHtml
        . '</div>';
}

// ---------------------------------------------------------------------------
// Installer Kernel
// ---------------------------------------------------------------------------

final class InstallerKernel
{
    private const string LOGO_ICON     = 'https://raw.githubusercontent.com/numerimondes/.github/refs/heads/main/assets/brands/webkernel/identity/appWebServer.png';
    private const string LOGO_WORDMARK = 'https://raw.githubusercontent.com/numerimondes/.github/refs/heads/main/assets/brands/webkernel/identity/logo-webkernel-darkmode.png';

    private FilesystemSessionStorage $storage;
    private InstallerPipeline $pipeline;

    public function __construct()
    {
        $this->storage  = new FilesystemSessionStorage();
        $this->pipeline = InstallerPipeline::build();
    }

    public function run(): void
    {
        if (InstallerEnvironment::isCli()) {
            $this->runCli();
        } else {
            $this->runHttp();
        }
    }

    // -------------------------------------------------------------------------
    // CLI
    // -------------------------------------------------------------------------

    private function runCli(): void
    {
        $output = new CliOutput();
        $paths  = InstallPath::resolve(InstallerEnvironment::resolveTargetDirectory());
        $paths->ensure();

        $output->info('Webkernel Installer — PHP ' . PHP_VERSION);
        $output->info('Target: ' . $paths->target);

        $idFile = $paths->userspace . '/cli.session';
        $sessionId = is_file($idFile) ? trim((string) file_get_contents($idFile)) : null;

        $session = ($sessionId !== null ? $this->storage->read($sessionId) : null)
            ?? InstallerSession::create($paths);

        file_put_contents($idFile, $session->id, LOCK_EX);

        $context = new InstallerContext($session, $output);

        foreach ($this->pipeline->all() as $stage) {
            $output->info('Stage: ' . $stage->label());

            try {
                $result = $stage->execute($context);
            } catch (\Throwable $e) {
                $result = StageResult::failure('Uncaught: ' . $e->getMessage());
            }

            foreach ($result->log as $line) {
                $clean = stripAnsi($line);
                $session->appendLog('[' . $stage->label() . '] ' . $clean);
                $output->info('  ' . $clean);
            }
            $this->storage->write($session);

            if ($result->status === StageStatus::Failed) {
                $session->advanceTo(InstallerPhase::Failed);
                $this->storage->write($session);
                $output->error('Failed: ' . stripAnsi($result->message));
                exit(1);
            }

            if ($result->status === StageStatus::Skipped) {
                $output->warning('Skipped: ' . stripAnsi($result->message));
            } else {
                $output->success(stripAnsi($result->message));
            }
        }

        $session->advanceTo(InstallerPhase::Complete);
        $this->storage->write($session);
        $output->success('Webkernel installed in: ' . $paths->target);

        // Offer self-deletion in CLI mode
        $self = (string) (__FILE__);
        if (is_file($self)) {
            $output->warning('Security: delete this installer when done: rm ' . escapeshellarg($self));
        }
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    private function runHttp(): void
    {
        // Buffer everything — stray notices/warnings must never corrupt JSON responses.
        ob_start();

        // ── URL routing ──────────────────────────────────────────────────────
        $urlPath = rtrim(
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            '/'
        );
        if ($urlPath === '' || $urlPath === '/') {
            ob_end_clean();
            $this->sendLandingPage();
        }
        // Any path other than /install or /fresh-install also goes to landing
        if (!in_array($urlPath, ['/install', '/fresh-install'], true)) {
            ob_end_clean();
            $this->sendLandingPage();
        }
        // ── end routing ──────────────────────────────────────────────────────

        $request = HttpRequest::fromGlobals();
        $paths   = InstallPath::resolve(InstallerEnvironment::resolveTargetDirectory());

        try {
            $paths->ensure();
        } catch (\Throwable $e) {
            ob_end_clean();
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'Userspace init failed: ' . $e->getMessage()]);
            exit(1);
        }

        if ($request->method === 'POST' && $request->action !== '') {
            try {
                $this->handleApi($request, $paths);
            } catch (\Throwable $e) {
                // Catch any unhandled exception and return a clean JSON 500
                if (ob_get_length() !== false) { ob_end_clean(); }
                http_response_code(500);
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-store');
                echo json_encode([
                    'ok'    => false,
                    'error' => $e->getMessage(),
                    'file'  => basename($e->getFile()) . ':' . $e->getLine(),
                ], JSON_UNESCAPED_SLASHES);
                exit(1);
            }
        }

        ob_end_clean();
        $this->sendHtml($paths);
    }

    /** @disregard P1075 — every branch calls $this->jsonOut() which is `never` */
    private function handleApi(HttpRequest $request, InstallPath $paths): never
    {
        $gated = AccessGate::isEnabled($paths);
        $authed = AccessGate::isAuthenticated($paths);

        if ($gated && !$authed) {
            if ($request->action === 'authenticate') {
                $pw = (string) ($request->body['password'] ?? '');
                if (AccessGate::authenticate($paths, $pw)) {
                    $this->jsonOut(['ok' => true]);
                }
                $this->jsonOut(['ok' => false, 'error' => 'Invalid password.'], 401);
            }
            // Recovery key bypass: allow removing the gate even when locked out
            if ($request->action === 'remove_password') {
                $recoveryKey = (string) ($request->body['recovery_key'] ?? '');
                if ($recoveryKey !== '' && AccessGate::verifyRecoveryKey($paths, $recoveryKey)) {
                    AccessGate::removePassword($paths);
                    $this->jsonOut(['ok' => true]);
                }
                $this->jsonOut(['ok' => false, 'error' => 'Invalid recovery key.'], 403);
            }
            $this->jsonOut(['ok' => false, 'error' => 'Unauthorized.'], 401);
        }

        match ($request->action) {
            'init'         => $this->apiInit($paths),
            'status'       => $this->apiStatus($request),
            'run_stage'    => $this->apiRunStage($request),
            'set_password'    => $this->apiSetPassword($request, $paths),
            'remove_password' => $this->apiRemovePassword($request, $paths),
            'reset'            => $this->apiReset($request),
            'delete_installer' => $this->apiDeleteInstaller(),
            'list_sessions'    => $this->apiListSessions($paths),
            default        => $this->jsonOut(['ok' => false, 'error' => 'Unknown action.'], 400),
        };
    }

    private function apiInit(InstallPath $paths): never
    {
        $session = InstallerSession::create($paths);
        $this->storage->write($session);

        setcookie('wk_session', $session->id, [
            'expires'  => time() + 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        $this->jsonOut([
            'ok'         => true,
            'session_id' => $session->id,
            'token'      => $session->token->value,
            'phase'      => $session->phase->value,
            'stages'     => $this->stagesPayload(),
        ]);
    }

    private function apiStatus(HttpRequest $request): never
    {
        $session = $this->storage->read($request->sessionId);
        if ($session === null) {
            $this->jsonOut(['ok' => false, 'error' => 'Session not found.'], 404);
        }
        $this->jsonOut([
            'ok'            => true,
            'phase'         => $session->phase->value,
            'phase_label'   => $session->phase->label(),
            'log'           => array_slice($session->log, -100),
            'last_activity' => $session->lastActivity,
            'locked'        => $session->locked,
        ]);
    }

    private function apiRunStage(HttpRequest $request): never
    {
        $session = $this->storage->read($request->sessionId);
        if ($session === null) {
            $this->jsonOut(['ok' => false, 'error' => 'Session not found.'], 404);
        }

        $providedToken = (string) ($request->body['token'] ?? '');
        if (!$session->token->verify($providedToken)) {
            $this->jsonOut(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        if ($session->token->isExpired()) {
            $this->jsonOut(['ok' => false, 'error' => 'Token expired. Please refresh the page.'], 403);
        }

        if ($session->locked) {
            $this->jsonOut(['ok' => false, 'error' => 'A stage is already running. Please wait.']);
        }

        $stageName = (string) ($request->body['stage'] ?? '');
        $stage = $this->pipeline->find($stageName);
        if ($stage === null) {
            $this->jsonOut(['ok' => false, 'error' => 'Unknown stage: ' . $stageName], 400);
        }

        $session->locked = true;
        $this->storage->write($session);

        $output  = new BufferedOutput();
        $context = new InstallerContext($session, $output);

        try {
            $result = $stage->execute($context);
        } catch (\Throwable $e) {
            $result = StageResult::failure('Uncaught exception: ' . $e->getMessage());
        }

        // Sanitize: strip ANSI codes from every log line before persistence or frontend delivery
        $cleanLog = array_map(
            fn (string $line): string => stripAnsi($line),
            $result->log,
        );

        foreach ($cleanLog as $line) {
            $session->appendLog('[' . $stage->label() . '] ' . $line);
        }

        $session->locked = false;

        if ($result->status === StageStatus::Failed) {
            $session->advanceTo(InstallerPhase::Failed);
        } else {
            // Check if all stages are done
            $stageNames = array_map(fn ($s) => $s->name(), $this->pipeline->all());
            $currentIdx = array_search($stageName, $stageNames, true);
            if ($currentIdx !== false && !isset($stageNames[$currentIdx + 1])) {
                $session->advanceTo(InstallerPhase::Complete);
            }
        }

        $this->storage->write($session);

        $response = [
            'ok'      => true,
            'status'  => $result->status->value,
            'message' => stripAnsi($result->message),
            'log'     => $cleanLog,
            'phase'   => $session->phase->value,
        ];

        if ($session->phase === InstallerPhase::Complete) {
            $wkVersion = resolveWebkernelVersion($session->paths->target);
            if ($wkVersion !== null) {
                $response['wk_version'] = $wkVersion;
            }
        }

        $this->jsonOut($response);
    }

    private function apiSetPassword(HttpRequest $request, InstallPath $paths): never
    {
        $pw = (string) ($request->body['password'] ?? '');
        if (strlen($pw) < 8) {
            $this->jsonOut(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 400);
        }
        if (!(bool) ($request->body['confirmed'] ?? false)) {
            $this->jsonOut(['ok' => false, 'error' => 'Confirmation required.'], 400);
        }

        $paths->ensure();
        AccessGate::setPassword($paths, $pw);
        $recoveryKey = AccessGate::generateRecoveryKey($paths);
        // Immediately auto-authenticate so the setter is never locked out.
        // setcookie() must be called before jsonOut() flushes output.
        AccessGate::authenticate($paths, $pw);
        $this->jsonOut(['ok' => true, 'recovery_key' => $recoveryKey]);
    }

    private function apiRemovePassword(HttpRequest $request, InstallPath $paths): never
    {
        // Must be authenticated OR provide valid recovery key
        $recoveryKey = (string) ($request->body['recovery_key'] ?? '');
        $authenticated = AccessGate::isAuthenticated($paths);
        $recoveryValid = $recoveryKey !== '' && AccessGate::verifyRecoveryKey($paths, $recoveryKey);

        if (!$authenticated && !$recoveryValid) {
            $this->jsonOut(['ok' => false, 'error' => 'Not authorised to remove password.'], 403);
        }

        AccessGate::removePassword($paths);
        $this->jsonOut(['ok' => true]);
    }

    private function apiReset(HttpRequest $request): never
    {
        if ($request->sessionId !== '') {
            $this->storage->delete($request->sessionId);
        }
        setcookie('wk_session', '', time() - 3600, '/');
        $this->jsonOut(['ok' => true]);
    }

    private function apiDeleteInstaller(): never
    {
        $self = (string) ($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
        if (!is_file($self)) {
            $this->jsonOut(['ok' => false, 'error' => 'Installer file not found.'], 404);
        }
        if (@unlink($self)) {
            $this->jsonOut(['ok' => true, 'deleted' => basename($self)]);
        }
        $this->jsonOut(['ok' => false, 'error' => 'Could not delete installer file. Remove it manually.'], 500);
    }

    private function apiListSessions(InstallPath $paths): never
    {
        $sessions = [];
        $pattern  = $paths->userspace . '/sessions/*/state.json';
        foreach (glob($pattern) ?: [] as $file) {
            $raw  = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $sessions[] = [
                'id'            => (string) ($data['id'] ?? ''),
                'phase'         => (string) ($data['phase'] ?? 'unknown'),
                'started_at'    => (int) ($data['started_at'] ?? 0),
                'last_activity' => (int) ($data['last_activity'] ?? 0),
                'locked'        => (bool) ($data['locked'] ?? false),
            ];
        }
        usort($sessions, fn ($a, $b) => $b['last_activity'] <=> $a['last_activity']);
        $this->jsonOut(['ok' => true, 'sessions' => $sessions]);
    }

    /** @return array<array{name:string,label:string}> */
    private function stagesPayload(): array
    {
        return array_map(
            fn ($s) => ['name' => $s->name(), 'label' => $s->label()],
            $this->pipeline->all(),
        );
    }

    private function jsonOut(mixed $data, int $status = 200): never
    {
        // Discard any accidental prior output (warnings, notices) that would
        // corrupt the JSON response and cause "Unexpected end of JSON input".
        if (ob_get_length() !== false) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = json_encode(['ok' => false, 'error' => 'JSON encoding failed: ' . json_last_error_msg()]);
        }
        echo $encoded;
        exit(0);
    }

    // -------------------------------------------------------------------------
    // HTML output
    // -------------------------------------------------------------------------

    private function sendHtml(InstallPath $paths): never
    {
        $gated  = AccessGate::isEnabled($paths);
        $authed = AccessGate::isAuthenticated($paths);

        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        // SSR gate: locked clients receive ONLY the lock screen page.
        // Zero app HTML, zero installer logic is sent to unauthenticated clients.
        if ($gated && !$authed) {
            echo $this->viewLock();
            exit(0);
        }

        $stagesJson   = json_encode($this->stagesPayload(), JSON_UNESCAPED_UNICODE);
        $targetJson   = json_encode($paths->target);
        $requirements = $this->buildRequirementsHtml();

        $alreadyInstalled = is_webkernel_installed($paths->target);
        $wkVersion        = $alreadyInstalled ? resolveWebkernelVersion($paths->target) : null;

        $restoredSession = null;
        $cookieSessionId = $_COOKIE['wk_session'] ?? '';
        if ($cookieSessionId !== '') {
            $sess = $this->storage->read($cookieSessionId);
            if ($sess !== null) {
                $restoredSession = [
                    'id'    => $sess->id,
                    'token' => $sess->token->value,
                    'phase' => $sess->phase->value,
                ];
            }
        }

        $bootJson = json_encode([
            'alreadyInstalled' => $alreadyInstalled,
            'wkVersion'        => $wkVersion,
            'session'          => $restoredSession,
        ], JSON_UNESCAPED_UNICODE);

        echo $this->view($stagesJson, $targetJson, $requirements, $bootJson);
        exit(0);
    }

    private function viewLock(): string
    {
        $logoWordmark = htmlspecialchars(self::LOGO_WORDMARK);
        $theme        = InstallerTheme::defaults();
        $darkCss      = $theme->darkCssVars();
        $lightCss     = $theme->lightCssVars();

        return <<<LOCKHTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Webkernel Installer — Access Restricted</title>
<script>
(function(){
  try{
    var t=localStorage.getItem('wk_theme');
    if(t==='light') document.documentElement.classList.add('light');
  }catch(_){}
})();
</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{{$darkCss}}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:system-ui,sans-serif;font-size:14px;-webkit-font-smoothing:antialiased}
.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.box{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:32px;width:100%;max-width:380px;box-shadow:0 4px 32px rgba(0,0,0,.45)}
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.fl{font-size:12px;font-weight:500;color:var(--text-muted)}
.fi{background:var(--surface-2);border:1px solid var(--border);border-radius:5px;color:var(--text);font-size:13px;font-family:inherit;padding:8px 38px 8px 11px;outline:none;transition:border-color .15s;width:100%}
.fi:focus{border-color:var(--primary)}
.pw-wrap{position:relative}
.eye-btn{position:absolute;right:9px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);padding:2px;display:flex}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:500;font-family:inherit;cursor:pointer;border:none;width:100%;transition:background .15s}
.btn:disabled{opacity:.4;cursor:not-allowed}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover:not(:disabled){background:var(--primary-h)}
.btn-danger{background:rgba(220,38,38,.1);color:var(--danger);border:1px solid rgba(220,38,38,.25)}
.btn-danger:hover:not(:disabled){background:rgba(220,38,38,.2)}
.err{background:rgba(220,38,38,.1);border:1px solid rgba(220,38,38,.25);color:#fca5a5;border-radius:6px;padding:9px 12px;font-size:12px;margin-bottom:10px;display:none}
.lnk{width:100%;margin-top:9px;background:none;border:none;cursor:pointer;font-size:12px;color:var(--text-dim);font-family:inherit;padding:4px;text-align:center;display:block}
.warn{background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.25);color:#fde68a;border-radius:6px;padding:9px 12px;font-size:12px;margin-bottom:12px;line-height:1.5}
.spin{width:14px;height:14px;border:2px solid rgba(255,255,255,.25);border-top-color:#fff;border-radius:50%;animation:r .65s linear infinite;display:inline-block}
@keyframes r{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div class="wrap">
  <div class="box">
    <div style="text-align:center;margin-bottom:22px">
      <img src="{$logoWordmark}" alt="Webkernel" style="height:36px" onerror="this.style.display='none'"/>
    </div>
    <div id="pw-panel">
      <div style="font-size:16px;font-weight:600;text-align:center;margin-bottom:3px">Access restricted</div>
      <div style="font-size:12px;color:var(--text-muted);text-align:center;margin-bottom:18px">Enter the installer password to continue.</div>
      <div class="fg">
        <label class="fl">Password</label>
        <div class="pw-wrap">
          <input type="password" class="fi" id="pw-in" placeholder="Password..." autocomplete="current-password"/>
          <button type="button" class="eye-btn" onclick="toggleEye('pw-in','es','eh')">
            <svg id="es" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg id="eh" style="display:none" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
          </button>
        </div>
      </div>
      <div class="err" id="pw-err"></div>
      <button class="btn btn-primary" id="pw-btn" onclick="doUnlock()">Unlock</button>
      <button class="lnk" onclick="showRec()">Forgot password? Use recovery key &rarr;</button>
    </div>
    <div id="rec-panel" style="display:none">
      <div style="font-size:16px;font-weight:600;text-align:center;margin-bottom:3px">Recovery key</div>
      <div style="font-size:12px;color:var(--text-muted);text-align:center;margin-bottom:18px">Remove password protection and unlock access.</div>
      <div class="warn">This will <strong>remove password protection</strong> entirely.</div>
      <div class="fg">
        <label class="fl">Recovery key</label>
        <input type="text" class="fi" style="padding-right:11px;font-family:monospace;letter-spacing:.08em;text-transform:uppercase" id="rec-in" placeholder="XXXXXX-XXXXXX-XXXXXX-XXXXXX" autocomplete="off" oninput="this.value=this.value.toUpperCase()"/>
      </div>
      <div class="err" id="rec-err"></div>
      <button class="btn btn-danger" id="rec-btn" onclick="doRec()">Unlock with recovery key</button>
      <button class="lnk" onclick="showPw()">&#8592; Back to password</button>
    </div>
  </div>
</div>
<script>
var g=function(id){return document.getElementById(id);};
function toggleEye(i,s,h){var el=g(i),isP=el.type==='password';el.type=isP?'text':'password';g(s).style.display=isP?'none':'inline';g(h).style.display=isP?'inline':'none';}
function showRec(){g('pw-panel').style.display='none';g('rec-panel').style.display='block';}
function showPw(){g('rec-panel').style.display='none';g('pw-panel').style.display='block';g('pw-err').style.display='none';}
g('pw-in').onkeydown=function(e){if(e.key==='Enter')doUnlock();};
g('rec-in').onkeydown=function(e){if(e.key==='Enter')doRec();};
async function post(b){var r=await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)});var t=await r.text();try{return JSON.parse(t);}catch(_){throw new Error('Server error');}}
async function doUnlock(){
  var btn=g('pw-btn'),err=g('pw-err'),pw=g('pw-in').value;
  btn.disabled=true;btn.innerHTML='<span class="spin"></span>';err.style.display='none';
  try{var d=await post({action:'authenticate',password:pw});if(d.ok){location.reload();}else{err.textContent=d.error||'Invalid password.';err.style.display='block';btn.disabled=false;btn.textContent='Unlock';}}
  catch(e){err.textContent=e.message;err.style.display='block';btn.disabled=false;btn.textContent='Unlock';}
}
async function doRec(){
  var btn=g('rec-btn'),err=g('rec-err'),key=g('rec-in').value;
  btn.disabled=true;btn.innerHTML='<span class="spin"></span>';err.style.display='none';
  try{var d=await post({action:'remove_password',recovery_key:key});if(d.ok){location.reload();}else{err.textContent=d.error||'Invalid key.';err.style.display='block';btn.disabled=false;btn.textContent='Unlock with recovery key';}}
  catch(e){err.textContent=e.message;err.style.display='block';btn.disabled=false;btn.textContent='Unlock with recovery key';}
}
</script>
</body>
</html>
LOCKHTML;
    }

    private function buildRequirementsHtml(): string
    {
        $items = [
            ['label' => 'PHP 8.4+',      'ok' => PHP_VERSION_ID >= 80400, 'val' => PHP_VERSION],
            ['label' => 'ext-json',      'ok' => extension_loaded('json'),      'val' => null],
            ['label' => 'ext-zip',       'ok' => extension_loaded('zip'),       'val' => null],
            ['label' => 'ext-openssl',   'ok' => extension_loaded('openssl'),   'val' => null],
            ['label' => 'ext-curl',      'ok' => extension_loaded('curl'),      'val' => null],
            ['label' => 'ext-mbstring',  'ok' => extension_loaded('mbstring'),  'val' => null],
            ['label' => 'ext-pdo',       'ok' => extension_loaded('pdo'),       'val' => null],
            ['label' => 'ext-tokenizer', 'ok' => extension_loaded('tokenizer'), 'val' => null],
        ];

        $html = '';
        foreach ($items as $item) {
            $html .= htmlRequirementBadge($item['label'], $item['ok'], $item['val'] ?? null);
        }
        return $html;
    }

    private function view(string $stagesJson, string $targetJson, string $requirements, string $bootJson = '{}'): string
    {
        $phpVer          = htmlspecialchars(PHP_VERSION);
        $phpSapi         = htmlspecialchars(PHP_SAPI);
        $installerVer    = htmlspecialchars(WEBKERNEL_INSTALLER_VERSION);
        $codenamesJson   = json_encode([
            1 => 'Waterfall',
            2 => 'Greenfields',
            3 => 'Horizon',
            4 => 'Stonebridge',
            5 => 'Evergreen',
        ]);
        // Theme tokens — edit freely; they are injected verbatim into the CSS :root blocks
        $theme        = InstallerTheme::defaults();
        $darkCss      = $theme->darkCssVars();
        $lightCss     = $theme->lightCssVars();
        $logoIcon     = htmlspecialchars(self::LOGO_ICON);
        $logoWordmark = htmlspecialchars(self::LOGO_WORDMARK);

        return <<<HTML
<!DOCTYPE html>
<html lang="en" id="app">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Webkernel Installer</title>
<script>(function(){try{if(localStorage.getItem('wk_theme')==='light')document.documentElement.classList.add('light');}catch(_){}})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{{$darkCss}}
html.light{{$lightCss}}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:var(--sans);font-size:14px;line-height:1.6;-webkit-font-smoothing:antialiased}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0 20px;height:56px;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:50}
.topbar-brand{display:flex;align-items:center;gap:9px;text-decoration:none;flex-shrink:0}
.topbar-logo{height:26px;width:auto;border-radius:4px}
.topbar-name{font-size:15px;font-weight:600;color:var(--text);letter-spacing:-.3px}
.badge{display:inline-flex;align-items:center;font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;white-space:nowrap;flex-shrink:0}
.badge-primary{background:var(--primary-dim);color:var(--primary-h)}
.badge-mono{background:var(--surface-2);border:1px solid var(--border);color:var(--text-dim);font-family:var(--mono);font-weight:400;border-radius:5px}
.badge-accent{background:rgba(34,211,238,.08);border:1px solid rgba(34,211,238,.2);color:var(--accent);font-family:var(--mono);font-weight:400;border-radius:5px}
.topbar-spacer{flex:1}
.icon-btn{display:flex;align-items:center;justify-content:center;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-sm);padding:6px;cursor:pointer;color:var(--text-muted);transition:color .12s,background .12s;flex-shrink:0}
.icon-btn:hover{color:var(--text);background:var(--surface-3)}
#mob-menu-btn{display:none}
.shell{display:grid;grid-template-columns:260px 1fr;max-width:1200px;margin:32px auto;padding:0 20px;gap:0;width:100%}
@media(max-width:768px){
  #mob-menu-btn{display:flex}
  .topbar-name,.badge-mono,.badge-accent{display:none}
  .shell{grid-template-columns:1fr;margin:16px auto;padding:0 12px}
  .sidebar{position:fixed;top:56px;left:0;bottom:0;z-index:100;width:280px;background:var(--surface);border-right:1px solid var(--border);padding:18px 14px;overflow-y:auto;transform:translateX(-100%);transition:transform .25s ease}
  .sidebar.open{transform:translateX(0)}
}
.mob-overlay{display:none;position:fixed;top:56px;left:0;right:0;bottom:0;z-index:99;background:rgba(0,0,0,.55)}
.mob-overlay.open{display:block}
.sidebar{padding-right:24px}
.sidebar-label{font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--text-dim);margin-bottom:10px}
.stage-list{list-style:none;display:flex;flex-direction:column;gap:2px;margin-bottom:16px}
.stage-item{display:flex;align-items:center;gap:9px;padding:7px 10px;border-radius:var(--r-sm);font-size:13px;color:var(--text-muted)}
.stage-item.active{background:var(--surface-2);color:var(--text);font-weight:500}
.si{width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:11px;font-weight:700}
.si.pending{background:var(--surface-3);color:var(--text-dim);border:1px solid var(--border)}
.si.running{background:var(--primary);color:#fff;animation:pulse 1.2s infinite}
.si.success{background:var(--success);color:#fff}
.si.failed{background:var(--danger);color:#fff}
.si.skipped{background:var(--surface-3);color:var(--text-muted)}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(37,99,235,.4)}70%{box-shadow:0 0 0 6px rgba(37,99,235,0)}100%{box-shadow:0 0 0 0 rgba(37,99,235,0)}}
.divider{height:1px;background:var(--border);margin:14px 0}
.ir{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);font-size:12px}
.ir:last-child{border-bottom:none}
.irl{color:var(--text-muted)}
.irv{font-family:var(--mono);color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px}
/* details/summary for sessions */
details.sess-panel{margin-bottom:4px}
details.sess-panel summary{display:flex;align-items:center;justify-content:space-between;cursor:pointer;list-style:none;padding:6px 0;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--text-dim);user-select:none}
details.sess-panel summary::-webkit-details-marker{display:none}
details.sess-panel summary .chevron{transition:transform .2s;width:12px;height:12px}
details.sess-panel[open] summary .chevron{transform:rotate(180deg)}
.sess-list{display:flex;flex-direction:column;gap:3px;padding-top:6px}
.sess-row{display:flex;align-items:center;gap:0;padding:5px 7px;border-radius:6px;border:1px solid transparent;transition:background .12s,border-color .12s}
.sess-row:hover{background:var(--surface-2);border-color:var(--border)}
.sess-row.active{background:var(--surface-2);border-color:var(--border)}
.sess-click{display:flex;align-items:center;gap:6px;flex:1;min-width:0;cursor:pointer;padding-right:4px}
.sess-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.sess-info{flex:1;min-width:0}
.sess-id{font-size:11px;font-family:var(--mono);color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sess-meta{font-size:10px;color:var(--text-dim);margin-top:1px}
.sess-active-tag{font-size:9px;font-weight:700;color:var(--primary-h);flex-shrink:0;letter-spacing:.04em}
.sess-del-btn{flex-shrink:0;width:22px;height:22px;background:none;border:none;cursor:pointer;border-radius:4px;color:var(--text-dim);display:flex;align-items:center;justify-content:center;margin-left:2px;opacity:0;transition:opacity .12s,color .12s,background .12s}
.sess-row:hover .sess-del-btn{opacity:1}
.sess-del-btn:hover{color:var(--danger);background:rgba(220,38,38,.1)}
.sess-del-all{width:100%;margin-top:6px;display:flex;align-items:center;gap:6px;background:none;border:1px dashed var(--border);border-radius:5px;padding:5px 8px;font-size:11px;color:var(--danger);cursor:pointer;transition:border-color .15s,background .15s;font-family:var(--sans)}
.sess-del-all:hover{background:rgba(220,38,38,.07);border-color:var(--danger)}
/* sidebar action buttons */
.sidebar-actions{display:flex;flex-direction:column;gap:6px;margin-top:14px}
/* main panel */
.main{display:flex;flex-direction:column;min-width:0}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:16px}
.ch{padding:15px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.ci{width:32px;height:32px;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--primary-h)}
.ct{font-size:14px;font-weight:600;color:var(--text)}
.cs{font-size:12px;color:var(--text-muted);margin-top:1px}
.cb{padding:18px 18px}
.banner{display:flex;align-items:center;gap:9px;padding:10px 13px;border-radius:var(--r-sm);font-size:13px;margin-bottom:12px}
.banner.info{background:rgba(37,99,235,.1);border:1px solid rgba(37,99,235,.25);color:#93c5fd}
.banner.success{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.25);color:#86efac}
.banner.danger{background:rgba(220,38,38,.1);border:1px solid rgba(220,38,38,.25);color:#fca5a5}
.banner.warning{background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.25);color:#fde68a}
.prog-track{background:var(--surface-3);border-radius:999px;height:6px;overflow:hidden;margin-bottom:14px}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--primary),var(--accent));border-radius:999px;transition:width .4s ease}
.terminal{background:#030712;border:1px solid var(--border);border-radius:var(--r-sm);font-family:var(--mono);font-size:12px;color:#94a3b8;padding:12px 14px;height:240px;overflow-y:auto;line-height:1.75}
.terminal::-webkit-scrollbar{width:5px}
.terminal::-webkit-scrollbar-thumb{background:var(--border-2);border-radius:3px}
.ll{white-space:pre-wrap;word-break:break-all;display:block}
.ll.success{color:#4ade80}
.ll.error{color:#f87171}
.ll.warning{color:#fbbf24}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 15px;border-radius:var(--r-sm);font-size:13px;font-weight:500;font-family:var(--sans);cursor:pointer;border:none;transition:all .15s;white-space:nowrap}
.btn:disabled{opacity:.4;cursor:not-allowed}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover:not(:disabled){background:var(--primary-h)}
.btn-secondary{background:var(--surface-2);color:var(--text);border:1px solid var(--border-2)}
.btn-secondary:hover:not(:disabled){background:var(--surface-3)}
.btn-danger{background:rgba(220,38,38,.1);color:var(--danger);border:1px solid rgba(220,38,38,.25)}
.btn-danger:hover:not(:disabled){background:rgba(220,38,38,.2)}
.btn-full{width:100%;justify-content:center}
.btn-sm{font-size:12px;padding:6px 12px}
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.fl{font-size:12px;font-weight:500;color:var(--text-muted)}
.fi{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-xs);color:var(--text);font-size:13px;font-family:var(--sans);padding:8px 11px;outline:none;transition:border-color .15s;width:100%}
.fi:focus{border-color:var(--primary)}
.pw-eye-wrap{position:relative}
.pw-eye-wrap .fi{padding-right:38px}
.pw-eye-btn{position:absolute;right:9px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);padding:2px;display:flex;align-items:center}
.spin{width:14px;height:14px;border:2px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;animation:rot .65s linear infinite;flex-shrink:0}
@keyframes rot{to{transform:rotate(360deg)}}
/* lock screen */
.lock-wrap{position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:20px}
.lock-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:32px;width:100%;max-width:380px;box-shadow:var(--shadow)}
/* modal */
.modal-bg{position:fixed;inset:0;z-index:300;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;padding:16px}
.modal-bg.open{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);width:100%;max-width:440px;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow);display:block}
.modal-hdr{display:flex;align-items:center;justify-content:space-between;padding:15px 18px;border-bottom:1px solid var(--border)}
.modal-title{font-size:14px;font-weight:600;color:var(--text);display:flex;align-items:center;gap:7px}
.modal-body{padding:18px;display:block}
.modal-close{background:none;border:none;cursor:pointer;color:var(--text-muted);padding:3px;border-radius:4px;display:flex;align-items:center}
.modal-close:hover{color:var(--text)}
/* ok screen */
.ok-icon{width:56px;height:56px;background:rgba(22,163,74,.12);border:2px solid rgba(22,163,74,.35);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
/* requirements grid */
.req-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:7px;width:100%}
.req-item{display:flex;align-items:center;gap:7px;background:var(--surface-2);border:1px solid var(--border);border-radius:5px;padding:7px 10px;font-size:12px;min-width:0}
.req-ok{color:#4ade80;display:flex;flex-shrink:0}
.req-fail{color:#f87171;display:flex;flex-shrink:0}
.req-label{color:var(--text);font-size:12px}
.req-val{font-size:11px;font-family:monospace;color:var(--text-dim);margin-left:2px}
html.light .req-ok{color:#16a34a}
html.light .req-fail{color:#dc2626}
/* Banner light mode — dark text on tinted background */
html.light .banner.info{background:#eff6ff;border-color:#bfdbfe;color:#1e40af}
html.light .banner.success{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}
html.light .banner.danger{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
html.light .banner.warning{background:#fffbeb;border-color:#fde68a;color:#92400e}
/* Modal light mode */
html.light .modal{box-shadow:0 8px 32px rgba(0,0,0,.12)}
html.light .modal-bg{background:rgba(0,0,0,.35)}
/* info cards row */
.info-cards{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.info-card{flex:1;min-width:150px;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-sm);padding:11px}
.info-card-label{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-dim);margin-bottom:4px;font-weight:600}
.info-card-val{font-size:12px;font-family:var(--mono);color:var(--primary-h)}
</style>
</head>
<body>

<!-- MAIN APP -->
<div id="main-app" style="display:none">

  <!-- Topbar -->
  <header class="topbar">
    <a class="topbar-brand" href="https://github.com/webkernelphp/webkernel" target="_blank" rel="noopener">
      <img class="topbar-logo" src="{$logoIcon}" alt="Webkernel" onerror="this.style.display='none'"/>
      <span class="topbar-name">Webkernel</span>
    </a>
    <span class="badge badge-primary">Installer</span>
    <span class="badge badge-mono">v{$installerVer}</span>
    <span class="badge badge-accent" id="topbar-codename">Waterfall series</span>
    <span class="topbar-spacer"></span>
    <button class="icon-btn" id="theme-btn" title="Toggle theme">
      <svg id="theme-sun" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
      <svg id="theme-moon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    </button>
    <button class="icon-btn" id="mob-menu-btn" aria-label="Menu">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <span class="badge badge-mono" style="font-size:11px">PHP {$phpVer}</span>
  </header>

  <!-- Mobile overlay -->
  <div class="mob-overlay" id="mob-overlay"></div>

  <!-- Shell -->
  <div class="shell">

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <span class="sidebar-label" style="margin-bottom:0">Steps</span>
        <button class="icon-btn" id="mob-close-btn" style="display:none" title="Close">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
        </button>
      </div>
      <ul class="stage-list" id="stage-list"></ul>

      <details class="sess-panel">
        <summary>
          <span style="display:flex;align-items:center;gap:6px">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></svg>
            Sessions
          </span>
          <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="sess-list" id="sess-list">
          <div style="font-size:12px;color:var(--text-dim);padding:4px 6px" id="sess-empty">No sessions found.</div>
        </div>
        <button class="sess-del-all" id="sess-del-all-btn" style="display:none" onclick="wk.deleteOldSessions()">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
          <span id="sess-del-all-label">Delete old sessions</span>
        </button>
      </details>

      <div class="divider"></div>
      <div class="ir"><span class="irl">PHP</span><span class="irv">{$phpVer}</span></div>
      <div class="ir"><span class="irl">SAPI</span><span class="irv">{$phpSapi}</span></div>
      <div class="ir"><span class="irl">Target</span><span class="irv" id="sidebar-target" title=""></span></div>

      <div class="sidebar-actions">
        <button class="btn btn-secondary btn-sm btn-full" id="btn-sync" onclick="wk.refresh()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/><polyline points="21 3 21 9 15 9"/></svg>
          Sync status
        </button>
        <button class="btn btn-secondary btn-sm btn-full" id="btn-security" onclick="wk.openPwModal()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Security
        </button>
        <button class="btn btn-danger btn-sm btn-full" id="btn-reset" onclick="wk.reset()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
          Reset session
        </button>
      </div>
    </aside>

    <!-- Main panel -->
    <main class="main" id="main-panel">

      <!-- Welcome panel (shown when no session and not installed) -->
      <div class="card" id="panel-welcome" style="display:none">
        <div class="ch">
          <div class="ci"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg></div>
          <div><div class="ct">Welcome to Webkernel Installer</div><div class="cs">Package webkernel/webkernel via Composer</div></div>
        </div>
        <div class="cb">
          <img src="{$logoWordmark}" alt="Webkernel" style="height:30px;margin-bottom:16px;display:block" onerror="this.style.display='none'"/>
          <p style="color:var(--text-muted);font-size:13px;margin-bottom:16px;line-height:1.7">This installer will download and configure <strong style="color:var(--text)">webkernel/webkernel</strong> into your target directory. Sessions persist on disk — close this tab and resume later.</p>
          <div class="info-cards">
            <div class="info-card"><div class="info-card-label">Repository</div><a href="https://github.com/webkernelphp/webkernel" target="_blank" style="text-decoration:none" class="info-card-val">github.com/webkernelphp</a></div>
            <div class="info-card"><div class="info-card-label">Package</div><a href="https://packagist.org/packages/webkernel/webkernel" target="_blank" style="text-decoration:none" class="info-card-val">webkernel/webkernel</a></div>
          </div>
          <!-- Security shortcut — opens modal -->
          <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-sm);padding:11px 14px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;cursor:pointer" onclick="wk.openPwModal()">
            <span style="display:flex;align-items:center;gap:7px;font-size:13px;font-weight:500">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              Protect with a password
            </span>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
          </div>
          <button class="btn btn-primary" id="btn-start" onclick="wk.start()" style="font-size:14px;padding:10px 22px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>
            Begin installation
          </button>
        </div>
      </div>

      <!-- Progress panel -->
      <div class="card" id="panel-progress" style="display:none">
        <div class="ch">
          <div class="ci" id="progress-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="7" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
          </div>
          <div>
            <div class="ct" id="progress-title">Installing…</div>
            <div class="cs" id="progress-sub"></div>
          </div>
        </div>
        <div class="cb">
          <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:5px">
            <span>Progress</span><span id="progress-pct">0%</span>
          </div>
          <div class="prog-track"><div class="prog-fill" id="prog-fill" style="width:0%"></div></div>
          <div id="status-banner" class="banner" style="display:none"></div>
          <div class="terminal" id="terminal"></div>
          <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap" id="progress-actions">
            <button class="btn btn-primary" id="btn-next" onclick="wk.runNext()">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>
              Start
            </button>
            <button class="btn btn-secondary" id="btn-all" onclick="wk.runAll()">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/><line x1="19" x2="19" y1="3" y2="21"/></svg>
              Run all steps
            </button>
          </div>
        </div>
      </div>

      <!-- Complete panel -->
      <div class="card" id="panel-complete" style="display:none">
        <div class="cb" style="text-align:center;padding:40px 24px">
          <div class="ok-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
          <div style="font-size:19px;font-weight:700;margin-bottom:6px">Installation complete</div>
          <div id="complete-codename" style="display:none;margin-bottom:14px">
            <span style="font-size:13px;color:var(--text-muted)">You installed </span>
            <span style="font-size:14px;font-weight:600;color:var(--accent);font-family:var(--mono)" id="complete-codename-text"></span>
          </div>
          <p style="color:var(--text-muted);font-size:13px;max-width:360px;margin:0 auto 20px">Webkernel installed successfully. Configure your environment and <strong style="color:var(--danger)">delete this installer immediately.</strong></p>
          <div style="display:flex;flex-direction:column;align-items:center;gap:10px">
            <button class="btn btn-danger" id="btn-delete-installer" onclick="wk.deleteInstaller()" style="font-size:13px;padding:9px 18px">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
              Delete install.php now
            </button>
            <div id="delete-msg" style="font-size:12px;padding:7px 12px;border-radius:var(--r-xs);display:none;background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.25);color:var(--success)"></div>
            <button class="btn btn-secondary btn-sm" onclick="wk.reset()">Start over / reinstall</button>
          </div>
        </div>
      </div>

      <!-- Failed panel -->
      <div class="card" id="panel-failed" style="display:none">
        <div class="cb" style="padding:24px">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
            <div style="width:40px;height:40px;background:rgba(220,38,38,.1);border:1px solid rgba(220,38,38,.25);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
            </div>
            <div>
              <div style="font-size:15px;font-weight:600;color:var(--danger)">Installation failed</div>
              <div style="font-size:12px;color:var(--text-muted)" id="failed-msg"></div>
            </div>
          </div>
          <div class="terminal" id="failed-terminal" style="height:180px"></div>
          <div style="margin-top:12px">
            <button class="btn btn-primary" onclick="wk.reset()">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
              Try again
            </button>
          </div>
        </div>
      </div>

      <!-- Requirements card (always visible) -->
      <div class="card">
        <div class="ch">
          <div class="ci"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div>
          <div><div class="ct">Requirements</div><div class="cs">PHP 8.4+ with required extensions</div></div>
        </div>
        <div class="cb"><div class="req-grid">{$requirements}</div></div>
      </div>

    </main>
  </div>
</div>

<!-- Password modal -->
<div class="modal-bg" id="pw-modal">
  <div class="modal">
    <div class="modal-hdr">
      <span class="modal-title">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Security
      </span>
      <button class="modal-close" onclick="wk.closePwModal()" title="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <!-- step: form -->
      <div id="pw-step-form">
        <div class="banner warning" style="margin-bottom:14px">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
          If you lose this password you will be <strong>locked out</strong>. A one-time recovery key will be shown.
        </div>
        <div class="fg">
          <label class="fl">Password (min 8 chars)</label>
          <div class="pw-eye-wrap">
            <input type="password" class="fi" id="pw-input" placeholder="Choose a password…" autocomplete="new-password" oninput="wk.onPwInput()"/>
            <button type="button" class="pw-eye-btn" id="pw-eye-btn" onclick="wk.toggleEye('pw-input','pw-eye-btn','pw-eye-show','pw-eye-hide')" title="Show/hide">
              <svg id="pw-eye-show" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg id="pw-eye-hide" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
            </button>
          </div>
        </div>
        <div class="fg" id="pw-confirm-group" style="display:none">
          <label class="fl">Confirm password</label>
          <div class="pw-eye-wrap">
            <input type="password" class="fi" id="pw-confirm-input" placeholder="Repeat password…" autocomplete="new-password" oninput="wk.onPwInput()"/>
            <button type="button" class="pw-eye-btn" onclick="wk.toggleEye('pw-confirm-input','',null,null)" title="Show/hide">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <div id="pw-mismatch" style="font-size:11px;color:var(--danger);margin-bottom:8px;display:none">Passwords do not match.</div>
        <label id="pw-confirm-label" style="display:none;align-items:flex-start;gap:8px;cursor:pointer;font-size:12px;color:var(--text-muted);margin-bottom:14px;line-height:1.5">
          <input type="checkbox" id="pw-confirm-check" style="margin-top:2px;accent-color:var(--primary);flex-shrink:0" onchange="wk.onPwInput()"/>
          <span>I understand that <strong style="color:var(--text)">losing this password will lock me out</strong>. I will save the recovery key.</span>
        </label>
        <button class="btn btn-primary btn-full" id="pw-save-btn" onclick="wk.savePassword()" disabled>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Set password
        </button>
      </div>
      <!-- step: recovery key shown once -->
      <div id="pw-step-recovery" style="display:none">
        <div style="background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.3);border-radius:var(--r-xs);padding:13px;margin-bottom:12px">
          <div style="font-size:12px;font-weight:600;color:var(--success);margin-bottom:7px;display:flex;align-items:center;gap:6px">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Password set — save your recovery key now
          </div>
          <div id="pw-recovery-key-display" style="font-family:var(--mono);font-size:15px;font-weight:700;letter-spacing:.12em;color:var(--text);background:var(--surface-3);border:1px solid var(--border);border-radius:5px;padding:10px 14px;text-align:center;user-select:all;cursor:text"></div>
          <div style="font-size:11px;color:var(--text-dim);margin-top:7px;line-height:1.5">Shown <strong>once only</strong>. Save it in a password manager or printed note.</div>
        </div>
      <button class="btn btn-secondary btn-full" onclick="wk.pwSavedKey()">I have saved the recovery key</button>
      </div>
      <!-- step: done -->
      <div id="pw-step-done" style="display:none">
        <div style="font-size:13px;color:var(--success);display:flex;align-items:center;gap:7px;margin-bottom:14px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          Password protection is active.
        </div>
        <button class="btn btn-danger btn-full" onclick="wk.pwStep('remove')">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Remove password
        </button>
      </div>
      <!-- step: confirm remove -->
      <div id="pw-step-remove" style="display:none">
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:14px;line-height:1.5">Removing password means anyone with the installer URL can use it.</div>
        <div style="display:flex;gap:8px">
          <button class="btn btn-danger" style="flex:1;justify-content:center" onclick="wk.removePassword()">Remove</button>
          <button class="btn btn-secondary" style="flex:1;justify-content:center" onclick="wk.pwStep('done')">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
window.__WK_BOOT = {$bootJson};

const wk = (() => {
  // ── State ──────────────────────────────────────────────────────────────────
  let state = {
    sid: null, token: null, phase: 'idle',
    stages: {$stagesJson},
    stageStatus: {}, // name → pending|running|success|failed|skipped
    busy: false,
    logLines: [],
    target: {$targetJson},
    codenames: {$codenamesJson},
    wkVersion: null,
    wkCodename: null,
    deleting: false,
  };

  // ── DOM helpers ────────────────────────────────────────────────────────────
  const _q = id => document.getElementById(id);
  const show = (id, flex) => { const el = _q(id); if(el) el.style.display = flex || 'block'; };
  const hide = id => { const el = _q(id); if(el) el.style.display = 'none'; };
  const text = (id, t) => { const el = _q(id); if(el) el.textContent = t; };
  const html = (id, h) => { const el = _q(id); if(el) el.innerHTML = h; };
  const setDisabled = (id, v) => { const el = _q(id); if(el) el.disabled = v; };

  // ── Panel switching ────────────────────────────────────────────────────────
  function showPanel(name) {
    ['welcome','progress','complete','failed'].forEach(p => hide('panel-'+p));
    if (name) show('panel-'+name, 'block');
  }

  // ── Stage list render ──────────────────────────────────────────────────────
  function renderStages() {
    const ul = _q('stage-list');
    if (!ul) return;
    ul.innerHTML = '';
    const ordinal = {preflight:0,composer_bootstrap:1,download:2,verify:3,configure:4};
    const phaseOrd = {preflight:0,download:1,verify:2,extract:3,configure:4,complete:5,failed:6};
    const curOrd = phaseOrd[state.phase] ?? 0;

    state.stages.forEach((s, i) => {
      const st = state.stageStatus[s.name] || 'pending';
      const li = document.createElement('li');
      li.className = 'stage-item' + (st === 'running' ? ' active' : (st === 'pending' && (ordinal[s.name] ?? i) === curOrd ? ' active' : ''));
      const icon = st === 'success' ? '✓' : st === 'failed' ? '✗' : st === 'running' ? '…' : st === 'skipped' ? '–' : String(i+1);
      li.innerHTML = '<span class="si '+st+'">'+icon+'</span><span>'+s.label+'</span>';
      ul.appendChild(li);
    });
  }

  // ── Log terminal ───────────────────────────────────────────────────────────
  function appendLog(text, cls) {
    const term = _q('terminal');
    const ftail = _q('failed-terminal');
    const line = '<span class="ll ' + (cls||'') + '">' + escHtml(text) + '</span>';
    state.logLines.push({text, cls});
    if (state.logLines.length > 600) state.logLines.shift();
    if (term) { term.innerHTML += line; term.scrollTop = term.scrollHeight; }
    if (ftail) { ftail.innerHTML += line; ftail.scrollTop = ftail.scrollHeight; }
  }

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function clearLog() {
    state.logLines = [];
    if (_q('terminal')) _q('terminal').innerHTML = '';
    if (_q('failed-terminal')) _q('failed-terminal').innerHTML = '';
  }

  // ── Status banner ──────────────────────────────────────────────────────────
  function setStatus(cls, msg) {
    const el = _q('status-banner');
    if (!el) return;
    if (!msg) { el.style.display='none'; return; }
    el.className = 'banner ' + cls;
    el.textContent = msg;
    el.style.display = 'flex';
  }

  // ── Progress bar ───────────────────────────────────────────────────────────
  function updateProgress() {
    const done = Object.values(state.stageStatus).filter(v => v==='success'||v==='skipped').length;
    const pct = state.stages.length ? Math.round(done/state.stages.length*100) : 0;
    const fill = _q('prog-fill');
    if (fill) fill.style.width = pct + '%';
    text('progress-pct', pct + '%');
    const idx = state.stages.findIndex(s => {
      const v = state.stageStatus[s.name];
      return !v || v === 'pending' || v === 'running';
    });
    const cur = state.stages[idx] || state.stages[state.stages.length-1];
    if (cur) {
      text('progress-title', cur.label || 'Installing…');
      text('progress-sub', 'Step ' + (idx+1) + ' of ' + state.stages.length);
      text('btn-next', idx === 0 ? 'Start' : 'Continue');
    }
  }

  // ── Codename ───────────────────────────────────────────────────────────────
  function resolveCodename(version) {
    if (!version) return;
    const major = parseInt(version.split('.')[0], 10);
    const name = state.codenames[major] || null;
    state.wkVersion = version;
    state.wkCodename = name ? 'Webkernel ' + name : null;
    if (state.wkCodename) {
      text('topbar-codename', state.wkCodename);
      const cc = _q('complete-codename');
      if (cc) {
        text('complete-codename-text', state.wkCodename + (version ? ' — v' + version : ''));
        cc.style.display = 'block';
      }
    }
  }

  // ── Theme ──────────────────────────────────────────────────────────────────
  let dark = true;
  function applyTheme(isDark) {
    dark = isDark;
    document.documentElement.classList.toggle('light', !isDark);
    const sun = _q('theme-sun'), moon = _q('theme-moon');
    if (sun) sun.style.display = isDark ? 'block' : 'none';
    if (moon) moon.style.display = isDark ? 'none' : 'block';
  }
  function toggleTheme() {
    // Enable transition only on manual toggle, not on initial load
    document.documentElement.style.transition = 'background .2s, color .2s';
    applyTheme(!dark);
    try { localStorage.setItem('wk_theme', dark ? 'dark' : 'light'); } catch(_){}
    setTimeout(() => { document.documentElement.style.transition = ''; }, 300);
  }

  // ── Mobile menu ────────────────────────────────────────────────────────────
  function openMobile() {
    const sb = _q('sidebar'), ov = _q('mob-overlay');
    if (sb) sb.classList.add('open');
    if (ov) ov.classList.add('open');
  }
  function closeMobile() {
    const sb = _q('sidebar'), ov = _q('mob-overlay');
    if (sb) sb.classList.remove('open');
    if (ov) ov.classList.remove('open');
  }

  // ── API ────────────────────────────────────────────────────────────────────
  async function api(body) {
    const r = await fetch(location.pathname, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(body)
    });
    const txt = await r.text();
    let parsed;
    try { parsed = JSON.parse(txt); }
    catch(_) {
      const preview = txt.slice(0, 200).replace(/<[^>]+>/g,' ').trim();
      throw new Error('Invalid JSON from server: ' + preview);
    }
    if (!parsed.ok && parsed.error) throw new Error(parsed.error);
    return parsed;
  }

  // ── Init ───────────────────────────────────────────────────────────────────
  function init() {
    // App is only sent by the server when the user is authenticated.
    // No lock screen logic needed here.
    show('main-app');

    try {
      // Sidebar target
      const t = state.target;
      const el = _q('sidebar-target');
      if (el) { el.textContent = t.split('/').pop() || t; el.title = t; }

      // Theme
      try {
        const saved = localStorage.getItem('wk_theme');
        applyTheme(saved !== 'light');
      } catch(_) { applyTheme(true); }

      // Boot data
      const boot = window.__WK_BOOT || {};

      // Already installed?
      if (boot.alreadyInstalled) {
        state.phase = 'complete';
        if (boot.wkVersion) resolveCodename(boot.wkVersion);
        showPanel('complete');
        renderStages();
        loadSessions();
        return;
      }

      // Restore session from cookie
      if (boot.session && boot.session.phase) {
        state.sid   = boot.session.id;
        state.token = boot.session.token;
        state.phase = boot.session.phase;
        restoreStageStatuses(boot.session.phase);
        renderByPhase();
        loadSessions();
        return;
      }

      showPanel('welcome');
      renderStages();
      loadSessions();

    } catch(e) {
      // Last resort: show something rather than blank screen
      const main = _q('main-panel');
      if (main) main.innerHTML = '<div class="card"><div class="cb" style="color:var(--danger);font-family:monospace;font-size:13px;padding:24px">'
        + '<strong>Installer init error:</strong><br>' + e.message
        + '<br><br>Check browser console for details.</div></div>';
      console.error('wk.init() failed:', e);
    }
  }

  function restoreStageStatuses(phase) {
    const phaseOrd = {preflight:0,download:1,verify:2,extract:3,configure:4,complete:5,failed:6};
    const cur = phaseOrd[phase] ?? 0;
    if (phase === 'complete') {
      state.stages.forEach(s => { state.stageStatus[s.name] = 'success'; });
    }
    // else leave as pending — user will see current state
  }

  function renderByPhase() {
    if (state.phase === 'complete') { showPanel('complete'); }
    else if (state.phase === 'failed') { showPanel('failed'); }
    else {
      showPanel('progress');
      updateProgress();
    }
    renderStages();
  }

  // ── Session management ─────────────────────────────────────────────────────
  async function loadSessions() {
    try {
      const d = await api({action:'list_sessions'});
      if (d.ok) renderSessions(d.sessions || []);
    } catch(_) {}
  }

  function renderSessions(sessions) {
    const list = _q('sess-list');
    const empty = _q('sess-empty');
    const delAll = _q('sess-del-all-btn');
    if (!list) return;

    list.innerHTML = '';
    const old = sessions.filter(s => s.id !== state.sid);

    if (sessions.length === 0) {
      if (empty) { list.appendChild(empty); empty.style.display='block'; }
    } else {
      sessions.forEach(s => {
        const row = document.createElement('div');
        row.className = 'sess-row' + (s.id === state.sid ? ' active' : '');
        row.dataset.id = s.id;

        const dot = document.createElement('span');
        dot.className = 'sess-dot';
        dot.style.background = phaseColor(s.phase);

        const click = document.createElement('div');
        click.className = 'sess-click';
        click.title = 'Resume ' + s.id;
        click.onclick = () => resumeSession(s);
        click.innerHTML = '<div class="sess-info"><div class="sess-id">'+s.id.slice(0,14)+'…</div>'
          +'<div class="sess-meta">'+phaseLabel(s.phase)+' · '+relTime(s.last_activity)+'</div></div>'
          +(s.id===state.sid?'<span class="sess-active-tag">ACTIVE</span>':'');
        click.prepend(dot);

        const del = document.createElement('button');
        del.className = 'sess-del-btn';
        del.title = 'Delete session';
        del.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';
        del.onclick = e => { e.stopPropagation(); deleteSession(s); };

        row.appendChild(click);
        row.appendChild(del);
        list.appendChild(row);
      });
    }

    if (delAll) {
      if (old.length > 0) {
        delAll.style.display = 'flex';
        text('sess-del-all-label', 'Delete ' + old.length + ' old session'+(old.length>1?'s':''));
      } else {
        delAll.style.display = 'none';
      }
    }
  }

  async function resumeSession(s) {
    if (s.id === state.sid) return;
    state.sid = s.id; state.phase = s.phase;
    state.stageStatus = {};
    restoreStageStatuses(s.phase);
    document.cookie = 'wk_session='+s.id+';path=/;max-age=86400;samesite=Strict';
    clearLog();
    setStatus('', '');
    await refresh();
    closeMobile();
  }

  async function deleteSession(s) {
    if (s.id === state.sid) {
      if (!confirm('This is your active session. Reset it?')) return;
      await reset(); return;
    }
    await api({action:'reset', session_id:s.id}).catch(()=>{});
    await loadSessions();
  }

  async function deleteOldSessions() {
    const d = await api({action:'list_sessions'}).catch(()=>({ok:false,sessions:[]}));
    const old = (d.sessions||[]).filter(s=>s.id!==state.sid);
    if (old.length === 0) return;
    if (!confirm('Delete ' + old.length + ' old session(s)?')) return;
    for (const s of old) await api({action:'reset',session_id:s.id}).catch(()=>{});
    await loadSessions();
  }

  function phaseColor(phase) {
    const m = {complete:'#4ade80',failed:'#f87171',download:'#22d3ee',configure:'#a78bfa'};
    return m[phase] || 'var(--text-dim)';
  }
  function phaseLabel(phase) {
    const m = {idle:'Idle',preflight:'Preparation',download:'Download',verify:'Verification',extract:'Extraction',configure:'Configuration',complete:'Complete',failed:'Failed'};
    return m[phase] || phase;
  }
  function relTime(ts) {
    if (!ts) return '';
    const d = Math.floor(Date.now()/1000 - ts);
    if (d < 60) return d+'s ago';
    if (d < 3600) return Math.floor(d/60)+'m ago';
    if (d < 86400) return Math.floor(d/3600)+'h ago';
    return Math.floor(d/86400)+'d ago';
  }

  // ── Installation flow ──────────────────────────────────────────────────────
  async function start() {
    state.busy = true;
    setDisabled('btn-start', true);
    try {
      const d = await api({action:'init'});
      state.sid = d.session_id;
      state.token = d.token;
      state.phase = d.phase;
      state.stages = d.stages;
      state.stageStatus = {};
      clearLog();
      appendLog('Session started: ' + state.sid, '');
      showPanel('progress');
      renderStages();
      updateProgress();
      await loadSessions();
    } catch(e) {
      setStatus('danger', e.message);
    }
    state.busy = false;
    setDisabled('btn-start', false);
  }

  async function runNext() {
    const s = state.stages.find(s => {
      const v = state.stageStatus[s.name];
      return !v || v === 'pending';
    });
    if (s) await execStage(s.name);
  }

  async function runAll() {
    for (const s of state.stages) {
      const v = state.stageStatus[s.name];
      if (v === 'success' || v === 'skipped') continue;
      const ok = await execStage(s.name);
      if (!ok) break;
    }
  }

  async function execStage(name) {
    if (state.busy) return false;
    state.busy = true;
    state.stageStatus[name] = 'running';
    renderStages();
    setDisabled('btn-next', true);
    setDisabled('btn-all', true);

    const stageLbl = (state.stages.find(s=>s.name===name)||{label:name}).label;

    try {
      const d = await api({action:'run_stage', stage:name, token:state.token, session_id:state.sid});
      (d.log||[]).forEach(l => {
        const cls = l.toLowerCase().includes('error') ? 'error'
          : l.toLowerCase().includes('warning') ? 'warning'
          : (l.toLowerCase().includes('ok')||l.toLowerCase().includes('success')) ? 'success' : '';
        appendLog('['+stageLbl+'] '+l, cls);
        const m = l.match(/webkernel\/webkernel[^(]*\(v?([\d]+\.[\d]+\.[\d]+)/i);
        if (m) resolveCodename(m[1]);
      });
      state.stageStatus[name] = d.status || 'failed';
      state.phase = d.phase || state.phase;
      if (d.phase === 'complete' && d.wk_version) resolveCodename(d.wk_version);

      if (d.status === 'failed') {
        setStatus('danger', d.message);
        text('failed-msg', d.message || '');
        showPanel('failed');
      } else if (d.status === 'skipped') {
        setStatus('warning', d.message);
      } else {
        setStatus('success', d.message);
      }

      if (d.phase === 'complete') { showPanel('complete'); }
      else if (d.phase === 'failed') { showPanel('failed'); }

      renderStages();
      updateProgress();
      state.busy = false;
      setDisabled('btn-next', false);
      setDisabled('btn-all', false);
      return d.status !== 'failed';

    } catch(e) {
      state.stageStatus[name] = 'failed';
      state.phase = 'failed';
      setStatus('danger', e.message);
      appendLog('['+stageLbl+'] Error: '+e.message, 'error');
      text('failed-msg', e.message);
      showPanel('failed');
      renderStages();
      state.busy = false;
      setDisabled('btn-next', false);
      setDisabled('btn-all', false);
      return false;
    }
  }

  async function refresh() {
    if (!state.sid) return;
    try {
      const d = await api({action:'status', session_id:state.sid});
      if (!d.ok) return;
      state.phase = d.phase;
      (d.log||[]).slice(-60).forEach(l => appendLog(l, ''));
      renderByPhase();
    } catch(_) {}
  }

  async function reset() {
    if (!confirm('Reset this session? All progress will be lost.')) return;
    await api({action:'reset', session_id:state.sid}).catch(()=>{});
    state.sid = null; state.token = null; state.phase = 'idle';
    state.stageStatus = {}; state.wkVersion = null; state.wkCodename = null;
    clearLog();
    setStatus('','');
    text('topbar-codename', 'Waterfall series');
    showPanel('welcome');
    renderStages();
    await loadSessions();
  }

  async function deleteInstaller() {
    if (state.deleting) return;
    if (!confirm('Delete install.php from the server permanently?')) return;
    state.deleting = true;
    setDisabled('btn-delete-installer', true);
    try {
      const d = await api({action:'delete_installer'});
      const msg = _q('delete-msg');
      if (msg) { msg.textContent = d.ok ? 'install.php deleted.' : (d.error||'Could not delete.'); msg.style.display='block'; }
    } catch(e) {
      const msg = _q('delete-msg');
      if (msg) { msg.textContent = e.message; msg.style.display='block'; }
    }
    state.deleting = false;
  }

  // ── Password modal ─────────────────────────────────────────────────────────
  function openPwModal() {
    const m = _q('pw-modal');
    if (m) m.classList.add('open');
    closeMobile();
    // Decide initial step
    // (We don't know server state here; start at 'form' always — user can navigate)
    pwStep('form');
  }
  function closePwModal() {
    const m = _q('pw-modal');
    if (m) m.classList.remove('open');
  }
  function pwStep(step) {
    ['form','recovery','done','remove'].forEach(s => hide('pw-step-'+s));
    show('pw-step-'+step, 'block');
    if (step === 'form') {
      const inp = _q('pw-input'); if(inp) inp.value = '';
      const ci = _q('pw-confirm-input'); if(ci) ci.value = '';
      const ck = _q('pw-confirm-check'); if(ck) ck.checked = false;
      hide('pw-confirm-group');
      hide('pw-confirm-label');
      hide('pw-mismatch');
      setDisabled('pw-save-btn', true);
    }
  }
  function onPwInput() {
    const pw = _q('pw-input')?.value || '';
    const pw2 = _q('pw-confirm-input')?.value || '';
    const ck = _q('pw-confirm-check')?.checked || false;
    const cg = _q('pw-confirm-group');
    const cl = _q('pw-confirm-label');
    const mm = _q('pw-mismatch');
    const btn = _q('pw-save-btn');
    if (cg) cg.style.display = pw.length >= 8 ? 'flex' : 'none';
    if (cl) cl.style.display = (pw.length >= 8 && pw === pw2) ? 'flex' : 'none';
    if (mm) mm.style.display = (pw2.length > 0 && pw !== pw2) ? 'block' : 'none';
    if (btn) btn.disabled = !(pw.length >= 8 && pw === pw2 && ck);
  }
  function toggleEye(inputId, btnId, showId, hideId) {
    const inp = _q(inputId);
    if (!inp) return;
    const isPass = inp.type === 'password';
    inp.type = isPass ? 'text' : 'password';
    if (showId) { const el=_q(showId); if(el) el.style.display = isPass ? 'none' : 'block'; }
    if (hideId) { const el=_q(hideId); if(el) el.style.display = isPass ? 'block' : 'none'; }
  }
  async function savePassword() {
    const pw = _q('pw-input')?.value || '';
    try {
      const d = await api({action:'set_password', password:pw, confirmed:true});
      text('pw-recovery-key-display', d.recovery_key || '');
      pwStep('recovery');
      appendLog('[Security] Password protection enabled.', 'success');
    } catch(e) {
      appendLog('[Security] ' + e.message, 'error');
    }
  }
  async function removePassword() {
    try {
      await api({action:'remove_password'});
      pwStep('form');
      appendLog('[Security] Password protection removed.', 'warning');
    } catch(e) {
      appendLog('[Security] ' + e.message, 'error');
    }
  }
  function pwSavedKey() {
    // User confirmed they saved the recovery key.
    // Reload the page — the gate is now active, user will be re-authenticated
    // via the cookie set during savePassword().
    location.reload();
  }

  // ── Lock screen ────────────────────────────────────────────────────────────

  // ── Public API ─────────────────────────────────────────────────────────────
  return {
    init, start, runNext, runAll, refresh, reset, deleteInstaller,
    openPwModal, closePwModal, pwStep, onPwInput, toggleEye,
    savePassword, removePassword, pwSavedKey, deleteOldSessions,
    toggleTheme,
  };
})();

// ── DOM ready ──────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  wk.init();

  // Theme toggle
  const tb = document.getElementById('theme-btn');
  if (tb) tb.onclick = wk.toggleTheme;

  // Mobile menu
  const mb = document.getElementById('mob-menu-btn');
  const mc = document.getElementById('mob-close-btn');
  const ov = document.getElementById('mob-overlay');
  if (mb) mb.onclick = () => {
    const sb = document.getElementById('sidebar');
    const o  = document.getElementById('mob-overlay');
    if(sb) sb.classList.toggle('open');
    if(o)  o.classList.toggle('open');
  };
  if (mc) mc.onclick = () => {
    const sb = document.getElementById('sidebar');
    const o  = document.getElementById('mob-overlay');
    if(sb) sb.classList.remove('open');
    if(o)  o.classList.remove('open');
  };
  if (ov) ov.onclick = () => {
    const sb = document.getElementById('sidebar');
    if(sb) sb.classList.remove('open');
    ov.classList.remove('open');
  };

  // Modal close on backdrop
  const pm = document.getElementById('pw-modal');
  if (pm) pm.onclick = e => { if(e.target===pm) wk.closePwModal(); };

  // ESC closes modal
  document.onkeydown = e => { if(e.key==='Escape') wk.closePwModal(); };
});
</script>
</body>
</html>

HTML;
    }

    // -------------------------------------------------------------------------
    // Landing page (served at /)
    // -------------------------------------------------------------------------

    private function sendLandingPage(): never
    {
        $year    = date('Y');
        $host    = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'this domain', ENT_QUOTES, 'UTF-8');

        $logoLight   = 'https://raw.githubusercontent.com/numerimondes/.github/refs/heads/main/assets/brands/webkernel/logo.png';
        $logoDark    = 'https://raw.githubusercontent.com/numerimondes/.github/refs/heads/main/assets/brands/webkernel/logo-dark.png';
        $faviconUrl  = 'https://raw.githubusercontent.com/numerimondes/.github/refs/heads/main/assets/brands/webkernel/favicon.ico.png';

        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Webkernel — Demo Environment</title>
<link rel="icon" type="image/png" href="{$faviconUrl}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #000;
    --card:      #0d0d0d;
    --card-head: #080808;
    --border:    #1a1a1a;
    --accent:    #3b82f6;
    --accent-bg: rgba(59,130,246,.1);
    --text:      #d0d0d0;
    --muted:     #555;
    --dim:       #888;
  }

  html, body {
    min-height: 100%;
    background: var(--bg);
    color: var(--text);
    font-family: 'Space Grotesk', system-ui, sans-serif;
    font-size: 14px;
    line-height: 1.6;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
  }

  .card {
    width: 100%;
    max-width: 540px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
  }

  /* ── header ── */
  .card-header {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    background: var(--card-head);
    border-bottom: 1px solid var(--border);
    padding: 10px 16px;
    gap: 8px;
  }
  .card-header .col { display: flex; flex-direction: column; gap: 2px; }
  .col-label  { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
  .col-value  { font-size: 12px; font-weight: 500; }
  .col-value.ok     { color: #22c55e; }
  .col-value.demo   { color: var(--accent); }
  .col-value.notice { color: #f59e0b; }

  /* ── body ── */
  .card-body { padding: 24px 20px; }

  .brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
  }
  .brand-logo {
    height: 32px;
    width: auto;
    display: block;
  }
  .brand-by {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 2px;
  }
  .brand-by span {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--muted);
  }
  .brand-by picture,
  .brand-by img {
    height: 20px;
    width: auto;
    display: block;
    opacity: .75;
  }

  .incident-title {
    font-size: 18px;
    font-weight: 600;
    color: #fff;
    margin-bottom: 12px;
    letter-spacing: -.01em;
  }

  .msg-block {
    background: var(--accent-bg);
    border-left: 2px solid var(--accent);
    border-radius: 0 4px 4px 0;
    padding: 12px 14px;
    font-size: 13px;
    color: var(--dim);
    margin-bottom: 20px;
  }
  .msg-block strong { color: var(--text); }

  .step-list {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .step-list li {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 13px;
    color: var(--dim);
  }
  .step-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--accent);
    margin-top: 6px;
    flex-shrink: 0;
  }

  /* ── footer ── */
  .card-footer {
    padding: 12px 20px;
    border-top: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 11px;
    color: var(--muted);
  }
</style>
</head>
<body>
<div class="card">

  <div class="card-header">
    <div class="col">
      <span class="col-label">Status</span>
      <span class="col-value ok">Operational</span>
    </div>
    <div class="col">
      <span class="col-label">Environment</span>
      <span class="col-value demo">Demo</span>
    </div>
    <div class="col">
      <span class="col-label">Purpose</span>
      <span class="col-value notice">Testing only</span>
    </div>
  </div>

  <div class="card-body">
    <div class="brand">
      <picture>
        <source media="(prefers-color-scheme: light)" srcset="{$logoLight}">
        <img src="{$logoDark}" alt="Webkernel" class="brand-logo">
      </picture>
      <div class="brand-by">
        <span>by</span>
        <picture>
          <source media="(prefers-color-scheme: light)" srcset="https://raw.githubusercontent.com/numerimondes/.github/refs/heads/main/assets/brands/numerimondes/MARS2026_REBRAND/logo-officiel.png">
          <img src="https://raw.githubusercontent.com/numerimondes/.github/refs/heads/main/assets/brands/numerimondes/MARS2026_REBRAND/logo-for-dark-mode.png" alt="Numerimondes">
        </picture>
      </div>
    </div>

    <p class="incident-title">{$host} — demo &amp; testing environment.</p>

    <div class="msg-block">
      <strong>Not a production environment.</strong> This instance runs
      Webkernel (by Numerimondes) for evaluation and demonstration purposes
      only. Data is non-persistent and may be reset without notice.
    </div>

    <ul class="step-list">
      <li><span class="step-dot"></span> This domain is reserved for internal demo use</li>
      <li><span class="step-dot"></span> No user data is stored or processed here</li>
      <li><span class="step-dot"></span> All activity on this domain is logged for security purposes</li>
    </ul>
  </div>

  <div class="card-footer">
    <span>Webkernel &mdash; by Numerimondes</span>
    <span>&copy; {$year}</span>
  </div>

</div>
</body>
</html>
HTML;
        exit(0);
    }
}

// ---------------------------------------------------------------------------
// Entry
// ---------------------------------------------------------------------------

(new InstallerKernel())->run();

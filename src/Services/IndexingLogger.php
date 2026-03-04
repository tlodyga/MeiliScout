<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use function current_time;
use function delete_transient;
use function get_transient;
use function set_transient;
use function wp_generate_password;
use function wp_mkdir_p;

/**
 * Secure file-based logging service for indexation operations.
 *
 * Uses a token-based system for security and file-based storage for performance.
 * Logs are written to a protected directory with restricted access.
 */
class IndexingLogger
{
    /**
     * Transient key for storing the current session token.
     */
    private const TOKEN_TRANSIENT_KEY = 'meiliscout_indexing_token';

    /**
     * Transient key for storing the current log file path.
     */
    private const LOG_PATH_TRANSIENT_KEY = 'meiliscout_indexing_log_path';

    /**
     * Token expiration time in seconds (1 hour).
     */
    private const TOKEN_EXPIRATION = 3600;

    /**
     * Number of log entries to buffer before writing to file.
     */
    private const BUFFER_SIZE = 5;

    /**
     * Time interval in seconds between forced flushes.
     */
    private const FLUSH_INTERVAL = 2;

    /**
     * Current session token.
     */
    private ?string $token = null;

    /**
     * Path to the current log file.
     */
    private ?string $logFilePath = null;

    /**
     * In-memory log buffer.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $buffer = [];

    /**
     * Current log data structure.
     *
     * @var array<string, mixed>
     */
    private array $logData = [];

    /**
     * Timestamp of last flush.
     */
    private int $lastFlushTime = 0;

    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    /**
     * Gets the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Private constructor for singleton pattern.
     */
    private function __construct()
    {
        $this->lastFlushTime = time();
    }

    /**
     * Initializes a new logging session with a secure token.
     *
     * @return string The session token for accessing logs
     */
    public function initializeSession(): string
    {
        // Generate a secure token
        $this->token = wp_generate_password(32, false, false);

        // Create the log directory if needed
        $logDir = $this->getLogDirectory();
        if (! is_dir($logDir)) {
            wp_mkdir_p($logDir);
            $this->protectLogDirectory($logDir);
        }

        // Clean up old log files (older than 24 hours)
        $this->cleanupOldLogs($logDir);

        // Create the log file path
        $this->logFilePath = $logDir . '/indexation-' . $this->token . '.json';

        // Initialize log data structure
        $this->logData = [
            'token' => $this->token,
            'start_time' => current_time('mysql'),
            'end_time' => null,
            'status' => 'pending',
            'entries' => [],
        ];

        // Store token and path in transients for fast access
        set_transient(self::TOKEN_TRANSIENT_KEY, $this->token, self::TOKEN_EXPIRATION);
        set_transient(self::LOG_PATH_TRANSIENT_KEY, $this->logFilePath, self::TOKEN_EXPIRATION);

        // Write initial log file
        $this->writeLogFile();

        return $this->token;
    }

    /**
     * Adds a log entry.
     *
     * @param string $type The log type (info, success, error, warning)
     * @param string $message The log message
     * @param bool $forceFlush Force immediate write to file
     */
    public function log(string $type, string $message, bool $forceFlush = false): void
    {
        $entry = [
            'type' => $type,
            'message' => $message,
            'time' => current_time('mysql'),
        ];

        $this->buffer[] = $entry;
        $this->logData['entries'][] = $entry;

        // Determine if we should flush
        $shouldFlush = $forceFlush
            || $type === 'error'
            || count($this->buffer) >= self::BUFFER_SIZE
            || (time() - $this->lastFlushTime) >= self::FLUSH_INTERVAL;

        if ($shouldFlush) {
            $this->flush();
        }
    }

    /**
     * Flushes the buffer to the log file.
     */
    public function flush(): void
    {
        if ($this->logFilePath === null) {
            return;
        }

        $this->logData['end_time'] = current_time('mysql');
        $this->writeLogFile();
        $this->buffer = [];
        $this->lastFlushTime = time();
    }

    /**
     * Marks the logging session as completed.
     *
     * @param string $status Final status (completed, error)
     */
    public function complete(string $status = 'completed'): void
    {
        $this->logData['status'] = $status;
        $this->logData['end_time'] = current_time('mysql');
        $this->flush();
    }

    /**
     * Gets the current session token.
     *
     * @return string|null The current token or null if no session
     */
    public function getToken(): ?string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        return get_transient(self::TOKEN_TRANSIENT_KEY) ?: null;
    }

    /**
     * Gets log data by token.
     *
     * @param string $token The session token
     * @return array<string, mixed>|null Log data or null if invalid/not found
     */
    public function getLogByToken(string $token): ?array
    {
        // Validate token format (alphanumeric, 32 chars)
        if (! preg_match('/^[a-zA-Z0-9]{32}$/', $token)) {
            return null;
        }

        // Check if this is the current session token
        $currentToken = get_transient(self::TOKEN_TRANSIENT_KEY);
        if ($token !== $currentToken) {
            return null;
        }

        // Get the log file path
        $logFilePath = get_transient(self::LOG_PATH_TRANSIENT_KEY);
        if (! $logFilePath || ! file_exists($logFilePath)) {
            return null;
        }

        // Read and parse the log file
        $content = file_get_contents($logFilePath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (! is_array($data) || ($data['token'] ?? '') !== $token) {
            return null;
        }

        return $data;
    }

    /**
     * Gets the current log data (for the active session).
     *
     * @return array<string, mixed>|null Log data or null if no active session
     */
    public function getCurrentLog(): ?array
    {
        $token = $this->getToken();
        if ($token === null) {
            return null;
        }

        return $this->getLogByToken($token);
    }

    /**
     * Clears the current session.
     */
    public function clearSession(): void
    {
        delete_transient(self::TOKEN_TRANSIENT_KEY);
        delete_transient(self::LOG_PATH_TRANSIENT_KEY);
        $this->token = null;
        $this->logFilePath = null;
        $this->buffer = [];
        $this->logData = [];
    }

    /**
     * Gets the log directory path.
     *
     * @return string The log directory path
     */
    private function getLogDirectory(): string
    {
        $uploadDir = wp_upload_dir();
        $defaultDir = $uploadDir['basedir'] . '/meiliscout/logs';

        return apply_filters('meiliscout/log_directory', $defaultDir);
    }

    /**
     * Protects the log directory from direct web access.
     *
     * @param string $directory The directory path
     */
    private function protectLogDirectory(string $directory): void
    {
        // Create .htaccess to deny direct access
        $htaccess = $directory . '/.htaccess';
        if (! file_exists($htaccess)) {
            file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }

        // Create index.php to prevent directory listing
        $indexFile = $directory . '/index.php';
        if (! file_exists($indexFile)) {
            file_put_contents($indexFile, "<?php\n// Silence is golden.\n");
        }

        // Create .gitignore to exclude logs from version control
        $gitignore = $directory . '/.gitignore';
        if (! file_exists($gitignore)) {
            file_put_contents($gitignore, "*\n!.htaccess\n!index.php\n!.gitignore\n");
        }
    }

    /**
     * Writes the current log data to the file.
     */
    private function writeLogFile(): void
    {
        if ($this->logFilePath === null) {
            return;
        }

        $json = json_encode($this->logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            file_put_contents($this->logFilePath, $json, LOCK_EX);
        }
    }

    /**
     * Cleans up old log files.
     *
     * @param string $directory The log directory
     * @param int $maxAge Maximum age in seconds (default: 24 hours)
     */
    private function cleanupOldLogs(string $directory, int $maxAge = 86400): void
    {
        $files = glob($directory . '/indexation-*.json');
        if ($files === false) {
            return;
        }

        $now = time();
        foreach ($files as $file) {
            if (($now - filemtime($file)) > $maxAge) {
                @unlink($file);
            }
        }
    }

    /**
     * Validates a token without retrieving log data.
     *
     * @param string $token The token to validate
     * @return bool True if valid, false otherwise
     */
    public function isValidToken(string $token): bool
    {
        // Validate format
        if (! preg_match('/^[a-zA-Z0-9]{32}$/', $token)) {
            return false;
        }

        // Check against current session token
        $currentToken = get_transient(self::TOKEN_TRANSIENT_KEY);

        return $token === $currentToken;
    }
}

#!/usr/bin/env php
<?php
declare(strict_types=1);

const AGENT_VERSION = '0.1.0';

$agentDir = __DIR__;

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

function timestamp(): string
{
    return date('Y-m-d-H-i-s');
}

function read_json_file(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON file: {$file}");
    }
    return $data;
}

function is_absolute_path(string $path): bool
{
    return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
}

function resolve_config_path(string $path, string $base): string
{
    if ($path === '') {
        return $base;
    }
    return is_absolute_path($path) ? $path : rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $path;
}

function load_config(string $wpRoot, string $agentDir): array
{
    $defaults = [
        'backupDir' => '../wp-backups',
        'siteUrl' => '',
        'updatePlugins' => true,
        'updateActiveTheme' => false,
        'updateAllThemes' => false,
        'clearCache' => true,
        'verifyUrls' => ['/', '/wp-login.php'],
        'autoRollbackOnFailure' => false,
        'keepBackups' => 2,
        'wpCliBinary' => 'wp',
        'tarBinary' => 'tar',
        'lockFile' => $agentDir . DIRECTORY_SEPARATOR . 'wp-agent.lock',
        'lockTimeoutMinutes' => 120,
        'cronDefaultCommand' => 'status',
        'notificationEmail' => '',
        'notificationFrom' => '',
        'logsDir' => $agentDir . DIRECTORY_SEPARATOR . 'logs',
        'reportsDir' => $agentDir . DIRECTORY_SEPARATOR . 'reports',
    ];

    $config = array_merge(
        $defaults,
        read_json_file($agentDir . DIRECTORY_SEPARATOR . 'wp-agent.config.json'),
        read_json_file($wpRoot . DIRECTORY_SEPARATOR . 'wp-agent.config.json')
    );
    $config['backupDir'] = resolve_config_path((string) $config['backupDir'], $wpRoot);
    $config['logsDir'] = resolve_config_path((string) $config['logsDir'], $agentDir);
    $config['reportsDir'] = resolve_config_path((string) $config['reportsDir'], $agentDir);
    $config['lockFile'] = resolve_config_path((string) $config['lockFile'], $agentDir);
    $config['keepBackups'] = (int) ($config['keepBackups'] ?? 2);
    $config['lockTimeoutMinutes'] = (int) ($config['lockTimeoutMinutes'] ?? 120);
    $config['verifyUrls'] = is_array($config['verifyUrls'] ?? null) ? $config['verifyUrls'] : ['/', '/wp-login.php'];
    $config['wpCliBinary'] = (string) ($config['wpCliBinary'] ?? 'wp');
    $config['tarBinary'] = (string) ($config['tarBinary'] ?? 'tar');

    return $config;
}

function parse_args(array $argv): array
{
    $command = $argv[1] ?? 'help';
    $flags = [];
    for ($i = 2; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $key = substr($arg, 2);
        $next = $argv[$i + 1] ?? null;
        if ($next !== null && !str_starts_with($next, '--')) {
            $flags[$key] = $next;
            $i++;
        } else {
            $flags[$key] = true;
        }
    }
    return [$command, $flags];
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create directory: {$dir}");
    }
}

function create_run_files(array $config, string $command): array
{
    ensure_dir($config['logsDir']);
    ensure_dir($config['reportsDir']);
    $id = timestamp() . '-' . $command;
    return [
        'id' => $id,
        'logPath' => rtrim($config['logsDir'], '/\\') . DIRECTORY_SEPARATOR . $id . '.log',
        'reportPath' => rtrim($config['reportsDir'], '/\\') . DIRECTORY_SEPARATOR . $id . '.json',
    ];
}

function append_log(string $file, string $level, string $message): void
{
    $line = '[' . date('c') . '] ' . strtoupper($level) . ' ' . $message . PHP_EOL;
    file_put_contents($file, $line, FILE_APPEND);
    fwrite($level === 'error' ? STDERR : STDOUT, $message . PHP_EOL);
}

function write_report(string $file, array $report): void
{
    file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function disabled_php_functions(): array
{
    $raw = (string) ini_get('disable_functions');
    if ($raw === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', array_map('strtolower', explode(',', $raw)))));
}

function function_is_available(string $name): bool
{
    return function_exists($name) && !in_array(strtolower($name), disabled_php_functions(), true);
}

function shell_execution_status(): array
{
    $required = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open'];
    $disabled = disabled_php_functions();
    $functions = [];
    foreach ($required as $function) {
        $functions[$function] = [
            'available' => function_is_available($function),
            'disabled' => in_array($function, $disabled, true),
        ];
    }
    return [
        'ok' => function_is_available('exec'),
        'requiredForAgent' => 'exec',
        'functions' => $functions,
        'disabledFunctions' => $disabled,
    ];
}

function assert_shell_execution_available(): void
{
    $status = shell_execution_status();
    if (!$status['ok']) {
        throw new RuntimeException('Shell execution is unavailable because PHP exec() is disabled or unavailable. Backups and updates cannot run safely.');
    }
}

function run_cmd(string $command, bool $allowFailure = false): array
{
    assert_shell_execution_available();
    $lines = [];
    $status = 0;
    exec($command . ' 2>&1', $lines, $status);
    $output = trim(implode(PHP_EOL, $lines));
    if ($status !== 0 && !$allowFailure) {
        throw new RuntimeException("Command failed: {$command}" . ($output !== '' ? PHP_EOL . $output : ''));
    }
    return [
        'command' => $command,
        'status' => $status,
        'ok' => $status === 0,
        'output' => $output,
    ];
}

function write_command_output(string $file, string $command): string
{
    $result = run_cmd($command);
    file_put_contents($file, $result['output'] . PHP_EOL);
    return $result['output'];
}

function bin_cmd(array $config, string $key): string
{
    return escapeshellarg((string) $config[$key]);
}

function assert_wordpress_root(string $wpRoot): void
{
    $required = ['wp-config.php', 'wp-content', 'wp-admin', 'wp-includes'];
    $missing = [];
    foreach ($required as $entry) {
        if (!file_exists($wpRoot . DIRECTORY_SEPARATOR . $entry)) {
            $missing[] = $entry;
        }
    }
    if ($missing !== []) {
        throw new RuntimeException('Current directory is not a WordPress root. Missing: ' . implode(', ', $missing));
    }
}

function run_preflight_checks(string $wpRoot, array $config): array
{
    assert_wordpress_root($wpRoot);
    $wp = bin_cmd($config, 'wpCliBinary');
    $tar = bin_cmd($config, 'tarBinary');
    $shellStatus = shell_execution_status();
    if (!$shellStatus['ok']) {
        return [
            [
                'name' => 'shell execution',
                'ok' => false,
                'output' => 'PHP exec() is disabled or unavailable. Disabled functions: ' . implode(', ', $shellStatus['disabledFunctions']),
            ],
        ];
    }
    $checks = [
        ['disabled PHP functions', null],
        ['shell execution', escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo "shell-ok";')],
        ['PHP CLI', escapeshellarg(PHP_BINARY) . ' -v'],
        ['WP-CLI', $wp . ' --info'],
        ['tar', $tar . ' --version'],
        ['wp core version', $wp . ' core version'],
        ['wp plugin list', $wp . ' plugin list'],
        ['wp theme list', $wp . ' theme list'],
        ['disk space', 'df -h .'],
    ];
    $results = [];
    foreach ($checks as [$name, $command]) {
        if ($command === null) {
            $results[] = [
                'name' => $name,
                'ok' => $shellStatus['ok'],
                'output' => json_encode($shellStatus, JSON_UNESCAPED_SLASHES),
            ];
        } else {
            $result = run_cmd($command, true);
            $results[] = [
                'name' => $name,
                'ok' => $result['ok'],
                'output' => $result['output'],
            ];
        }
    }
    return $results;
}

function normalized_path(string $path): string
{
    $real = realpath($path);
    return rtrim($real !== false ? $real : $path, '/\\');
}

function is_path_inside(string $child, string $parent): bool
{
    $childPath = normalized_path($child);
    $parentPath = normalized_path($parent);
    return $childPath === $parentPath || str_starts_with($childPath, $parentPath . DIRECTORY_SEPARATOR) || str_starts_with($childPath, $parentPath . '/');
}

function relative_path(string $path, string $base): string
{
    $pathParts = explode('/', trim(str_replace('\\', '/', normalized_path($path)), '/'));
    $baseParts = explode('/', trim(str_replace('\\', '/', normalized_path($base)), '/'));
    while ($pathParts !== [] && $baseParts !== [] && $pathParts[0] === $baseParts[0]) {
        array_shift($pathParts);
        array_shift($baseParts);
    }
    return str_repeat('../', count($baseParts)) . implode('/', $pathParts);
}

function protect_backup_dir_if_public(array $config, string $wpRoot): ?string
{
    if (!is_path_inside($config['backupDir'], $wpRoot)) {
        return null;
    }
    file_put_contents(
        rtrim($config['backupDir'], '/\\') . DIRECTORY_SEPARATOR . '.htaccess',
        "Require all denied\nDeny from all\n"
    );
    return 'backupDir is inside the WordPress root. A protective .htaccess file was created, but backups should be moved outside public_html.';
}

function remove_dir_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        throw new RuntimeException("Could not read directory: {$dir}");
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            remove_dir_recursive($path);
        } elseif (!unlink($path)) {
            throw new RuntimeException("Could not delete file: {$path}");
        }
    }
    if (!rmdir($dir)) {
        throw new RuntimeException("Could not delete directory: {$dir}");
    }
}

function prune_backups(array $config, string $newestBackup, string $logPath): void
{
    $keep = (int) $config['keepBackups'];
    if ($keep === 0) {
        return;
    }
    $entries = scandir($config['backupDir']);
    if ($entries === false) {
        throw new RuntimeException('Could not read backup directory for pruning.');
    }
    $folders = [];
    foreach ($entries as $entry) {
        $folder = rtrim($config['backupDir'], '/\\') . DIRECTORY_SEPARATOR . $entry;
        if (
            is_dir($folder)
            && preg_match('/^site-backup-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$/', $entry)
            && is_file($folder . DIRECTORY_SEPARATOR . 'backup-manifest.json')
        ) {
            $folders[] = $folder;
        }
    }
    rsort($folders, SORT_STRING);
    foreach (array_slice($folders, $keep) as $folder) {
        if (normalized_path($folder) === normalized_path($newestBackup)) {
            continue;
        }
        append_log($logPath, 'info', "Pruning old backup {$folder}");
        remove_dir_recursive($folder);
    }
}

function create_backup(array $config, string $wpRoot, ?array $parentRun = null): array
{
    $run = $parentRun ?? create_run_files($config, 'backup');
    $startedAt = date('c');
    $report = [
        'command' => 'backup',
        'startedAt' => $startedAt,
        'endedAt' => null,
        'success' => false,
        'warnings' => [],
        'backupFolder' => null,
        'manifestPath' => null,
        'logPath' => $run['logPath'],
    ];

    try {
        assert_shell_execution_available();
        append_log($run['logPath'], 'info', 'Starting backup');
        $checks = run_preflight_checks($wpRoot, $config);
        $failed = array_values(array_filter($checks, function (array $check): bool {
            return !$check['ok'];
        }));
        if ($failed !== []) {
            throw new RuntimeException('Preflight failed: ' . implode(', ', array_column($failed, 'name')));
        }

        ensure_dir($config['backupDir']);
        $warning = protect_backup_dir_if_public($config, $wpRoot);
        if ($warning !== null) {
            $report['warnings'][] = $warning;
            append_log($run['logPath'], 'error', $warning);
        }

        $backupFolder = rtrim($config['backupDir'], '/\\') . DIRECTORY_SEPARATOR . 'site-backup-' . timestamp();
        ensure_dir($backupFolder);
        $report['backupFolder'] = $backupFolder;

        append_log($run['logPath'], 'info', 'Saving WordPress metadata');
        $wp = bin_cmd($config, 'wpCliBinary');
        $tar = bin_cmd($config, 'tarBinary');
        write_command_output($backupFolder . DIRECTORY_SEPARATOR . 'wordpress-version-before.txt', $wp . ' core version');
        write_command_output($backupFolder . DIRECTORY_SEPARATOR . 'plugins-before.json', $wp . ' plugin list --format=json');
        write_command_output($backupFolder . DIRECTORY_SEPARATOR . 'themes-before.json', $wp . ' theme list --format=json');

        append_log($run['logPath'], 'info', 'Exporting database');
        run_cmd($wp . ' db export ' . escapeshellarg($backupFolder . DIRECTORY_SEPARATOR . 'database.sql'));

        append_log($run['logPath'], 'info', 'Creating files archive');
        $tarCommand = $tar . ' -czf ' . escapeshellarg($backupFolder . DIRECTORY_SEPARATOR . 'files.tar.gz');
        if (is_path_inside($config['backupDir'], $wpRoot)) {
            $relativeBackup = relative_path($config['backupDir'], $wpRoot);
            $tarCommand .= ' --exclude=' . escapeshellarg('./' . ltrim($relativeBackup, './'));
            $tarCommand .= ' --exclude=' . escapeshellarg(ltrim($relativeBackup, './'));
        }
        $tarCommand .= ' --exclude=' . escapeshellarg('./wordpress-maintenance-agent/logs');
        $tarCommand .= ' --exclude=' . escapeshellarg('./wordpress-maintenance-agent/reports');
        $tarCommand .= ' .';
        run_cmd($tarCommand);

        $manifest = [
            'createdAt' => $startedAt,
            'wordpressRoot' => $wpRoot,
            'siteUrl' => $config['siteUrl'],
            'filesArchive' => 'files.tar.gz',
            'databaseDump' => 'database.sql',
            'pluginsList' => 'plugins-before.json',
            'themesList' => 'themes-before.json',
            'wordpressVersionFile' => 'wordpress-version-before.txt',
            'success' => true,
            'warnings' => $report['warnings'],
        ];
        $manifestPath = $backupFolder . DIRECTORY_SEPARATOR . 'backup-manifest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $report['success'] = true;
        $report['manifestPath'] = $manifestPath;

        try {
            prune_backups($config, $backupFolder, $run['logPath']);
        } catch (Throwable $error) {
            $report['warnings'][] = 'Backup pruning warning: ' . $error->getMessage();
            append_log($run['logPath'], 'error', 'Backup pruning warning: ' . $error->getMessage());
        }

        append_log($run['logPath'], 'info', "Backup completed: {$backupFolder}");
    } catch (Throwable $error) {
        $report['error'] = $error->getMessage();
        append_log($run['logPath'], 'error', $error->getMessage());
    } finally {
        $report['endedAt'] = date('c');
        write_report($run['reportPath'], $report);
    }

    if (!$report['success']) {
        throw new RuntimeException($report['error'] ?? 'Backup failed');
    }
    return $report;
}

function update_intent(array $config, array $flags): array
{
    return [
        'plugins' => !isset($flags['skip-plugins']) && (bool) $config['updatePlugins'],
        'allThemes' => isset($flags['all-themes']) || (bool) $config['updateAllThemes'],
        'activeTheme' => isset($flags['active-theme']) || (bool) $config['updateActiveTheme'],
    ];
}

function clear_common_caches(array $config, string $logPath): array
{
    $wp = bin_cmd($config, 'wpCliBinary');
    $plugins = ['litespeed-cache', 'w3-total-cache', 'wp-super-cache', 'wp-rocket', 'autoptimize'];
    $detected = [];
    foreach ($plugins as $plugin) {
        $result = run_cmd($wp . ' plugin is-active ' . escapeshellarg($plugin), true);
        if ($result['ok']) {
            $detected[] = $plugin;
        }
    }
    $actions = [];
    if ($detected !== []) {
        append_log($logPath, 'info', 'Detected cache plugins: ' . implode(', ', $detected));
        $actions[] = run_cmd($wp . ' cache flush', true);
        if (in_array('litespeed-cache', $detected, true)) {
            $actions[] = run_cmd($wp . ' litespeed-purge all', true);
        }
        if (in_array('wp-rocket', $detected, true)) {
            $actions[] = run_cmd($wp . ' rocket clean --confirm', true);
        }
    }
    return ['detected' => $detected, 'actions' => $actions];
}

function fetch_url_check(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 20,
            'ignore_errors' => true,
            'user_agent' => 'wp-maintenance-platform/' . AGENT_VERSION,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $statusCode = 0;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $statusCode = (int) $matches[1];
            break;
        }
    }
    $ok = $body !== false && $statusCode > 0 && $statusCode < 500;
    $sample = is_string($body) ? substr($body, 0, 500) : '';
    if (str_ends_with($url, '/wp-login.php') && $ok && !preg_match('/wp-login|login/i', $sample)) {
        $ok = false;
    }
    return [
        'url' => $url,
        'ok' => $ok,
        'statusCode' => $statusCode,
        'error' => $ok ? null : 'URL check failed or returned an unexpected response.',
    ];
}

function verify_site(array $config): array
{
    $siteUrl = rtrim((string) $config['siteUrl'], '/');
    if ($siteUrl === '') {
        return ['ok' => false, 'checks' => [], 'error' => 'siteUrl is not configured'];
    }
    $checks = [];
    foreach ($config['verifyUrls'] as $path) {
        $path = '/' . ltrim((string) $path, '/');
        $checks[] = fetch_url_check($siteUrl . $path);
    }
    return ['ok' => count(array_filter($checks, function (array $check): bool {
        return !$check['ok'];
    })) === 0, 'checks' => $checks];
}

function update_site(array $config, string $wpRoot, array $flags): array
{
    $run = create_run_files($config, 'update');
    $report = [
        'command' => 'update',
        'startedAt' => date('c'),
        'endedAt' => null,
        'success' => false,
        'needsManualReview' => false,
        'backup' => null,
        'pluginUpdate' => null,
        'themeUpdate' => null,
        'cache' => null,
        'verification' => null,
        'logPath' => $run['logPath'],
    ];

    try {
        assert_shell_execution_available();
        $wp = bin_cmd($config, 'wpCliBinary');
        $intent = update_intent($config, $flags);
        if (!$intent['allThemes'] && !$intent['activeTheme']) {
            append_log($run['logPath'], 'info', 'Theme updates are skipped by default');
        }

        $report['backup'] = create_backup($config, $wpRoot, $run);
        if (!$report['backup']['success']) {
            throw new RuntimeException('Backup did not succeed for this update run.');
        }
        $backupFolder = $report['backup']['backupFolder'];

        append_log($run['logPath'], 'info', 'Activating maintenance mode');
        run_cmd($wp . ' maintenance-mode activate', true);
        try {
            if ($intent['plugins']) {
                append_log($run['logPath'], 'info', 'Updating plugins');
                $report['pluginUpdate'] = run_cmd($wp . ' plugin update --all', true);
                if (!$report['pluginUpdate']['ok']) {
                    throw new RuntimeException('Plugin update failed: ' . $report['pluginUpdate']['output']);
                }
            } else {
                $report['pluginUpdate'] = ['skipped' => true];
            }

            if ($intent['allThemes']) {
                append_log($run['logPath'], 'info', 'Updating all themes because theme updates were explicitly requested');
                $report['themeUpdate'] = run_cmd($wp . ' theme update --all', true);
                if (!$report['themeUpdate']['ok']) {
                    throw new RuntimeException('Theme update failed: ' . $report['themeUpdate']['output']);
                }
            } elseif ($intent['activeTheme']) {
                $activeTheme = trim(run_cmd($wp . ' theme list --status=active --field=name')['output']);
                append_log($run['logPath'], 'info', "Updating active theme: {$activeTheme}");
                $report['themeUpdate'] = run_cmd($wp . ' theme update ' . escapeshellarg($activeTheme), true);
                if (!$report['themeUpdate']['ok']) {
                    throw new RuntimeException('Active theme update failed: ' . $report['themeUpdate']['output']);
                }
            } else {
                $report['themeUpdate'] = ['skipped' => true, 'reason' => 'Theme updates are disabled by default'];
            }

            if ((bool) $config['clearCache']) {
                $report['cache'] = clear_common_caches($config, $run['logPath']);
            }
        } finally {
            append_log($run['logPath'], 'info', 'Deactivating maintenance mode');
            run_cmd($wp . ' maintenance-mode deactivate', true);
        }

        write_command_output($backupFolder . DIRECTORY_SEPARATOR . 'wordpress-version-after.txt', $wp . ' core version');
        write_command_output($backupFolder . DIRECTORY_SEPARATOR . 'plugins-after.json', $wp . ' plugin list --format=json');
        write_command_output($backupFolder . DIRECTORY_SEPARATOR . 'themes-after.json', $wp . ' theme list --format=json');

        $report['verification'] = verify_site($config);
        if (!$report['verification']['ok']) {
            $report['needsManualReview'] = true;
            throw new RuntimeException('Verification failed. Keep the backup and manually review the site or run an individual rollback.');
        }

        $report['success'] = true;
        append_log($run['logPath'], 'info', 'Update completed successfully');
    } catch (Throwable $error) {
        $report['error'] = $error->getMessage();
        if (isset($report['backup']['backupFolder'])) {
            $report['needsManualReview'] = true;
            $report['recommendation'] = 'Manual review recommended. Roll back this individual site only if needed.';
        }
        append_log($run['logPath'], 'error', $error->getMessage());
    } finally {
        $report['endedAt'] = date('c');
        if (isset($backupFolder) && is_dir($backupFolder)) {
            write_report($backupFolder . DIRECTORY_SEPARATOR . 'update-report.json', $report);
        }
        write_report($run['reportPath'], $report);
    }

    if (!$report['success']) {
        throw new RuntimeException($report['error'] ?? 'Update failed');
    }
    return $report;
}

function confirm_or_fail(array $flags, string $question): void
{
    if (isset($flags['yes'])) {
        return;
    }
    fwrite(STDOUT, $question . ' Type YES to continue: ');
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'YES') {
        throw new RuntimeException('Rollback cancelled.');
    }
}

function rollback_site(array $config, string $wpRoot, array $flags): array
{
    $run = create_run_files($config, 'rollback');
    $backupFolder = isset($flags['backup']) ? resolve_config_path((string) $flags['backup'], $wpRoot) : '';
    $report = [
        'command' => 'rollback',
        'startedAt' => date('c'),
        'endedAt' => null,
        'success' => false,
        'backupFolder' => $backupFolder,
        'logPath' => $run['logPath'],
    ];

    try {
        $wp = bin_cmd($config, 'wpCliBinary');
        $tar = bin_cmd($config, 'tarBinary');
        assert_wordpress_root($wpRoot);
        if ($backupFolder === '') {
            throw new RuntimeException('Rollback requires --backup <backup-folder>.');
        }
        $filesArchive = $backupFolder . DIRECTORY_SEPARATOR . 'files.tar.gz';
        $databaseDump = $backupFolder . DIRECTORY_SEPARATOR . 'database.sql';
        if (!is_file($filesArchive)) {
            throw new RuntimeException("Missing {$filesArchive}");
        }
        if (!is_file($databaseDump)) {
            throw new RuntimeException("Missing {$databaseDump}");
        }

        confirm_or_fail($flags, "Rollback {$wpRoot} using {$backupFolder}?");
        append_log($run['logPath'], 'info', 'Restoring files');
        run_cmd($tar . ' -xzf ' . escapeshellarg($filesArchive) . ' -C ' . escapeshellarg($wpRoot));
        append_log($run['logPath'], 'info', 'Restoring database');
        run_cmd($wp . ' db import ' . escapeshellarg($databaseDump));
        $report['success'] = true;
        append_log($run['logPath'], 'info', 'Rollback completed');
    } catch (Throwable $error) {
        $report['error'] = $error->getMessage();
        append_log($run['logPath'], 'error', $error->getMessage());
    } finally {
        $report['endedAt'] = date('c');
        if ($backupFolder !== '' && is_dir($backupFolder)) {
            write_report($backupFolder . DIRECTORY_SEPARATOR . 'rollback-report.json', $report);
        }
        write_report($run['reportPath'], $report);
    }

    if (!$report['success']) {
        throw new RuntimeException($report['error'] ?? 'Rollback failed');
    }
    return $report;
}

function latest_result_path(array $config): string
{
    return rtrim($config['reportsDir'], '/\\') . DIRECTORY_SEPARATOR . 'latest-result.json';
}

function write_latest_result(array $config, array $result): void
{
    ensure_dir($config['reportsDir']);
    write_report(latest_result_path($config), $result);
}

function acquire_cron_lock(array $config): array
{
    $lockFile = (string) $config['lockFile'];
    $timeoutSeconds = max(60, (int) $config['lockTimeoutMinutes'] * 60);
    ensure_dir(dirname($lockFile));
    if (is_file($lockFile)) {
        $age = time() - (int) filemtime($lockFile);
        if ($age > $timeoutSeconds) {
            @unlink($lockFile);
        }
    }
    $handle = @fopen($lockFile, 'x');
    if ($handle === false) {
        throw new RuntimeException("Another cron run appears to be active. Lock file exists: {$lockFile}");
    }
    $payload = [
        'pid' => getmypid(),
        'startedAt' => date('c'),
        'timeoutMinutes' => (int) $config['lockTimeoutMinutes'],
    ];
    fwrite($handle, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    fclose($handle);
    return ['lockFile' => $lockFile, 'acquiredAt' => $payload['startedAt']];
}

function release_cron_lock(array $lock): void
{
    if (isset($lock['lockFile']) && is_file($lock['lockFile'])) {
        @unlink($lock['lockFile']);
    }
}

function notify_result(array $config, array $result): array
{
    $email = trim((string) ($config['notificationEmail'] ?? ''));
    if ($email === '') {
        return ['sent' => false, 'reason' => 'notificationEmail is empty'];
    }
    if (!function_exists('mail')) {
        return ['sent' => false, 'reason' => 'PHP mail() is unavailable'];
    }
    $subject = '[wp-maintenance] ' . ($result['success'] ? 'SUCCESS' : 'FAILURE') . ' ' . ($result['cronCommand'] ?? $result['command'] ?? 'cron');
    $body = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $headers = '';
    $from = trim((string) ($config['notificationFrom'] ?? ''));
    if ($from !== '') {
        $headers = 'From: ' . $from;
    }
    $sent = @mail($email, $subject, (string) $body, $headers);
    return ['sent' => $sent, 'to' => $email, 'reason' => $sent ? null : 'mail() returned false'];
}

function run_cron_mode(array $config, string $wpRoot, array $flags): array
{
    $run = create_run_files($config, 'cron');
    $cronCommand = (string) ($flags['command'] ?? $config['cronDefaultCommand'] ?? 'status');
    $allowed = ['status', 'backup', 'update'];
    $result = [
        'command' => 'cron',
        'cronCommand' => $cronCommand,
        'startedAt' => date('c'),
        'endedAt' => null,
        'success' => false,
        'version' => AGENT_VERSION,
        'wordpressRoot' => $wpRoot,
        'reportPath' => $run['reportPath'],
        'logPath' => $run['logPath'],
        'latestResultPath' => latest_result_path($config),
        'lock' => null,
        'shellExecution' => shell_execution_status(),
        'childResult' => null,
    ];
    $lock = null;
    try {
        if (!in_array($cronCommand, $allowed, true)) {
            throw new RuntimeException('cron --command must be one of: ' . implode(', ', $allowed));
        }
        assert_shell_execution_available();
        $lock = acquire_cron_lock($config);
        $result['lock'] = $lock;
        append_log($run['logPath'], 'info', "Starting cron command: {$cronCommand}");
        if ($cronCommand === 'status') {
            $checks = run_preflight_checks($wpRoot, $config);
            $result['childResult'] = [
                'command' => 'status',
                'success' => count(array_filter($checks, function (array $check): bool {
                    return !$check['ok'];
                })) === 0,
                'checks' => $checks,
            ];
        } elseif ($cronCommand === 'backup') {
            $result['childResult'] = create_backup($config, $wpRoot, $run);
        } elseif ($cronCommand === 'update') {
            if (!isset($flags['all-themes'])) {
                $flags['skip-theme'] = true;
            }
            $result['childResult'] = update_site($config, $wpRoot, $flags);
        }
        $result['success'] = (bool) ($result['childResult']['success'] ?? false);
        if (!$result['success']) {
            throw new RuntimeException('Cron child command failed.');
        }
        append_log($run['logPath'], 'info', 'Cron command completed successfully');
    } catch (Throwable $error) {
        $result['error'] = $error->getMessage();
        append_log($run['logPath'], 'error', $error->getMessage());
    } finally {
        if ($lock !== null) {
            release_cron_lock($lock);
        }
        $result['endedAt'] = date('c');
        $result['notification'] = notify_result($config, $result);
        write_report($run['reportPath'], $result);
        write_latest_result($config, $result);
    }
    if (!$result['success']) {
        throw new RuntimeException($result['error'] ?? 'Cron command failed');
    }
    return $result;
}

function print_help(): void
{
    echo "wp-agent " . AGENT_VERSION . PHP_EOL . PHP_EOL;
    echo "Usage:" . PHP_EOL;
    echo "  php wp-agent.php status" . PHP_EOL;
    echo "  php wp-agent.php backup" . PHP_EOL;
    echo "  php wp-agent.php update" . PHP_EOL;
    echo "  php wp-agent.php update --skip-theme" . PHP_EOL;
    echo "  php wp-agent.php update --skip-plugins" . PHP_EOL;
    echo "  php wp-agent.php update --all-themes" . PHP_EOL;
    echo "  php wp-agent.php cron --command status|backup|update [--wp-root <path>]" . PHP_EOL;
    echo "  php wp-agent.php rollback --backup <backup-folder> [--yes]" . PHP_EOL;
}

[$command, $flags] = parse_args($argv);
$wpRoot = isset($flags['wp-root']) ? resolve_config_path((string) $flags['wp-root'], getcwd()) : getcwd();
$config = load_config($wpRoot, $agentDir);

try {
    if ($command === 'help' || isset($flags['help'])) {
        print_help();
        exit(0);
    }

    if ($command === 'version') {
        echo AGENT_VERSION . PHP_EOL;
        exit(0);
    }

    if ($command === 'status') {
        $run = create_run_files($config, 'status');
        $report = [
            'command' => 'status',
            'startedAt' => date('c'),
            'endedAt' => null,
            'success' => false,
            'version' => AGENT_VERSION,
            'wordpressRoot' => $wpRoot,
            'checks' => [],
        ];
        try {
            $report['checks'] = run_preflight_checks($wpRoot, $config);
            $report['success'] = count(array_filter($report['checks'], function (array $check): bool {
                return !$check['ok'];
            })) === 0;
        } catch (Throwable $error) {
            $report['error'] = $error->getMessage();
        }
        $report['endedAt'] = date('c');
        write_report($run['reportPath'], $report);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($report['success'] ? 0 : 1);
    }

    if ($command === 'backup') {
        echo json_encode(create_backup($config, $wpRoot), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    if ($command === 'cron') {
        echo json_encode(run_cron_mode($config, $wpRoot, $flags), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    if ($command === 'update') {
        echo json_encode(update_site($config, $wpRoot, $flags), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    if ($command === 'rollback') {
        echo json_encode(rollback_site($config, $wpRoot, $flags), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    print_help();
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

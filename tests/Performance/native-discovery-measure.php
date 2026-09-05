<?php

declare(strict_types = 1);
/*
 * Reproducible local fixture: controlled GitHub-shaped responses only. Run:
 * mkdir -p .workspaces/php-tmp
 * TMPDIR="$PWD/.workspaces/php-tmp" php -d sys_temp_dir="$PWD/.workspaces/php-tmp" \
 *     tests/Performance/native-discovery-measure.php
 * PHP CLI needs ext-zip and uses the caller's INI configuration.
 */
namespace {
    final class WP_Error {
        public function __construct(public string $code, public string $message) {
        }
    }
    function is_wp_error(mixed $value):bool {
        return $value instanceof WP_Error;
    }
    function get_filesystem_method():string {
        return 'direct';
    }
    function get_current_network_id():int {
        return 1;
    }
    function wp_safe_remote_get(string $url, array $args):array {
        $response = native_measure_response($url);
        $body = $response ['body'];
        $GLOBALS ['native_measure'] ['http_calls'] ++;
        $GLOBALS ['native_measure'] ['body_bytes'] += strlen($body);
        if(isset($args ['filename'])) {
            file_put_contents($args ['filename'], $body);
            chmod($args ['filename'], 0600);
            $GLOBALS ['native_measure'] ['streamed_bytes'] += strlen($body);
        }
        return array('body' => isset($args ['filename'])?'':$body, 'headers' => array(), 'response' => array('code' => 200));
    }
    function wp_remote_retrieve_response_code(array $r):int {
        return $r ['response'] ['code'];
    }
    function wp_remote_retrieve_header(array $r, string $name):mixed {
        return $r ['headers'] [strtolower($name)] ??null;
    }
    function wp_remote_retrieve_body(array $r):string {
        return $r ['body'];
    }
    function wp_http_validate_url(string $url):string {
        return $url;
    }
    function wp_tempnam(string $name):string | false {
        $path = tempnam($GLOBALS ['native_measure'] ['temp'], 'asset-');
        if(is_string($path)) {
            chmod($path, 0600);
            $GLOBALS ['native_measure'] ['temporary'] [] = $path;
        }
        return $path;
    }
    function native_measure_response(string $url):array {
        $u = & $GLOBALS ['native_measure'];
        $parts = parse_url($url);
        $repository = (string)($u ['repository'] ??'repository');
        $prefix = '/repos/owner/'.$repository;
        $path = $parts ['path'] ??null;
        if(! is_array($parts) || 'https' !==($parts ['scheme'] ??null) || 'api.github.com' !==($parts ['host'] ??null) || ! is_string($path) ||($path !== $prefix && ! str_starts_with($path, $prefix.'/'))) throw new \RuntimeException('Unexpected fixture request: '.$url);
        if($prefix.'/releases' === $path && isset($parts ['query'])) return array('body' => json_encode(native_measure_releases($u ['scenario'])));
        if(1 === preg_match('~^'.preg_quote($prefix, '~').'/releases/assets/8$~D', $path)) {
            $u ['acquisitions'] ++;
            return array('body' => $u ['zip']);
        }
        if(1 === preg_match('~^'.preg_quote($prefix, '~').'/releases/([0-9]+)$~D', $path, $m)) return array('body' => json_encode(native_measure_release((int) $m [1], $u ['changed'] && $u ['after_offer'], 'incompatible' === $u ['scenario'])));
        if(1 === preg_match('~^'.preg_quote($prefix, '~').'/commits/[A-Za-z0-9._/-]+$~D', $path)) return array('body' => json_encode(array('sha' => $u ['changed'] && $u ['after_offer']?str_repeat('b', 40):str_repeat('a', 40))));
        if($prefix !== $path) throw new \RuntimeException('Unexpected fixture endpoint: '.$url);
        $number = preg_match('/-(\d+)$/', $repository, $match)?(int) $match [1]:0;
        return array('body' => json_encode(array('id' => 99 + $number)));
    }
    function native_measure_releases(string $scenario):array {
        $n = 'incompatible' === $scenario?8:1;
        $all = array();
        for($i = 0; $i < $n; ++ $i) $all [] = native_measure_release($i + 1, false, 'incompatible' === $scenario);
        return $all;
    }
    function native_measure_release(int $id, bool $changed = false, bool $incompatible = false):array {
        $v = $incompatible?'2.0.'.$id:'2.0.0';
        $repository = $GLOBALS ['native_measure'] ['repository'] ??'repository';
        return array('id' => $id, 'draft' => false, 'prerelease' => false, 'immutable' => true, 'published_at' => '2026-08-22T10:00:00Z', 'tag_name' => 'v'.$v, 'html_url' => 'https://github.com/owner/'.$repository.'/releases/tag/v'.$v, 'target_commitish' => $changed?str_repeat('b', 40):str_repeat('a', 40), 'assets' => array(array('id' => 8, 'name' => $repository.'.zip', 'size' => strlen($GLOBALS ['native_measure'] ['zip']), 'state' => 'uploaded', 'digest' => 'sha256:'.hash('sha256', $GLOBALS ['native_measure'] ['zip']))));
    }
}
namespace Tests\Performance {
    require_once dirname(__DIR__).'/Support/WordPressHookFixture.php';
    require_once dirname(__DIR__).'/Support/FakeOptionDatabase.php';
    use Tests\Support\FakeOptionDatabase;
    const NATIVE_MEASURE_COUNTS = array(1, 5, 10, 20);
    function native_measure_assert(bool $condition, string $message):void {
        if(! $condition) throw new \RuntimeException($message);
    }
    function native_measure_zip(string $type, string $root = 'repository', string $uri = 'https://github.com/owner/repository', string $name = 'Fixture'):string {
        $path = tempnam($GLOBALS ['native_measure'] ['temp'], 'zip-');
        $zip = new \ZipArchive();
        native_measure_assert(true === $zip-> open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE), 'ZIP creation failed.');
        $header = 'plugin' === $type
            ? "<?php\n/*\nPlugin Name: " . $name . "\nVersion: 2.0.0\nUpdate URI: " . $uri . "\nRequires PHP: 8.2\nRequires at least: 6.8\n*/"
            : "/*\nTheme Name: " . $name . "\nVersion: 2.0.0\nUpdate URI: " . $uri . "\nRequires PHP: 8.2\nRequires at least: 6.8\n*/";
        $zip-> addFromString($root.'/'.('plugin' === $type?$root.'.php':'style.css'), $header);
        $zip-> addFromString($root.'/readme.txt', 'fixture');
        $zip-> close();
        $bytes = file_get_contents($path);
        unlink($path);
        return is_string($bytes)?$bytes:throw new \RuntimeException('ZIP read failed.');
    }
    /** One PHP request: N copied bootstraps, M queued registrar targets, then one activation. */
    function native_measure_delta(array $before):array {
        $now = $GLOBALS ['native_measure'];
        return array('http_calls' => $now ['http_calls'] - $before ['http_calls'], 'body_bytes' => $now ['body_bytes'] - $before ['body_bytes'], 'streamed_bytes' => $now ['streamed_bytes'] - $before ['streamed_bytes'], 'archive_acquisitions' => $now ['acquisitions'] - $before ['acquisitions'], 'validation_archive_opens' => $now ['validation_opens'] - $before ['validation_opens']);
    }
    function native_measure_counters():array {
        return array('http_calls' => $GLOBALS ['native_measure'] ['http_calls'], 'body_bytes' => $GLOBALS ['native_measure'] ['body_bytes'], 'streamed_bytes' => $GLOBALS ['native_measure'] ['streamed_bytes'], 'acquisitions' => $GLOBALS ['native_measure'] ['acquisitions'], 'validation_opens' => $GLOBALS ['native_measure'] ['validation_opens']);
    }
    /** One PHP request: N copied bootstraps, M queued registrar targets, then one activation. */
    function native_measure_shared_request(array $roots, int $targets, string $scenario, bool $callbackControl = false, string $targetType = 'plugin', bool $callbackRevoked = false):array {
        native_measure_assert($roots !== array() && count($roots) === count(array_unique($roots)), 'Physical runtime roots are not distinct.');
        $temp = getenv('RAN_NATIVE_MEASURE_TEMP')?:sys_get_temp_dir();
        $GLOBALS ['native_measure'] = array('temp' => $temp, 'scenario' => $scenario, 'changed' => false, 'after_offer' => false, 'http_calls' => 0, 'body_bytes' => 0, 'streamed_bytes' => 0, 'acquisitions' => 0, 'validation_opens' => 0, 'temporary' => array());
        $installedRoot = $temp.'/shared-installed-'.bin2hex(random_bytes(4));
        mkdir($installedRoot, 0700, true);
        if(! defined('WP_PLUGIN_DIR')) define('WP_PLUGIN_DIR', $installedRoot);
        $GLOBALS ['wp_version'] = '6.8.0';
        $GLOBALS ['wpdb'] = new FakeOptionDatabase(100);
        $GLOBALS ['wp_theme_directories'] = array($installedRoot);
        $GLOBALS ['wp_filter'] = array();
        $GLOBALS ['wp_actions'] = array();
        $GLOBALS ['wp_current_filter'] = array();
        $api = null;
        $targetFixtures = array();
        for($index = 0; $index < $targets; ++ $index) {
            $slug = 'repository-'.$index;
            $uri = 'https://github.com/owner/'.$slug;
            $name = 'Fixture '.$index;
            mkdir($installedRoot.'/'.$slug, 0700, true);
            $file = $installedRoot.'/'.$slug.'/'.('plugin' === $targetType?$slug.'.php':'style.css');
            $header = 'plugin' === $targetType
                ? "<?php\n/*\nPlugin Name: " . $name . "\nVersion: 1.0.0\nPlugin URI: " . $uri . "\nUpdate URI: " . $uri . "\nRequires PHP: 8.2\nRequires at least: 6.8\n*/"
                : "/*\nTheme Name: " . $name . "\nVersion: 1.0.0\nTheme URI: " . $uri . "\nUpdate URI: " . $uri . "\nRequires PHP: 8.2\nRequires at least: 6.8\n*/";
            file_put_contents($file, $header);
            $targetFixtures [] = array('repository' => $slug, 'uri' => $uri, 'zip' => native_measure_zip($targetType, $slug, $uri, $name));
        }
        memory_reset_peak_usage();
        $before = array('time_ns' => hrtime(true), 'memory' => memory_get_usage(true));
        foreach($roots as $root) {
            $loaded = require $root.'/bootstrap.php';
            if(! is_object($api)) $api = $loaded;
        }
        $afterBootstrap = array('time_ns' => hrtime(true), 'memory' => memory_get_usage(true));
        $credentialCalls = 0;
        foreach($targetFixtures as $index => $fixture) {
            $file = $installedRoot.'/'.$fixture ['repository'].'/'.('plugin' === $targetType?$fixture ['repository'].'.php':'style.css');
            $resolver = $callbackControl?static function() use(& $credentialCalls, $callbackRevoked):?string {
                ++ $credentialCalls;
                if($callbackRevoked && $credentialCalls > 3) throw new \RuntimeException('Credential revoked.');
                return null;
            }
            :null;
            $handle = 'plugin' === $targetType?$api-> plugin('github', $file, 'owner/'.$fixture ['repository'], (string)(99 + $index), 'stable', 'manual', $resolver):$api-> theme('github', $file, 'owner/'.$fixture ['repository'], (string)(99 + $index), 'stable', 'manual', $resolver);
            native_measure_assert($handle-> register(), 'Queued registrar target was rejected.');
        }
        $afterDeclaration = array('time_ns' => hrtime(true), 'memory' => memory_get_usage(true));
        $broker = $GLOBALS ['ran_wp_release_updater_v1_broker'];
        $activation = $broker-> activate(array('php_version' => '8.2.0', 'runtime_protocol' => 2, 'wordpress_version' => '6.8.0'));
        native_measure_assert('active' === $activation ['state'], 'Shared-request activation failed.');
        $afterActivation = array('time_ns' => hrtime(true), 'memory' => memory_get_usage(true));
        $submissions = new \ReflectionProperty($broker, 'submissions');
        $all = $submissions-> getValue($broker);
        $opens = 0;
        $natives = array();
        foreach(array_values($all) as $index => $submission) {
            $native =(new \ReflectionProperty($submission ['handle'], 'native'))-> getValue($submission ['handle']);
            $validator =(new \ReflectionProperty($native, 'validator'))-> getValue($native);
            (new \ReflectionProperty($validator, 'afterOpen'))-> setValue($validator, static function(string $path) use(& $opens):void {
                unset($path); ++ $opens; ++ $GLOBALS ['native_measure'] ['validation_opens'];
            }
            );
            $natives [] = array('native' => $native, 'identity' =>(new \ReflectionProperty($native, 'installedIdentity'))-> getValue($native), 'fixture' => $targetFixtures [$index]);
        }
        $steps = array();
        $offers = array();
        if('registration' !== $scenario) foreach($natives as $index => $item) {
            $GLOBALS ['native_measure'] ['repository'] = $item ['fixture'] ['repository'];
            $GLOBALS ['native_measure'] ['zip'] = $item ['fixture'] ['zip'];
            $beforeStep = native_measure_counters();
            $offer = $item ['native']-> filterUpdate(false, array('Version' => '1.0.0', 'UpdateURI' => $item ['fixture'] ['uri']), $item ['identity'], array());
            if('incompatible' === $scenario) native_measure_assert(false === $offer, 'Incompatible candidate was offered.');
            else native_measure_assert(is_array($offer), 'Discovery did not offer an update for '.$item ['identity'].'.');
            $offers [$index] = $offer;
            $steps ['discovery_'.$index] = native_measure_delta($beforeStep);
            if('incompatible' === $scenario) {
                native_measure_assert(8 === $steps ['discovery_'.$index] ['archive_acquisitions'], 'Incompatible search did not inspect eight candidates.');
                native_measure_assert(8 === $steps ['discovery_'.$index] ['validation_archive_opens'], 'Incompatible search did not open eight candidate archives.');
            }
        }
        if('repeated' === $scenario) foreach($natives as $index => $item) {
            $GLOBALS ['native_measure'] ['repository'] = $item ['fixture'] ['repository'];
            $GLOBALS ['native_measure'] ['zip'] = $item ['fixture'] ['zip'];
            $beforeStep = native_measure_counters();
            $again = $item ['native']-> filterUpdate(false, array('Version' => '1.0.0', 'UpdateURI' => $item ['fixture'] ['uri']), $item ['identity'], array());
            if($callbackRevoked) native_measure_assert(false === $again, 'Revoked callback discovery did not fail closed.');
            else native_measure_assert(is_array($again), 'Repeated discovery did not offer an update.');
            $steps ['repeated_discovery_'.$index] = native_measure_delta($beforeStep);
            if('plugin' === $targetType && ! $callbackRevoked) {
                $beforeStep = native_measure_counters();
                $information = $item ['native']-> filterPluginInformation(false, 'plugin_information', (object) array('slug' => 'ran-wp-release-updater-'.substr(hash('sha256', 'plugin'."\0".$item ['identity']), 0, 24)));
                $steps ['information_'.$index] = native_measure_delta($beforeStep);
                native_measure_assert(is_object($information) && '2.0.0' ===($information-> version ??null), 'Plugin information did not return the expected version.');
            }
        }
        if('refresh' === $scenario) foreach($natives as $index => $item) {
            native_measure_assert(true === $item ['native']-> refresh(), 'Native refresh failed.');
            $GLOBALS ['native_measure'] ['repository'] = $item ['fixture'] ['repository'];
            $GLOBALS ['native_measure'] ['zip'] = $item ['fixture'] ['zip'];
            $beforeStep = native_measure_counters();
            native_measure_assert(is_array($item ['native']-> filterUpdate(false, array('Version' => '1.0.0', 'UpdateURI' => $item ['fixture'] ['uri']), $item ['identity'], array())), 'Refresh discovery did not offer an update.');
            $steps ['refresh_discovery_'.$index] = native_measure_delta($beforeStep);
            native_measure_assert(1 === $steps ['refresh_discovery_'.$index] ['archive_acquisitions'], 'Refresh discovery did not acquire a fresh archive.');
        }
        if('install' === $scenario || 'changed' === $scenario) foreach($natives as $index => $item) {
            $GLOBALS ['native_measure'] ['repository'] = $item ['fixture'] ['repository'];
            $GLOBALS ['native_measure'] ['zip'] = $item ['fixture'] ['zip'];
            $GLOBALS ['native_measure'] ['after_offer'] = true;
            if('changed' === $scenario) $GLOBALS ['native_measure'] ['changed'] = true;
            $beforeStep = native_measure_counters();
            $extra = array('action' => 'update', 'type' => $targetType, 'plugin' === $targetType?'plugin':'theme' => $item ['identity']);
            $reply = $item ['native']-> filterPreDownload(false, $offers [$index] ['package'], null, $extra);
            if('install' === $scenario) {
                native_measure_assert(is_string($reply) && is_file($reply), 'Fresh installation preparation failed.');
            }
            else {
                native_measure_assert($reply instanceof \WP_Error || false === $reply, 'Changed remote evidence was admitted.');
                native_measure_assert('remote_release_changed' === $item ['native']-> status() ['failure_code'], 'Changed remote evidence had the wrong rejection.');
            }
            $stepName =('install' === $scenario?'install_':'changed_').$index;
            $steps [$stepName] = native_measure_delta($beforeStep);
            if('install' === $scenario) {
                native_measure_assert(1 === $steps [$stepName] ['archive_acquisitions'], 'Installation preparation did not acquire exactly one fresh archive.');
                native_measure_assert(2 === $steps [$stepName] ['validation_archive_opens'], 'Installation preparation did not open source and owned archive.');
            }
        }
        $afterOperations = array('time_ns' => hrtime(true), 'memory' => memory_get_usage(true), 'memory_peak' => memory_get_peak_usage(true));
        $ownedPaths = array();
        foreach($natives as $item) {
            $path =(new \ReflectionProperty($item ['native'], 'pendingArchive'))-> getValue($item ['native']);
            if(is_string($path) && is_file($path)) $ownedPaths [] = $path;
        }
        $temporaryPaths = array_filter($GLOBALS ['native_measure'] ['temporary'], 'is_file');
        $beforeCleanup = count(array_unique(array_merge($ownedPaths, $temporaryPaths)));
        foreach($natives as $item) native_measure_assert(true === $item ['native']-> refresh(), 'Native cleanup refresh failed.');
        $afterCleanup = count(array_filter(array_unique(array_merge($ownedPaths, $temporaryPaths)), 'is_file'));
        $brokerState = $broker-> diagnostics();
        native_measure_assert(count($roots) === $brokerState ['candidate_count'], 'Broker candidate count differs from physical copies: '.json_encode($brokerState));
        native_measure_assert(count($all) === $targets && $targets === $brokerState ['logical_target_count'], 'Shared-request active native count differs from declarations.');
        native_measure_assert($opens >= $GLOBALS ['native_measure'] ['acquisitions'], 'Validator opened fewer archives than acquisitions.');
        native_measure_assert(0 === $afterCleanup, 'Native refresh left owned or temporary archives behind.');
        if('registration' === $scenario) native_measure_assert(0 === $GLOBALS ['native_measure'] ['http_calls'] && 0 === $credentialCalls, 'Registration performed work outside declaration.');
        if('install' === $scenario) native_measure_assert($targets === $beforeCleanup, 'Installation did not retain exactly one owned file per target.');
        if('changed' === $scenario) foreach($steps as $name => $delta) if(str_starts_with($name, 'changed_')) native_measure_assert(0 === $delta ['archive_acquisitions'], 'Changed evidence acquired an archive.');
        foreach($GLOBALS ['native_measure'] ['temporary'] as $path) if(is_file($path)) unlink($path);
        return array('physical_copies' => count($roots), 'distinct_targets' => $targets, 'target_type' => $targetType, 'scenario' => $scenario, 'credential_mode' => $callbackControl?'callback_returning_null':'literal_null', 'bootstrap_ns' => $afterBootstrap ['time_ns'] - $before ['time_ns'], 'declaration_ns' => $afterDeclaration ['time_ns'] - $afterBootstrap ['time_ns'], 'activation_ns' => $afterActivation ['time_ns'] - $afterDeclaration ['time_ns'], 'operations_ns' => $afterOperations ['time_ns'] - $afterActivation ['time_ns'], 'memory_initial' => $before ['memory'], 'memory_after_operations' => $afterOperations ['memory'], 'memory_peak' => $afterOperations ['memory_peak'], 'http_calls' => $GLOBALS ['native_measure'] ['http_calls'], 'body_bytes' => $GLOBALS ['native_measure'] ['body_bytes'], 'streamed_bytes' => $GLOBALS ['native_measure'] ['streamed_bytes'], 'archive_acquisitions' => $GLOBALS ['native_measure'] ['acquisitions'], 'validation_archive_opens' => $opens, 'files_before_cleanup' => $beforeCleanup, 'files_after_cleanup' => $afterCleanup, 'credential_callback_calls' => $credentialCalls, 'operation_steps' => $steps);
    }
    function native_measure_shared_worker(array $args):void {
        $roots = explode('|', (string) getenv('RAN_NATIVE_MEASURE_ROOTS'));
        echo json_encode(native_measure_shared_request($roots, (int)($args [2] ??1), $args [3] ??'cold', '--callback-control' ===($args [4] ??null), $args [5] ??'plugin', '--callback-revoked' ===($args [6] ??null)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    function native_measure_copy(string $from, string $to):void {
        mkdir($to, 0700, true);
        foreach(array('bootstrap.php', 'runtime.php', 'runtime-copy.json') as $file) copy($from.'/'.$file, $to.'/'.$file);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from.'/src', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach($iterator as $file) {
            $relative = substr($file-> getPathname(), strlen($from.'/src') + 1);
            $destination = $to.'/src/'.$relative;
            if($file-> isDir()) mkdir($destination, 0700, true);
            else copy($file-> getPathname(), $destination);
        }
    }
    function native_measure_child(array $roots, string $scratch, int $targets, string $scenario, bool $callbackControl = false, string $targetType = 'plugin', bool $callbackRevoked = false):array {
        $command = array(PHP_BINARY, '-d', 'sys_temp_dir='.$scratch, __FILE__, '--shared-worker', (string) $targets, $scenario, $callbackControl?'--callback-control':'--literal-null', $targetType, $callbackRevoked?'--callback-revoked':'--callback-stable');
        $environment = array('RAN_NATIVE_MEASURE_ROOTS' => implode('|', $roots), 'RAN_NATIVE_MEASURE_TEMP' => $scratch);
        foreach(array('PATH', 'TMPDIR', 'PHPRC', 'PHP_INI_SCAN_DIR') as $name) {
            $value = getenv($name);
            if(false !== $value) $environment[$name] = $value;
        }
        $pipes = array();
        $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $environment);
        native_measure_assert(is_resource($process), 'Could not start measurement subprocess.');
        fclose($pipes [0]);
        $stdout = stream_get_contents($pipes [1]);
        $stderr = stream_get_contents($pipes [2]);
        fclose($pipes [1]);
        fclose($pipes [2]);
        $exit = proc_close($process);
        if(0 !== $exit) throw new \RuntimeException('Measurement subprocess failed ('.$exit."): ".trim($stderr)."\nstdout: ".trim($stdout));
        try {
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        }
        catch(\Throwable $error) {
            throw new \RuntimeException('Measurement subprocess returned invalid JSON: '.trim($stderr)."\nstdout: ".trim($stdout), 0, $error);
        }
        native_measure_assert(is_array($decoded), 'Measurement subprocess did not return a row.');
        return $decoded;
    }
    function native_measure_main():void {
        $root = dirname(__DIR__, 2);
        $scratch = $root.'/.workspaces/evidence/u2-native-measure-'.bin2hex(random_bytes(4));
        mkdir($scratch, 0700, true);
        $scenarios = array('registration', 'cold', 'repeated', 'incompatible', 'refresh', 'install', 'changed');
        $rows = array();
        foreach(NATIVE_MEASURE_COUNTS as $count) foreach(array(array(1, $count), array($count, 1), array($count, $count)) as $topology) foreach($scenarios as $scenario) {
            [$copies, $targets] = $topology;
            $roots = array();
            for($index = 0; $index < $copies; ++ $index) {
                $copy = $scratch.'/copy-'.count($rows).'-'.$index;
                native_measure_copy($root, $copy);
                $roots [] = $copy;
            }
            $row = native_measure_child($roots, $scratch, $targets, $scenario);
            $row ['topology'] = array('physical_copies' => $copies, 'distinct_targets' => $targets);
            $rows [] = $row;
        }
        native_measure_assert(84 === count($rows), 'Shared-request matrix did not produce 84 rows.');
        $files = 2;
        $bytes = filesize($root.'/bootstrap.php') + filesize($root.'/runtime.php');
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) if($file-> isFile() && 'php' === $file-> getExtension()) {
            ++ $files;
            $bytes += $file-> getSize();
        }
        $out = array('fixture' => 'native-discovery-measure', 'environment' => array('php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'filesystem_cache' => 'warm local filesystem; one fresh PHP globals process per matrix row', 'transport' => 'controlled in-process wp_safe_remote_get; no network, credentials, or live site'), 'source_inventory' => array('runtime_php_files' => $files, 'runtime_php_bytes' => $bytes, 'inventory_only' => true), 'measurements' => $rows, 'limitations' => array('Timing is local contextual evidence, not host or network latency.', 'Fake transport and fake option database count deterministic runtime work only.'));
        $controlCopy = $scratch.'/callback-control';
        native_measure_copy($root, $controlCopy);
        $out ['controls'] = array('callback_returning_null_repeated_plugin' => native_measure_child(array($controlCopy), $scratch, 1, 'repeated', true));
		$out ['controls'] ['callback_revoked_repeated_plugin'] = native_measure_child(array($controlCopy), $scratch, 1, 'repeated', true, 'plugin', true);
        $themeControls = array();
        foreach(array('cold', 'repeated', 'refresh', 'install', 'changed') as $scenario) {
            $themeCopy = $scratch.'/theme-control-'.$scenario;
            native_measure_copy($root, $themeCopy);
            $themeControls [$scenario] = native_measure_child(array($themeCopy), $scratch, 1, $scenario, false, 'theme');
        }
        $out ['controls'] ['theme_1copy_1target'] = $themeControls;
        native_measure_assert($out ['controls'] ['callback_returning_null_repeated_plugin'] ['credential_callback_calls'] > 0, 'Callback-returning-null control did not invoke its resolver.');
		native_measure_assert(4 === $out ['controls'] ['callback_revoked_repeated_plugin'] ['credential_callback_calls'], 'Revoked callback control did not re-resolve before the second discovery.');
        file_put_contents($root.'/.workspaces/evidence/native-discovery-measure.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }
    if('--shared-worker' ===($argv [1] ??null)) native_measure_shared_worker($argv);
    else native_measure_main();
}

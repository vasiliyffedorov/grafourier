<?php
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/CacheManagerFactory.php';
require_once __DIR__ . '/CorridorBuilder.php';
require_once __DIR__ . '/GrafanaProxyClient.php';

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

/** Нормализация и разделение override-параметров */
function normalizeQuery(string $q): string {
    return trim(preg_replace('/\s+/', ' ', str_replace(["\r","\n"], '', $q)));
}

function parseValue(string $v) {
    // булевы
    if (strcasecmp($v, 'true') === 0) return true;
    if (strcasecmp($v, 'false') === 0) return false;
    // массив по запятой
    if (strpos($v, ',') !== false) {
        return array_map('trim', explode(',', $v));
    }
    // число
    if (is_numeric($v)) {
        return strpos($v, '.') !== false
            ? (float)$v
            : (int)$v;
    }
    // строка
    return $v;
}

function setNested(array &$cfg, array $keys, $value): void {
    $ref = &$cfg;
    foreach ($keys as $i => $key) {
        if ($i === count($keys) - 1) {
            $ref[$key] = $value;
        } else {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
    }
}

function jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['status'=>'error','error'=>$msg]);
    exit;
}

// 1) читаем конфиг
$flat = parse_ini_file(__DIR__.'/config.cfg', true, INI_SCANNER_RAW);
if ($flat === false) {
    throw new Exception("Не удалось прочитать config.cfg");
}
function nest(array $f): array {
    $out = [];
    foreach ($f as $k => $v) {
        $parts = explode('.', $k);
        $r = &$out;
        foreach ($parts as $i => $p) {
            if ($i === count($parts)-1) {
                // списки через запятую
                if (strpos($v, ',') !== false) {
                    $r[$p] = array_map('trim', explode(',', $v));
                } elseif (is_numeric($v)) {
                    $r[$p] = strpos($v, '.')!==false ? (float)$v : (int)$v;
                } elseif (strtolower($v)==='true') {
                    $r[$p] = true;
                } elseif (strtolower($v)==='false') {
                    $r[$p] = false;
                } else {
                    $r[$p] = $v;
                }
            } else {
                if (!isset($r[$p]) || !is_array($r[$p])) {
                    $r[$p] = [];
                }
                $r = &$r[$p];
            }
        }
    }
    return $out;
}

$config = nest($flat);
$logger = new Logger(
    $config['log_file'],
    Logger::{"LEVEL_" . strtoupper($config['log_level'] ?? 'INFO')}
);

// 2) Grafana client
$proxy = new GrafanaProxyClient(
    $config['grafana_url'],
    $config['grafana_api_token'],
    $logger,
    $config['blacklist_datasource_ids'] ?? [] // Pass blacklist_datasource_ids to GrafanaProxyClient
);

// 3) роутинг
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// GET /api/v1/labels
if ($method==='GET' && $path==='/api/v1/labels') {
    echo json_encode(['status'=>'success','data'=>$proxy->getMetricNames()]);
    exit;
}

// GET /api/v1/label/__name__/values
if ($method==='GET' && $path==='/api/v1/label/__name__/values') {
    echo json_encode(['status'=>'success','data'=>$proxy->getMetricNames()]);
    exit;
}

// POST /api/v1/query_range
if ($method==='POST' && $path==='/api/v1/query_range') {
    parse_str(file_get_contents('php://input'), $p);
    if (empty($p['query'])) {
        jsonError('Missing query', 400);
    }

    // разбираем override-параметры
    $raw = normalizeQuery($p['query']);
    $overrides = [];
    if (strpos($raw, '#') !== false) {
        list($clean, $over) = explode('#', $raw, 2);
        $p['query'] = trim($clean);
        foreach (explode(';', $over) as $part) {
            if (!strstr($part, '=')) continue;
            list($k, $v) = array_map('trim', explode('=', $part, 2));
            $overrides[$k] = $v;
        }
    }

    // накладываем их на копию конфига
    $final = $config;
    foreach ($overrides as $key => $val) {
        $keys = explode('.', $key);
        setNested($final, $keys, parseValue($val));
    }

    // параметры запроса
    $start = (int)($p['start'] ?? time()-3600);
    $end   = (int)($p['end']   ?? time());
    $step  = (int)($p['step']  ?? 60);

    // и строим коридор
    $builder = new CorridorBuilder(
        $config['grafana_url'],
        $logger,
        $final
    );
    $result = $builder->build(
        $p['query'],
        $start,
        $end,
        $step
    );

    echo json_encode($result);
    exit;
}

// всё остальное 404
jsonError('Not found', 404);
?>
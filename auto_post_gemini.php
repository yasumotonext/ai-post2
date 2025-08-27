<?php
/**
 * auto_post_gemini_v4_2.php
 *
 * 変更点（v4→v4.2 + ログ強化）
 * - Google CSE を使った日英検索の結果（クエリ・URL・タイトル）を詳細ログ出力
 * - 取得JSONを ./logs/ に保存（監査・再現性向上）
 * - WP.org API: JSON/serialize 自動判別 + リトライ
 * - Gutenbergブロックのみで生成＆検証
 * - ユーザー評価（ratings 分布・サポート解決率）の要約ユーティリティ（必要に応じて使用）
 *
 * 必要な .env
 *   WP_BASE_URL, WP_API_USER, WP_API_PASS
 *   GEMINI_API_KEY
 *   GOOGLE_CSE_KEY, GOOGLE_CSE_CX
 *   （任意）TRENDS_* / MIN_INSTALLS / MAX_DAYS / REQUIRE_TESTED / MIN_RATING / WPORG_MAX_PAGES / PUBLISH_HOUR_JST
 *
 * 依存:
 *   - vlucas/phpdotenv
 *   - guzzlehttp/guzzle
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

Dotenv::createImmutable(__DIR__)->load();

/*===============================================================
 * 0) クライアント
 *===============================================================*/
function googleCseClient(): Client {
    static $client = null;
    if ($client) return $client;
    $client = new Client([
        'base_uri' => 'https://www.googleapis.com',
        'timeout'  => 20,
    ]);
    return $client;
}
function wpClient(): Client {
    static $client = null;
    if ($client) return $client;
    $auth = base64_encode($_ENV['WP_API_USER'] . ':' . $_ENV['WP_API_PASS']);
    $client = new Client([
        'base_uri' => rtrim($_ENV['WP_BASE_URL'], '/') . '/',
        'timeout'  => 30,
        'headers'  => ['Authorization' => "Basic {$auth}"],
    ]);
    return $client;
}
function wporgClient(): Client {
    static $client = null;
    if ($client) return $client;
    $client = new Client([
        'base_uri' => 'https://api.wordpress.org',
        'timeout'  => 20,
        'headers'  => ['User-Agent' => 'NA-Bot/1.0 (+https://example.com/)'],
    ]);
    return $client;
}
function geminiClient(): Client {
    static $client = null;
    if ($client) return $client;
    $client = new Client([
        'base_uri' => 'https://generativelanguage.googleapis.com',
        'timeout'  => 60,
    ]);
    return $client;
}

/*===============================================================
 * 1) ログ & デバッグユーティリティ
 *===============================================================*/
function logInfo(string $msg): void {
    file_put_contents(__DIR__ . '/log.txt', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}
/** APIキーを隠したURLを作ってログに出す用 */
function buildQueryUrlForLog(array $q): string {
    $q2 = $q;
    if (isset($q2['key'])) $q2['key'] = '***REDACTED***';
    return 'https://www.googleapis.com/customsearch/v1?' . http_build_query($q2);
}
/** 検索結果を行単位でログ出力 */
function logSearchResults(string $query, string $lang, array $items, string $urlForLog): void {
    logInfo("CSE SEARCH lang={$lang} q=\"{$query}\" url={$urlForLog}");
    logInfo("CSE RESULTS lang={$lang} count=" . count($items));
    $n = 0;
    foreach ($items as $it) {
        $n++;
        $t = $it['title']   ?? '';
        $u = $it['url']     ?? '';
        logInfo(sprintf("  #%02d %s | %s", $n, $t, $u));
    }
}
/** JSONをファイル保存（監査用） */
function saveJsonDebug(string $prefix, array $data): void {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . '/' . date('Ymd_His') . "_{$prefix}.json";
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    logInfo("DEBUG JSON saved: {$file}");
}

/*===============================================================
 * 2) Google トレンド（任意）
 *===============================================================*/
function getTrendingTopics(string $keyword = 'wordpress プラグイン'): array {
    $base = rtrim($_ENV['TRENDS_BASE'] ?? 'https://trends.google.com', '/');
    $hl   = $_ENV['TRENDS_HL']   ?? 'ja';
    $tz   = (int)($_ENV['TRENDS_TZ'] ?? 540);
    $geo  = $_ENV['TRENDS_GEO']  ?? 'JP';
    $time = $_ENV['TRENDS_TIME'] ?? 'now 7-d';

    $client = new Client([
        'base_uri' => $base,
        'timeout'  => 20,
        'headers'  => [
            'User-Agent' => 'Mozilla/5.0',
        ],
    ]);

    $payload = [
        'comparisonItem' => [[ 'keyword' => $keyword, 'geo' => $geo, 'time' => $time ]],
        'category' => 0, 'property' => '',
    ];

    try {
        $explore = $client->get('/trends/api/explore', [
            'query' => ['hl'=>$hl,'tz'=>$tz,'req'=> json_encode($payload, JSON_UNESCAPED_UNICODE)],
        ]);
    } catch (GuzzleException $e) { return []; }

    $json = preg_replace('/^\)\]\}\'/', '', (string)$explore->getBody());
    $data = json_decode($json, true);
    if (!$data || empty($data['widgets'])) return [];

    $widget = null;
    foreach ($data['widgets'] as $w) {
        if (($w['id'] ?? '') === 'RELATED_QUERIES' || ($w['title'] ?? '') === 'Related queries') { $widget = $w; break; }
    }
    if (!$widget) return [];

    try {
        $rq = $client->get('/trends/api/widgetdata/relatedsearches', [
            'query' => ['hl'=>$hl,'tz'=>$tz,'token'=>$widget['token'],'req'=> json_encode($widget['request'], JSON_UNESCAPED_UNICODE)],
        ]);
    } catch (GuzzleException $e) { return []; }

    $json2 = preg_replace('/^\)\]\}\'/', '', (string)$rq->getBody());
    $rqData = json_decode($json2, true);

    $topics = [];
    foreach (($rqData['default']['rankedList'] ?? []) as $list) {
        $type = $list['type'] ?? '';
        foreach ($list['rankedKeyword'] ?? [] as $kw) {
            $q = $kw['query'] ?? ($kw['topic']['title'] ?? null);
            if (!$q) continue;
            $topics[] = ['query'=>$q, 'value'=>(int)($kw['value'] ?? 0), 'type'=>(string)$type];
        }
    }
    return $topics;
}
function pickTodayTopic(array $topics, string $fallback = 'WordPress セキュリティ'): string {
    if (!$topics) return $fallback;
    usort($topics, function ($a, $b) {
        if ($a['type'] !== $b['type']) return $a['type'] === 'RISING' ? -1 : 1;
        return (int)$b['value'] <=> (int)$a['value'];
    });
    return $topics[0]['query'] ?? $fallback;
}

/*===============================================================
 * 3) 既出プラグイン（重複防止）
 *===============================================================*/
function fetchUsedPluginIdentifiers(int $days = 60): array {
    $afterUTC = (new DateTime("-{$days} days", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');
    $page = 1; $names = []; $slugs = [];
    do {
        $res = wpClient()->get('wp-json/wp/v2/posts', [
            'query' => [
                'after'     => $afterUTC,
                'per_page'  => 100,
                'page'      => $page,
                'status'    => 'publish,future',
                '_fields'   => 'title.rendered,content.rendered',
            ],
        ]);
        $posts = json_decode((string) $res->getBody(), true);
        foreach ($posts as $p) {
            $title   = (string)($p['title']['rendered'] ?? '');
            $content = (string)($p['content']['rendered'] ?? '');
            if (preg_match('/^\[PLUGIN_SLUG\]:\s*([a-z0-9\-]+)\s*$/mi', $content, $m)) {
                $slugs[] = mb_strtolower(trim($m[1]));
            }
            if (preg_match('/^(.+?)解説/u', $title, $m2)) {
                $names[] = mb_strtolower(trim($m2[1]));
            }
        }
        $page++;
    } while (is_array($posts) && count($posts) === 100);
    return ['names'=>array_values(array_unique($names)),'slugs'=>array_values(array_unique($slugs))];
}

/*===============================================================
 * 4) WP.org API（JSON/serialize 自動判別 + リトライ）
 *===============================================================*/
function parseWporg(string $body) {
    $json = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) return $json;
    $arr = @unserialize($body, ['allowed_classes' => false]);
    if (is_array($arr) || is_object($arr)) return (array)$arr;
    throw new RuntimeException('WPORG response parse failed');
}
function wporgGetWithRetry(Client $client, string $path, array $query, int $maxTries = 4): string {
    $delay = 300; // ms
    for ($i=1; $i<=$maxTries; $i++) {
        try {
            $res = $client->get($path, [
                'query'   => $query,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $code = $res->getStatusCode();
            $body = (string)$res->getBody();
            if ($code >= 200 && $code < 300) return $body;

            if ($code === 429 || $code >= 500) { usleep($delay*1000); $delay*=2; continue; }
            throw new RuntimeException("WPORG HTTP {$code}");
        } catch (\Throwable $e) {
            if ($i === $maxTries) throw $e;
            usleep($delay*1000); $delay*=2;
        }
    }
    throw new RuntimeException('WPORG request failed (exhausted)');
}
function searchPluginsOnDotOrg(string $query, int $page = 1, int $perPage = 20): array {
    $params = [
        'action'  => 'query_plugins',
        'request' => [
            'search'   => $query,
            'page'     => $page,
            'per_page' => $perPage,
            'fields'   => [
                'short_description' => true,
                'sections'          => false,
                'icons'             => false,
                'banners'           => false,
                'compatibility'     => true,
            ],
        ],
    ];
    $body = wporgGetWithRetry(wporgClient(), '/plugins/info/1.2/', $params);
    $decoded = parseWporg($body);
    $plugins = $decoded['plugins'] ?? [];
    return array_map(fn($p) => is_object($p) ? (array)$p : $p, $plugins);
}
function searchPluginsOnDotOrgWithBrowse(string $browse, int $page = 1, int $perPage = 20): array {
    $params = [
        'action'  => 'query_plugins',
        'request' => [
            'browse'   => $browse,
            'page'     => $page,
            'per_page' => $perPage,
            'fields'   => [
                'short_description' => true,
                'sections'          => false,
                'icons'             => false,
                'banners'           => false,
                'compatibility'     => true,
            ],
        ],
    ];
    $body = wporgGetWithRetry(wporgClient(), '/plugins/info/1.2/', $params);
    $decoded = parseWporg($body);
    $plugins = $decoded['plugins'] ?? [];
    return array_map(fn($p) => is_object($p) ? (array)$p : $p, $plugins);
}
/** 詳細情報（ratings/sections/tags/support_* を含む） */
function getPluginInfoBySlug(string $slug): ?array {
    $params = [
        'action'  => 'plugin_information',
        'request' => [
            'slug'   => $slug,
            'fields' => [
                'short_description'       => true,
                'sections'                => true,
                'tags'                    => true,
                'icons'                   => false,
                'banners'                 => false,
                'contributors'            => false,
                'rating'                  => true,
                'num_ratings'             => true,
                'ratings'                 => true,
                'active_installs'         => true,
                'last_updated'            => true,
                'tested'                  => true,
                'requires'                => true,
                'homepage'                => true,
                'support_threads'         => true,
                'support_threads_resolved'=> true,
            ],
        ],
    ];
    $body = wporgGetWithRetry(wporgClient(), '/plugins/info/1.2/', $params);
    $decoded = parseWporg($body);
    if (!$decoded) return null;
    $arr = is_object($decoded) ? (array)$decoded : $decoded;
    $arr['slug'] = $slug;
    return $arr;
}

/*===============================================================
 * 5) 事実抽出（readme 由来の機能メモ／将来拡張用）
 *===============================================================*/
function enrichPluginFacts(array $info, string $officialUrl): array {
    $sections = $info['sections'] ?? [];
    $tags     = array_map('strval', array_keys($info['tags'] ?? []));
    $blob     = mb_strtolower(
        implode("\n\n", array_map('strval', [
            $info['short_description'] ?? '',
            $sections['description'] ?? '',
            $sections['faq'] ?? '',
            $sections['changelog'] ?? '',
        ]))
    );

    $hasRecaptcha = (bool)preg_match('/recaptcha|captcha/u', $blob) || in_array('recaptcha', $tags, true);
    $hasHoneypot  = (bool)preg_match('/honeypot/u', $blob)      || in_array('honeypot',  $tags, true);
    $hasCsv       = (bool)preg_match('/csv/u', $blob)           || in_array('csv',       $tags, true);

    $evidence = [
        ['claim' => '公式配布中', 'source' => $officialUrl],
        ['claim' => '最終更新/対応WP/Install数', 'source' => $officialUrl],
    ];

    return [
        'features' => [
            'recaptcha'  => $hasRecaptcha,
            'honeypot'   => $hasHoneypot,
            'csv_export' => $hasCsv,
        ],
        'evidence' => $evidence,
    ];
}

/*===============================================================
 * 6) ユーザー評価の要約（必要なら使用）
 *===============================================================*/
function summarizeUserFeedback(array $info): array {
    $ratings = $info['ratings'] ?? []; // [1=>x,2=>y,3=>z,4=>a,5=>b]
    $total   = is_array($ratings) ? array_sum($ratings) : 0;

    $resolved = (int)($info['support_threads_resolved'] ?? 0);
    $threads  = (int)($info['support_threads'] ?? 0);
    $supportRate = ($threads > 0) ? ($resolved / $threads) : null; // 0〜1 or null

    $pos = ($total > 0) ? (((int)($ratings[5] ?? 0) + (int)($ratings[4] ?? 0)) / $total) : 0;
    $neg = ($total > 0) ? (((int)($ratings[1] ?? 0) + (int)($ratings[2] ?? 0)) / $total) : 0;

    $positives = [];
    $negatives = [];

    if ($total >= 10 && $pos >= 0.60) {
        $positives[] = '使いやすさ・安定性に対する高評価が多い（★4〜5が6割以上）。';
    }
    if ($supportRate !== null) {
        if ($supportRate >= 0.5 && $threads >= 10) {
            $positives[] = 'サポートフォーラムの解決率が比較的高い。';
        } elseif ($threads >= 10 && $supportRate < 0.3) {
            $negatives[] = 'サポートフォーラムの解決率が低めの傾向。';
        }
    }
    if ($total >= 10 && $neg >= 0.20) {
        $negatives[] = '低評価（★1〜2）の割合が2割以上。設定難度や環境依存の指摘に注意。';
    }

    $installs = (int)($info['active_installs'] ?? 0);
    if ($installs >= 100000) {
        $positives[] = '導入実績が多く、運用ノウハウが見つかりやすい。';
    } elseif ($installs < 1000) {
        $negatives[] = '導入実績が少なく、情報が限られる可能性。';
    }

    return [
        'total_ratings'  => $total,
        'positive_ratio' => $pos,
        'negative_ratio' => $neg,
        'support_rate'   => $supportRate,
        'highlights'     => $positives,
        'cautions'       => $negatives,
    ];
}

/*===============================================================
 * 7) 候補探索・足切り
 *===============================================================*/
function isPluginViable(
    array $p,
    string $currentWpMajor = '6.6',
    ?int $minInstalls = null,
    ?int $maxDays = null,
    ?bool $requireTested = null,
    ?int $minRating = null
): bool {
    $minInstalls   = $minInstalls   ?? (int)($_ENV['MIN_INSTALLS'] ?? 500);
    $maxDays       = $maxDays       ?? (int)($_ENV['MAX_DAYS']     ?? 730);
    $requireTested = $requireTested ?? (bool)($_ENV['REQUIRE_TESTED'] ?? false);
    $minRating     = $minRating     ?? (int)($_ENV['MIN_RATING'] ?? 60);

    if ((int)($p['active_installs'] ?? 0) < $minInstalls) return false;

    $rating = (int)($p['rating'] ?? 0); // 0〜100（★平均の百分率）
    if ($rating < $minRating) return false;

    $lastUpdated = $p['last_updated'] ?? '';
    if ($lastUpdated) {
        try {
            $last = new DateTime($lastUpdated);
            if ($last < (new DateTime("-{$maxDays} days"))) return false;
        } catch (\Throwable $e) { return false; }
    } else return false;

    if ($requireTested) {
        $tested = (string)($p['tested'] ?? '');
        if ($tested === '') return false;
        $testedMajor = implode('.', array_slice(explode('.', $tested), 0, 2));
        if ($testedMajor !== '' && version_compare($testedMajor, $currentWpMajor, '<')) return false;
    }

    $sd = (string)($p['short_description'] ?? '');
    if (stripos($sd, 'This plugin has been closed') !== false) return false;

    return true;
}
function findCandidatePlugin(string $topic, array $usedNames, array $usedSlugs, string $currentWpMajor = '6.6'): ?array {
    $topicBase = trim(preg_replace('/プラグイン/u', '', $topic));
    $queries = array_values(array_unique(array_filter([
        $topic, $topicBase,
        'WordPress セキュリティ','WordPress 画像 最適化','WordPress バックアップ','WordPress キャッシュ',
        'フォーム','画像圧縮','SEO','security','backup','cache','caching','image optimization',
        'compression','forms','antispam','firewall','performance','seo','redirect','gallery','analytics',
        'migration','multilingual','woocommerce','WordPress 引越し','WordPress 保守','WordPress メンテナンス','WordPress ハッキング',
    ])));

    $maxQueryPages = (int)($_ENV['WPORG_MAX_PAGES'] ?? 3);

    foreach ($queries as $q) {
        for ($page = 1; $page <= $maxQueryPages; $page++) {
            $plugins = searchPluginsOnDotOrg($q, $page);
            if (!$plugins) continue;

            $plugins = array_filter($plugins, function($p) use ($usedNames, $usedSlugs) {
                $n = mb_strtolower($p['name'] ?? '');
                $s = mb_strtolower($p['slug'] ?? '');
                return !in_array($n, $usedNames, true) && !in_array($s, $usedSlugs, true);
            });

            $plugins = array_filter($plugins, function($p) use ($currentWpMajor) {
                return isPluginViable($p, $currentWpMajor, null, null, null, null);
            });
            if (!$plugins) continue;

            usort($plugins, function($a, $b) {
                $ai = ((int)($b['active_installs'] ?? 0)) <=> ((int)($a['active_installs'] ?? 0));
                if ($ai !== 0) return $ai;
                $ldA = !empty($a['last_updated']) ? strtotime($a['last_updated']) : 0;
                $ldB = !empty($b['last_updated']) ? strtotime($b['last_updated']) : 0;
                return $ldB <=> $ldA;
            });

            $p        = array_values($plugins)[0];
            $detail   = getPluginInfoBySlug($p['slug']) ?? $p;

            return [
                'name'            => $p['name'],
                'slug'            => $p['slug'],
                'url'             => 'https://wordpress.org/plugins/' . $p['slug'] . '/',
                'active_installs' => (int)($detail['active_installs'] ?? $p['active_installs'] ?? 0),
                'last_updated'    => (string)($detail['last_updated'] ?? $p['last_updated'] ?? ''),
                'tested'          => (string)($detail['tested'] ?? $p['tested'] ?? ''),
                'rating'          => (int)($detail['rating'] ?? 0),
                'num_ratings'     => (int)($detail['num_ratings'] ?? 0),
                'ratings'         => $detail['ratings'] ?? [],
                'requires'        => (string)($detail['requires'] ?? ''),
                'support_threads' => (int)($detail['support_threads'] ?? 0),
                'support_threads_resolved' => (int)($detail['support_threads_resolved'] ?? 0),
                'sections'        => $detail['sections'] ?? [],
                'tags'            => $detail['tags'] ?? [],
            ];
        }
    }

    // fallback: popular
    for ($page = 1; $page <= min(2, $maxQueryPages); $page++) {
        $popular = searchPluginsOnDotOrgWithBrowse('popular', $page, 30);
        if (!$popular) continue;

        $popular = array_filter($popular, function($p) use ($usedNames, $usedSlugs) {
            $n = mb_strtolower($p['name'] ?? '');
            $s = mb_strtolower($p['slug'] ?? '');
            return !in_array($n, $usedNames, true) && !in_array($s, $usedSlugs, true);
        });

        $popular = array_filter($popular, function($p) use ($currentWpMajor) {
            return isPluginViable($p, $currentWpMajor, null, null, null, null);
        });
        if (!$popular) continue;

        usort($popular, function($a, $b) {
            return ((int)($b['active_installs'] ?? 0)) <=> ((int)($a['active_installs'] ?? 0));
        });

        $p      = array_values($popular)[0];
        $detail = getPluginInfoBySlug($p['slug']) ?? $p;

        return [
            'name'            => $p['name'],
            'slug'            => $p['slug'],
            'url'             => 'https://wordpress.org/plugins/' . $p['slug'] . '/',
            'active_installs' => (int)($detail['active_installs'] ?? $p['active_installs'] ?? 0),
            'last_updated'    => (string)($detail['last_updated'] ?? $p['last_updated'] ?? ''),
            'tested'          => (string)($detail['tested'] ?? $p['tested'] ?? ''),
            'rating'          => (int)($detail['rating'] ?? 0),
            'num_ratings'     => (int)($detail['num_ratings'] ?? 0),
            'ratings'         => $detail['ratings'] ?? [],
            'requires'        => (string)($detail['requires'] ?? ''),
            'support_threads' => (int)($detail['support_threads'] ?? 0),
            'support_threads_resolved' => (int)($detail['support_threads_resolved'] ?? 0),
            'sections'        => $detail['sections'] ?? [],
            'tags'            => $detail['tags'] ?? [],
        ];
    }

    return null;
}

/*===============================================================
 * 8) CSE（日・英）検索
 *===============================================================*/
/** lang: 'ja' | 'en' */
function googleSearchTop10(string $query, string $lang = 'ja'): array {
    $hl = $lang === 'ja' ? 'ja' : 'en';
    $gl = $lang === 'ja' ? 'jp' : 'us';
    $lr = $lang === 'ja' ? 'lang_ja' : 'lang_en';

    $delay = 200; // ms
    for ($i=0; $i<3; $i++) {
        try {
            $q = [
                'key'    => $_ENV['GOOGLE_CSE_KEY'],
                'cx'     => $_ENV['GOOGLE_CSE_CX'],
                'q'      => $query,
                'num'    => 10,
                'hl'     => $hl,
                'gl'     => $gl,
                'lr'     => $lr,
                'fields' => 'items(title,link,snippet)',
            ];
            $urlForLog = buildQueryUrlForLog($q);

            $res = googleCseClient()->get('/customsearch/v1', ['query' => $q]);
            $json = json_decode((string)$res->getBody(), true);
            $itemsRaw = $json['items'] ?? [];
            $items = array_map(fn($it) => [
                'title'   => (string)($it['title'] ?? ''),
                'url'     => (string)($it['link'] ?? ''),
                'snippet' => (string)($it['snippet'] ?? ''),
            ], $itemsRaw);

            logSearchResults($query, $lang, $items, $urlForLog);
            saveJsonDebug("cse_{$lang}_" . preg_replace('/\W+/u','_',$query), $items);

            return $items;
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $code = $e->getResponse()?->getStatusCode() ?? 0;
            logInfo("CSE ERROR lang={$lang} q=\"{$query}\" code={$code} msg=" . $e->getMessage());
            if ($code === 429 || $code >= 500) { usleep($delay*1000); $delay *= 2; continue; }
            throw $e;
        }
    }
    logInfo("CSE GIVEUP lang={$lang} q=\"{$query}\"");
    return [];
}
/** JA/EN で検索し、URL重複を除いて統合 */
function collectSearchSources(string $topic): array {
    $ja = googleSearchTop10($topic, 'ja');
    $en = googleSearchTop10($topic, 'en');

    // URL基準で重複除去（クエリ/ハッシュを除去）
    $seen = [];
    $norm = function (string $u): string {
        $u = preg_replace('/\#.*$/', '', $u);
        $u = preg_replace('/\?.*$/', '', $u);
        $u = rtrim($u, '/');
        return mb_strtolower($u);
    };

    $out = [];
    foreach (array_merge($ja, $en) as $row) {
        $k = $norm($row['url']);
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $row;
    }

    logInfo("CSE MERGED sources for topic=\"{$topic}\" total=" . count($out));
    $i = 0;
    foreach ($out as $r) {
        $i++;
        logInfo(sprintf("  M#%02d %s | %s", $i, $r['title'], $r['url']));
    }
    saveJsonDebug("cse_merged_" . preg_replace('/\W+/u','_',$topic), $out);

    return $out;
}

/*===============================================================
 * 9) ブリーフ / プロンプト（Gutenberg）
 *===============================================================*/
function buildMasterPrompt(): string {
    return <<<PROMPT
あなたはWordPress運用ブログの編集者です。対象読者は「WordPress初〜中級のウェブ担当者」。
以下のルールを厳守してください。

【出力形式（最重要）】
- Markdownは使わない。**Gutenbergブロックコメント付きHTMLのみ**で出力する。
- 例：段落は「<!-- wp:paragraph -->...<!-- /wp:paragraph -->」、見出しは「<!-- wp:heading {"level":2} -->...<!-- /wp:heading -->」

【原則】
- 事実と意見を分ける。事実には根拠・出典を付ける（出典URLは {{SOURCE_URL}} のまま出力）。
- 同一文型の連続を避け、段落ごとに文長・接続表現を変える。
- 見出しは「悩み→解決→手順→注意点→まとめ」を基本。
- 文体は人間らしく親しみやすく、会話的だが丁寧。専門用語には補足。

【機能と事実の扱い】
- features_detected の true の項目だけ機能として触れる（false/不明は書かない）。
- WordPress.org のメタ（インストール数・最終更新・対応WP）は事実として記載可。

【禁止・制限】
- Markdownや、ブロックコメントのないプレーンHTMLの出力は禁止。
- 断定しすぎる表現は避け、条件を明示。

【アウトライン】
- 冒頭：結論サマリー（paragraph）
- 本文：h2/h3 と list/paragraph/必要に応じ code
- 最後：簡易チェックリスト（list）と参考リンク（list、{{SOURCE_URL}} を使う）

【追加要求】
- 検索記事の要点を要約するだけでなく、以下を必ず盛り込むこと：
  1. 導入時のつまずきやすいポイントと解決策
  2. おすすめの初期設定例
  3. 規模別の運用アドバイス
- 読者が「この記事だけで導入〜運用のイメージが掴める」レベルにすること。

PROMPT;
}
function pickArticleTypeByDay(): string {
    $map = [0=>'comparison',1=>'troubleshooting',2=>'howto',3=>'best_practices',4=>'roundup',5=>'security',6=>'performance'];
    $w = (int)date('w'); // 0:日
    return $map[$w] ?? 'howto';
}
function buildBrief(array $plugin, string $articleType): array {
    $sectionsMap = [
        'comparison'      => ["導入背景","選定基準","他プラグインとの違い","向き不向き","まとめ"],
        'troubleshooting' => ["症状","想定原因","確認方法","恒久対策","再発防止"],
        'howto'           => ["できること","前提条件","最短手順","つまずきポイント","応用"],
        'best_practices'  => ["初期設定","セキュリティ/スパム対策","運用ルール","点検チェック"],
        'roundup'         => ["選定基準","主な特徴","ケース別おすすめ","まとめ"],
        'security'        => ["脅威の整理","保護設定","ログ監視","定期点検"],
        'performance'     => ["ボトルネック","キャッシュ/画像最適化","計測と見直し"],
    ];
    $sections = $sectionsMap[$articleType] ?? ["導入背景","最短手順","注意点","代替案"];

    return [
        "article_type" => $articleType,
        "persona"      => "初心者の広報担当",
        "topic"        => "WordPressプラグイン",
        "primary_plugin" => [
            "name"   => $plugin['name'],
            "slug"   => $plugin['slug'],
            "verified" => true,
            "meta"   => [
                "active_installs" => $plugin['active_installs'] ?? 0,
                "last_updated"    => $plugin['last_updated'] ?? "",
                "tested_up_to"    => $plugin['tested'] ?? "",
                "rating"          => $plugin['rating'] ?? 0,
                "num_ratings"     => $plugin['num_ratings'] ?? 0,
                "requires"        => $plugin['requires'] ?? "",
            ],
            "source_url" => "{{SOURCE_URL}}"
        ],
        "features_detected" => $plugin['facts']['features'] ?? [],
        "sections"     => $sections,
        "tone"         => "丁寧で実務的",
        "length"       => "1200-1600",
    ];
}
function buildGutenbergPreamble(string $pluginName, string $pluginSlug, string $officialUrl): string {
    return <<<P
必ず記事冒頭に下記3行を**そのまま**出力すること（検証用）。
[PLUGIN_NAME]: {$pluginName}
[PLUGIN_SLUG]: {$pluginSlug}
[OFFICIAL_URL]: {$officialUrl}

**以下は必ず Gutenberg ブロックコメント付きHTMLで出力する（Markdown禁止）。**
最初の h2 見出しは「{$pluginName}」とする。本文中に公式URL（{$officialUrl}）を1回以上記載する。

【使用ブロック例】
<!-- wp:paragraph -->
<p>段落テキスト</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2>見出し</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul><li>箇条書き1</li><li>箇条書き2</li></ul>
<!-- /wp:list -->
P;
}
function buildTitlePrompt(array $brief): string {
    $briefJson = json_encode($brief, JSON_UNESCAPED_UNICODE);
    return <<<PROMPT
次のブリーフを要約し、検索ユーザーの悩みと利点が一目で伝わる日本語タイトルを10本、30〜38文字で出力。
- 重複語尾を避ける
- 語順に変化をつける
- 「WordPress プラグイン」を1回だけ自然に含める
- 出力は箇条書きのみ

ブリーフ:
{$briefJson}
PROMPT;
}
function buildBodyPrompt(
    array $brief,
    array $verification,
    string $chosenTitle,
    string $pluginName,
    string $pluginSlug,
    string $officialUrl,
    array $searchSources
): string {
    $master     = buildMasterPrompt();
    $briefJson  = json_encode($brief, JSON_UNESCAPED_UNICODE);
    $verJson    = json_encode($verification, JSON_UNESCAPED_UNICODE);
    $preamble   = buildGutenbergPreamble($pluginName, $pluginSlug, $officialUrl);
    $sourcesJson= json_encode($searchSources, JSON_UNESCAPED_UNICODE);

    return <<<PROMPT
{$master}

【検証データ(verification)】
verified=false のプラグインは本文・タイトルとも登場禁止:
{$verJson}

【採用タイトル】
{$chosenTitle}

【ブリーフ(JSON)】
{$briefJson}

【検索で確認した参考記事（日本語/英語 上位）】
{$sourcesJson}

【Gutenberg専用の前置き指示（厳守）】
{$preamble}

- features_detected の true のみ機能節に含める（false/不明は触れない）。
- 事実節では WordPress.org のメタ（インストール数/最終更新/対応WP）を簡潔に示す。
- 参考リンク一覧を最後に作る。各リスト項目のURLは {{SOURCE_URL}} の形で置き、本文中の根拠提示にも最低1回 {{SOURCE_URL}} を含める。
- 検索で得た要点を、日本語の自然な文で人肌のトーンに要約し、重複やテンプレ的な言い回しを避ける。
- 最後に簡易チェックリスト（list）。

**注意**：MarkdownやプレーンHTMLのみの出力は禁止。必ず「<!-- wp:... -->」形式のブロックで構成せよ。
PROMPT;
}

/*===============================================================
 * 10) Gemini 生成
 *===============================================================*/
function generateContent(string $prompt): string {
    $res = geminiClient()->post('/v1beta/models/gemini-1.5-flash:generateContent', [
        'query' => ['key' => $_ENV['GEMINI_API_KEY']],
        'json'  => ['contents' => [[ 'role'=>'user', 'parts'=>[['text'=>$prompt]] ]]],
    ]);
    $body = json_decode((string)$res->getBody(), true);
    return $body['candidates'][0]['content']['parts'][0]['text'] ?? '(生成失敗)';
}

/*===============================================================
 * 11) 出力検証（ブロック必須）
 *===============================================================*/
function validateGeneratedContent(string $content, string $pluginName, string $pluginSlug, string $officialUrl): void {
    if (!preg_match('/^\[PLUGIN_NAME\]:\s*'.preg_quote($pluginName,'/').'\s*$/mi', $content)) {
        throw new RuntimeException('[PLUGIN_NAME] 不一致/欠落');
    }
    if (!preg_match('/^\[PLUGIN_SLUG\]:\s*'.preg_quote($pluginSlug,'/').'\s*$/mi', $content)) {
        throw new RuntimeException('[PLUGIN_SLUG] 不一致/欠落');
    }
    if (!preg_match('/^\[OFFICIAL_URL\]:\s*'.preg_quote($officialUrl,'/').'\s*$/mi', $content)) {
        throw new RuntimeException('[OFFICIAL_URL] 不一致/欠落');
    }
    if (!preg_match('/<!--\s*wp:/i', $content)) {
        throw new RuntimeException('Gutenbergブロックが検出できません（Markdown/プレーンHTMLの可能性）');
    }
    if (!preg_match('/<!--\s*wp:heading[^>]*-->\s*<h2[^>]*>\s*'.preg_quote($pluginName,'/').'\s*<\/h2>\s*<!--\s*\/wp:heading\s*-->/i', $content)) {
        throw new RuntimeException('h2（プラグイン名）の heading ブロックがありません');
    }
    if (stripos($content, $officialUrl) === false) {
        throw new RuntimeException('本文中に公式URLが含まれていません');
    }
}
/** evidence/SOURCE_URLの最低要件チェック（簡易） */
function validateEvidence(string $content): void {
    if (strpos($content, '{{SOURCE_URL}}') === false) {
        throw new RuntimeException('出典(SOURCE_URL)が本文に含まれていません');
    }
}

/*===============================================================
 * 12) 投稿
 *===============================================================*/
function postToWordPress(string $title, string $content, DateTime $publishGMT): array {
    $res = wpClient()->post('wp-json/wp/v2/posts', [
        'headers' => ['Content-Type' => 'application/json'],
        'json'    => [
            'title'    => $title,
            'content'  => $content,
            'status'   => 'future',
            'date_gmt' => $publishGMT->format('Y-m-d\TH:i:s'),
        ],
    ]);
    return json_decode((string)$res->getBody(), true) ?? [];
}

/*===============================================================
 * 13) メイン
 *===============================================================*/
try {
    // 1) トピック（任意）
    $todayTopic = pickTodayTopic(getTrendingTopics(), 'WordPress セキュリティ');
    logInfo("TOPIC selected: {$todayTopic}");

    // 2) 既出（名前/slug）収集
    $used = fetchUsedPluginIdentifiers(60);
    $usedNames = $used['names'] ?? [];
    $usedSlugs = $used['slugs'] ?? [];
    logInfo("USED names=" . count($usedNames) . " slugs=" . count($usedSlugs));

    // 3) 候補（詳細メタ付き）
    $candidate = findCandidatePlugin($todayTopic, $usedNames, $usedSlugs, '6.6');
    if (!$candidate) {
        logInfo("候補見つからず: topic={$todayTopic} → スキップ");
        echo "候補が見つからなかったためスキップしました\n";
        exit(0);
    }

    $pluginName  = $candidate['name'];
    $pluginSlug  = $candidate['slug'];
    $officialUrl = $candidate['url'];
    logInfo("CANDIDATE plugin={$pluginName} slug={$pluginSlug} url={$officialUrl}");

    // 4) 検索（JA/EN） → マージ
    $searchTopic   = "{$pluginName} WordPress";
    $searchSources = collectSearchSources($searchTopic);
    logInfo("PROMPT INPUT sources_count=" . count($searchSources));
    saveJsonDebug('prompt_sources', $searchSources);

    // 5) 事実抽出
    $infoFull = getPluginInfoBySlug($pluginSlug) ?? [];
    $facts    = enrichPluginFacts($infoFull ?: $candidate, $officialUrl);

    // 6) 記事タイプ（曜日ローテ）
    $articleType = pickArticleTypeByDay();

    // 7) ブリーフ＆verification
    $candidate['facts'] = $facts;
    $brief        = buildBrief($candidate, $articleType);
    $verification = [ $pluginSlug => ["verified"=>true, "source_url"=>"{{SOURCE_URL}}"] ];
    saveJsonDebug('brief', $brief);

    // 8) タイトル10本 → 採択
    $titlesRaw = generateContent(buildTitlePrompt($brief));
    $titleLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $titlesRaw))));
    $titleCandidates = [];
    foreach ($titleLines as $line) {
        $line = preg_replace('/^[\-\*\d\.\)\s]+/u', '', $line);
        if ($line !== '') $titleCandidates[] = $line;
    }
    $chosenTitle = $titleCandidates[0] ?? "{$pluginName}の特徴と導入手順を徹底解説";
    logInfo("TITLE chosen: {$chosenTitle}");

    // 9) 本文生成（最大3回検証）
    $attempt = 0; $content = '';
    while ($attempt < 3) {
        $attempt++;
        $content = generateContent(
            buildBodyPrompt($brief, $verification, $chosenTitle, $pluginName, $pluginSlug, $officialUrl, $searchSources)
        );
        try {
            validateGeneratedContent($content, $pluginName, $pluginSlug, $officialUrl);
            validateEvidence($content);
            break;
        } catch (\Throwable $e) {
            logInfo("生成検証NG({$attempt}): " . $e->getMessage());
            $content = '';
        }
    }
    if ($content === '') throw new RuntimeException('生成が要件を満たしませんでした（3回失敗）');

    // 10) 予約タイトル
    $title = "{$chosenTitle}（" . date('Y-m-d') . '）';

    // 11) 予約時刻（明朝 7:00 JST → UTC）
    $hourJst = sprintf('%02d', (int)($_ENV['PUBLISH_HOUR_JST'] ?? '07'));
    $publishJST = new DateTime("tomorrow {$hourJst}:00", new DateTimeZone('Asia/Tokyo'));
    $publishGMT = (clone $publishJST)->setTimezone(new DateTimeZone('UTC'));

    // 12) 投稿
    $post = postToWordPress($title, $content, $publishGMT);
    $postId = $post['id'] ?? '???';
    echo "予約投稿 ID {$postId} を作成しました\n";
    logInfo("予約投稿 ID {$postId} / topic={$todayTopic} / plugin={$pluginName} ({$pluginSlug})");
} catch (Throwable $e) {
    logInfo('投稿失敗: ' . $e->getMessage());
    throw $e;
}

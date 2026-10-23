<?php
declare(strict_types=1);

/**
 * Static-only, bounded readout of a source-pinned production V4 router and
 * direct V4 controller DECLARATIONS. Never require/eval source, run Bitrix,
 * read configuration, execute SQL, print method bodies or request/user data.
 *
 * PUBLIC route templates and PHP identifiers only; no arbitrary strings.
 */

function v5v4Fail(string $why): never
{
    fwrite(STDERR, "FAIL: {$why}\n");
    exit(2);
}

/** @param array<int, array{int,string,int}|string> $tokens */
function v5v4Next(array $tokens, int $i): int
{
    $count = count($tokens);
    while ($i < $count && is_array($tokens[$i])
        && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        $i++;
    }
    return $i;
}

function v5v4Identifier(string $value): bool
{
    return strlen($value) <= 120
        && preg_match('/\A\\\\?[A-Za-z_][A-Za-z_0-9]*(?:\\\\[A-Za-z_][A-Za-z_0-9]*)*\z/D', $value) === 1;
}

function v5v4Route(string $quoted): string
{
    $len = strlen($quoted);
    if ($len < 3 || !in_array($quoted[0], ["'", '"'], true)
        || $quoted[0] !== $quoted[$len - 1]) {
        return 'UNRESOLVED';
    }
    $value = substr($quoted, 1, -1);
    // Never expose source strings unless they look precisely like an HTTP
    // route/path or regex path; exclude shell/URI query assignment syntax.
    if (strlen($value) > 160
        || preg_match('~\A(?:/|\^/?|#\^?/)[A-Za-z0-9_./\^:$*+?{}()\[\]|\-\\\\#]*\z~D', $value) !== 1) {
        return 'UNRESOLVED';
    }
    return $value;
}

/** @param array<int, array{int,string,int}|string> $tokens
 * @return array<int, array{line:int,method:string,path:string,hints:string}>
 */
function v5v4Routes(array $tokens): array
{
    $routes = [];
    $allowed = [
        'get'=>true, 'post'=>true, 'put'=>true, 'patch'=>true,
        'delete'=>true, 'options'=>true, 'head'=>true,
        'match'=>true, 'all'=>true, 'any'=>true, 'mount'=>true,
    ];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i])
            || !in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }
        $m = v5v4Next($tokens, $i + 1);
        if (!isset($tokens[$m]) || !is_array($tokens[$m])
            || $tokens[$m][0] !== T_STRING) {
            continue;
        }
        $method = strtolower($tokens[$m][1]);
        if (!isset($allowed[$method])) {
            continue;
        }
        $op = v5v4Next($tokens, $m + 1);
        if (($tokens[$op] ?? null) !== '(') {
            continue;
        }
        $first = v5v4Next($tokens, $op + 1);
        $path = 'DYNAMIC_UNRESOLVED';
        if (isset($tokens[$first]) && is_array($tokens[$first])
            && $tokens[$first][0] === T_CONSTANT_ENCAPSED_STRING) {
            $path = v5v4Route($tokens[$first][1]);
        }

        $hints = [];
        $depth = 1;
        $start = $first;
        $end = null;
        for ($j = $op + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    $end = $j;
                    break;
                }
            }
        }
        if ($end === null) {
            v5v4Fail('unclosed router method expression');
        }
        // Output only syntactically valid public PHP class/method names.
        // Hints are lexical and may refer to conditional closure bodies.
        $scanEnd = min($end, $start + 220);
        for ($j = $start; $j < $scanEnd; $j++) {
            $t = $tokens[$j];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NEW) {
                $n = v5v4Next($tokens, $j + 1);
                if (isset($tokens[$n]) && is_array($tokens[$n])
                    && in_array($tokens[$n][0],
                        [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && v5v4Identifier($tokens[$n][1])) {
                    $hints[] = 'new:' . $tokens[$n][1];
                }
            }
            if (in_array($t[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $n = v5v4Next($tokens, $j + 1);
                if (isset($tokens[$n]) && is_array($tokens[$n])
                    && $tokens[$n][0] === T_STRING
                    && v5v4Identifier($tokens[$n][1])) {
                    $hints[] = 'call:' . $tokens[$n][1];
                }
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING && $j !== $first) {
                $lit = substr($t[1], 1, -1);
                if (strlen($lit) <= 120
                    && preg_match('/\A\\\\?[A-Za-z_][A-Za-z_0-9\\\\]*(?:@|::)[A-Za-z_][A-Za-z_0-9]*\z/D', $lit)) {
                    $hints[] = 'callback:' . $lit;
                }
            }
        }
        $hints = array_slice(array_values(array_unique($hints)), 0, 8);
        $routes[] = [
            'line' => $tokens[$m][2],
            'method' => strtoupper($method),
            'path' => $path,
            'hints' => $hints === [] ? 'NONE' : implode(',', $hints),
        ];
        if (count($routes) > 160) {
            v5v4Fail('unexpected router registration count');
        }
    }
    return $routes;
}

/**
 * @return array{classes:string,methods:string,deps:string}
 */
function v5v4Controller(string $source): array
{
    $tokens = token_get_all($source);
    $classes = [];
    $methods = [];
    $deps = [];
    $total = count($tokens);
    for ($i = 0; $i < $total; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_CLASS || $t[0] === T_INTERFACE || $t[0] === T_TRAIT) {
            $n = v5v4Next($tokens, $i + 1);
            if (isset($tokens[$n]) && is_array($tokens[$n])
                && $tokens[$n][0] === T_STRING
                && v5v4Identifier($tokens[$n][1])) {
                $classes[] = $tokens[$n][1];
            }
        }
        if ($t[0] === T_FUNCTION) {
            $n = v5v4Next($tokens, $i + 1);
            if (($tokens[$n] ?? null) === '&') {
                $n = v5v4Next($tokens, $n + 1);
            }
            if (!isset($tokens[$n]) || !is_array($tokens[$n])
                || $tokens[$n][0] !== T_STRING
                || !v5v4Identifier($tokens[$n][1])) {
                continue;
            }
            $visibility = 'default';
            for ($k = $i - 1; $k >= max(0, $i - 20); $k--) {
                $p = $tokens[$k];
                if (!is_array($p) && in_array($p, ['{', '}', ';'], true)) {
                    break;
                }
                if (is_array($p)) {
                    if ($p[0] === T_PUBLIC) {
                        $visibility = 'public';
                    } elseif ($p[0] === T_PRIVATE) {
                        $visibility = 'private';
                    } elseif ($p[0] === T_PROTECTED) {
                        $visibility = 'protected';
                    }
                }
            }
            $methods[] = $visibility . ':' . $tokens[$n][1];
        }
        if ($t[0] === T_NEW) {
            $n = v5v4Next($tokens, $i + 1);
            if (isset($tokens[$n]) && is_array($tokens[$n])
                && in_array($tokens[$n][0],
                    [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && v5v4Identifier($tokens[$n][1])) {
                $deps[] = 'new:' . $tokens[$n][1];
            }
        }
        if ($t[0] === T_DOUBLE_COLON) {
            $p = $i - 1;
            while ($p >= 0 && is_array($tokens[$p])
                && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT], true)) {
                $p--;
            }
            if ($p >= 0 && is_array($tokens[$p])
                && in_array($tokens[$p][0],
                    [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && v5v4Identifier($tokens[$p][1])) {
                $deps[] = 'static:' . $tokens[$p][1];
            }
        }
    }
    $classes = array_values(array_unique($classes));
    $methods = array_values(array_unique($methods));
    $deps = array_values(array_unique($deps));
    return [
        'classes' => implode(',', array_slice($classes, 0, 10)) ?: 'NONE',
        'methods' => implode(',', array_slice($methods, 0, 55)) ?: 'NONE',
        'deps' => implode(',', array_slice($deps, 0, 24)) ?: 'NONE',
    ];
}

if (count($argv) === 2 && $argv[1] === '--self-test') {
    $fixture = <<<'SOURCE'
<?php
// $router->post('/commented', 'Hidden@call');
$router->mount('/business', function () use ($router) {
    $router->get('/profile', [Profile::class, 'show']);
    $router->post('/bonus', [Loyalty::class, 'summary']);
});
$router->get('secret=value', function () {});
SOURCE;
    $routes = v5v4Routes(token_get_all($fixture));
    if (count($routes) !== 4
        || $routes[0]['path'] !== '/business'
        || $routes[1]['path'] !== '/profile'
        || $routes[2]['path'] !== '/bonus'
        || $routes[3]['path'] !== 'UNRESOLVED'
        || str_contains(json_encode($routes, JSON_THROW_ON_ERROR), 'secret=value')) {
        v5v4Fail('fixture route extraction/comment/literal masking');
    }
    $methods = v5v4Controller(<<<'SOURCE'
<?php
class Profile { public function show(): void {} private function update(): void {} }
SOURCE
    );
    if (!str_contains($methods['methods'], 'public:show')
        || !str_contains($methods['methods'], 'private:update')
        || $methods['classes'] !== 'Profile') {
        v5v4Fail('fixture class/visibility extraction');
    }
    echo "PASS: lexical public-route and controller method selftest\n";
    echo "SOURCE_EXECUTED=NO\nDATABASE_CONNECTION=NO\n";
    echo "SYNTHETIC_ACCOUNTS_CREATED=NO\n";
    exit(0);
}

if (count($argv) !== 4 || $argv[1] !== '--source-only') {
    v5v4Fail('require exactly --source-only <router.php> <Controllers/V4>');
}
$routerPath = $argv[2];
$controllersDir = $argv[3];
if (!is_file($routerPath) || !is_readable($routerPath) || is_link($routerPath)
    || filesize($routerPath) < 100 || filesize($routerPath) > 2000000
    || !is_dir($controllersDir) || is_link($controllersDir)) {
    v5v4Fail('invalid pinned source arguments');
}
$raw = file_get_contents($routerPath);
if (!is_string($raw)) {
    v5v4Fail('router unreadable');
}
echo "=== 3. PINNED V4 ROUTE DECLARATIONS ===\n";
echo 'V4_ROUTER_SHA256=' . hash('sha256', $raw) . "\n";
$routes = v5v4Routes(token_get_all($raw));
echo "ROUTE_REGISTRATIONS=" . count($routes) . "\n";
foreach ($routes as $route) {
    printf(
        "LINE=%d METHOD=%s PATH=%s CALLBACK_HINTS=%s\n",
        $route['line'],
        $route['method'],
        $route['path'],
        $route['hints']
    );
}
$files = glob($controllersDir . '/*.php') ?: [];
sort($files, SORT_STRING);
if (count($files) < 1 || count($files) > 70) {
    v5v4Fail('unexpected direct V4 controller count');
}
echo "\n=== 4. V4 CONTROLLER DECLARATIONS (NO SOURCE BODIES) ===\n";
echo 'CONTROLLER_FILES=' . count($files) . "\n";
foreach ($files as $file) {
    if (!is_file($file) || !is_readable($file) || is_link($file)
        || realpath($file) !== $file || filesize($file) > 1200000) {
        v5v4Fail('unsafe V4 controller file');
    }
    $name = basename($file);
    if (!preg_match('/\A[A-Za-z_][A-Za-z_0-9-]*\.php\z/D', $name)) {
        v5v4Fail('unexpected controller filename');
    }
    $content = file_get_contents($file);
    if (!is_string($content)) {
        v5v4Fail('controller read error');
    }
    $found = v5v4Controller($content);
    printf(
        "CONTROLLER=%s SHA256=%s CLASSES=%s METHODS=%s DEPENDENCY_HINTS=%s\n",
        $name, hash('sha256', $content),
        $found['classes'], $found['methods'], $found['deps']
    );
}
echo "\n=== SOURCE / RELEASE BOUNDARY ===\n";
echo "ROUTES_ARE_LEXICAL_NOT_RUNTIME_PROOF=YES\n";
echo "CALLBACK_HINTS_NOT_EXECUTION_PROOF=YES\n";
echo "CONTROLLER_SHA_PINNED=NO\n";
echo "SOURCE_METHOD_BODIES_PRINTED=NO\n";
echo "STRING_VALUES_EXCEPT_PUBLIC_ROUTE_TEMPLATES_PRINTED=NO\n";
echo "BITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTION=NO\n";
echo "USER_DATA_READ=NO\nPRODUCTION_CODE_WRITE=NO\nSMS_SENT=NO\n";

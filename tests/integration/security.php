<?php
/**
 * What "Manage reports" can read — checked over HTTP and in-process in the plugin-testing harness.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-reportr/tests/integration/security.php
 *
 * A report reads content out of Craft in bulk. Before this, anyone who could manage reports could
 * build one over every user (emails, password hashes via `attr:password`), every order, or a
 * section they could not open; add a `twig:` column that ran any Twig — `craft.app` included —
 * unsandboxed, despite a comment saying otherwise; put SQL in the order-by; and point the output
 * folder outside the storage folder. Each refusal is paired with what the same editor, or an
 * admin, is allowed.
 *
 * Signs in as an editor with every Reportr permission who can view exactly one section.
 * Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\models\QuerySpec;
use justinholtweb\reportr\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'rp-' . bin2hex(random_bytes(12));
$cleanup = [];

register_shutdown_function(function() use (&$cleanup, $run) {
    foreach (Report::find()->handle("security-$run-*")->status(null)->all() as $report) {
        // Runs outlive their report by design, so the ones this suite started go explicitly —
        // a queued build of a deleted run finds nothing and skips.
        foreach (Run::find()->reportId($report->id)->status(null)->all() as $started) {
            Craft::$app->getElements()->deleteElement($started, true);
        }
        Craft::$app->getElements()->deleteElement($report, true);
    }
    foreach (array_reverse($cleanup) as $element) {
        Craft::$app->getElements()->deleteElement($element, true);
    }
});

// A section the editor can view, and one they can't — each with an entry in it.
$sections = array_values(array_filter(
    Craft::$app->getEntries()->getAllSections(),
    fn($s) => Entry::find()->sectionId($s->id)->status(null)->exists(),
));

if (count($sections) < 2) {
    echo "Needs two sections with entries.\n";
    exit(1);
}

[$visible, $hidden] = $sections;

$editor = new User(['username' => "reportr-editor-$run", 'email' => "reportr-editor-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($editor, false);
Craft::$app->getUsers()->activateUser($editor);
Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, [
    'accesscp', 'accessplugin-reportr', "viewentries:{$visible->uid}",
    'reportr:viewreports', 'reportr:managereports', 'reportr:runreports', 'reportr:viewruns', 'reportr:downloadruns',
]);
$cleanup[] = $editor;

/** A signed-in HTTP client, and a way to read a fresh CSRF token for it. */
function session(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
    $login = $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);

    if ($login->getStatusCode() !== 200) {
        echo "Could not sign in as $username\n";
        exit(1);
    }

    return [$http, $csrf];
}

function client(string $username, string $password): callable
{
    return clientFor(...session($username, $password), ...[$username, $password]);
}

/**
 * Saves a query report the way the edit screen does; answers the saved report, or null.
 *
 * Given credentials, a save that bounces to the login screen signs in again and is sent once more:
 * the harness ends sessions after a few minutes, and an expired session otherwise looks exactly
 * like a save that was refused.
 */
function clientFor(Client $http, callable $csrf, ?string $username = null, ?string $password = null): callable
{
    return static function(string $handle, array $spec, array $extra = []) use (&$http, &$csrf, $username, $password): ?Report {
        $existing = Report::find()->handle($handle)->status(null)->one();
        // By reference, so a retry after signing in again uses the new session and token.
        $post = static function() use (&$http, &$csrf, $existing, $handle, $spec, $extra) {
            return $http->post('index.php?p=admin/actions/reportr/reports/save', [
                'form_params' => array_merge([
                    'reportId' => $existing?->id,
                    'title' => $extra['title'] ?? "Security $handle",
                    'handle' => $handle,
                    'type' => Report::TYPE_QUERY,
                    'format' => 'csv',
                    'querySpec' => $spec,
                    'CRAFT_CSRF_TOKEN' => $csrf(),
                ], array_diff_key($extra, ['title' => 1])),
            ]);
        };
        $response = $post();

        if ($username !== null && $response->getStatusCode() === 302 && str_contains($response->getHeaderLine('Location'), 'login')) {
            [$http, $csrf] = session($username, $password);
            $response = $post();
        }

        $saved = Report::find()->handle($handle)->status(null)->one();

        if ($saved === null && getenv('REPORTR_DEBUG')) {
            echo "    [debug] $handle → {$response->getStatusCode()} " . substr(strip_tags((string)$response->getBody()), 0, 400) . "\n";
        }

        return $saved;
    };
}

// A second editor who may manage and run reports but not download what they produce.
$viewer = new User(['username' => "reportr-viewer-$run", 'email' => "reportr-viewer-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($viewer, false);
Craft::$app->getUsers()->activateUser($viewer);
Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, [
    'accesscp', 'accessplugin-reportr', "viewentries:{$visible->uid}",
    'reportr:viewreports', 'reportr:managereports', 'reportr:runreports', 'reportr:viewruns',
]);
$cleanup[] = $viewer;

$asEditor = client($editor->username, $password);
$asViewer = client($viewer->username, $password);
// The harness invalidates its admin password every few minutes; restoring it through the element
// is the only thing that brings the web login back (users/set-password does not).
$harnessAdmin = User::find()->admin()->username('admin')->status(null)->one();
$harnessAdmin->newPassword = 'claudepassword';
Craft::$app->getElements()->saveElement($harnessAdmin);

$asAdmin = client('admin', 'claudepassword');

$spec = static fn(string $type, string $source, array $columns = ['attr:title']) => [
    'elementType' => $type,
    'source' => $source,
    'columns' => array_map(fn($key) => ['key' => $key, 'heading' => $key, 'format' => 'auto'], $columns),
];

// -------------------------------------------------------------------------------------------
echo "\nWhat an editor may report on\n";

check('the section they can view', fn() => $asEditor("security-$run-visible", $spec(Entry::class, "section:{$visible->uid}")) !== null ?: 'not saved');
check('…not a section they can’t', fn() => $asEditor("security-$run-hidden", $spec(Entry::class, "section:{$hidden->uid}")) === null ?: 'saved');
check('…nor “all entries”, which includes it', fn() => $asEditor("security-$run-all", $spec(Entry::class, '*')) === null ?: 'saved');
check('…nor users, without permission to view them', fn() => $asEditor("security-$run-users", $spec(User::class, '*', ['attr:email'])) === null ?: 'saved');
check('an admin can report on users', fn() => $asAdmin("security-$run-admin-users", $spec(User::class, '*', ['attr:email'])) !== null ?: 'not saved');

// -------------------------------------------------------------------------------------------
echo "\nTwig columns\n";

check('an editor can’t add a twig: column', function() use ($asEditor, $spec, $run, $visible) {
    $report = $asEditor("security-$run-twig", $spec(Entry::class, "section:{$visible->uid}", ['attr:title', 'twig:{{ craft.app.config.general.securityKey }}']));

    return $report === null ?: 'saved';
});

check('an admin can', fn() => $asAdmin("security-$run-admin-twig", $spec(Entry::class, "section:{$visible->uid}", ['attr:title', 'twig:{{ object.title|upper }}'])) !== null ?: 'not saved');

check('an editor can rename an admin’s report, keeping its twig: column and its source', function() use ($asEditor, $spec, $run, $visible) {
    $report = $asEditor("security-$run-admin-twig", $spec(Entry::class, "section:{$visible->uid}", ['attr:title', 'twig:{{ object.title|upper }}']), ['title' => 'Renamed by the editor']);

    return $report?->title === 'Renamed by the editor' ?: 'not saved';
});

check('…and an admin’s users report, which they couldn’t have built', function() use ($asEditor, $spec, $run) {
    $report = $asEditor("security-$run-admin-users", $spec(User::class, '*', ['attr:email']), ['title' => 'Users, renamed']);

    return $report?->title === 'Users, renamed' ?: 'not saved';
});

check('…but not change the twig: column', function() use ($asEditor, $spec, $run, $visible) {
    $asEditor("security-$run-admin-twig", $spec(Entry::class, "section:{$visible->uid}", ['attr:title', 'twig:{{ craft.app.config.general.securityKey }}']));
    $columns = array_map(fn($c) => $c->key, Report::find()->handle("security-$run-admin-twig")->one()->getQuerySpec()->columns);

    return !in_array('twig:{{ craft.app.config.general.securityKey }}', $columns, true) ?: 'changed';
});

// -------------------------------------------------------------------------------------------
echo "\nParameters and the query\n";

$visibleSpec = static fn() => QuerySpec::fromArray(['elementType' => Entry::class, 'source' => "section:{$visible->uid}", 'columns' => [['key' => 'attr:title']]]);

check('a parameter named where or orderBy never reaches the SQL', function() {
    // Over "all": a section source carries `editable`, which Craft aborts with nobody signed in.
    $all = QuerySpec::fromArray(['elementType' => Entry::class, 'source' => '*', 'columns' => [['key' => 'attr:title']]]);
    $sql = Plugin::getInstance()->queries->build($all, [
        'where' => '1=1) OR (SLEEP(5)',
        'orderBy' => '(SELECT SLEEP(5))',
        'join' => 'users',
    ])->limit(1)->createCommand()->getRawSql();

    return !str_contains($sql, 'SLEEP') ?: $sql;
});

check('a sectionId parameter can’t swap the source’s section for a hidden one', function() use ($visibleSpec, $hidden, $visible) {
    $query = Plugin::getInstance()->queries->build($visibleSpec(), ['sectionId' => $hidden->id, 'editable' => false]);
    $ids = (array)$query->sectionId;

    return !in_array($hidden->id, $ids, true) && $query->editable !== false ?: 'sectionId ' . json_encode($query->sectionId) . ', editable ' . var_export($query->editable, true);
});

check('…while one inside the source still narrows', function() use ($visibleSpec, $visible) {
    $query = Plugin::getInstance()->queries->build($visibleSpec(), ['sectionId' => $visible->id]);

    return (array)$query->sectionId === [$visible->id] ?: json_encode($query->sectionId);
});

check('a parameter can’t be named after query machinery', fn() => $asAdmin("security-$run-param-where", $spec(Entry::class, "section:{$visible->uid}"), [
    'params' => [['name' => 'where', 'label' => 'Where', 'type' => 'text']],
]) === null ?: 'saved');

check('a parameter’s label and instructions reach the run form as text, not markup', function() use ($asEditor, $spec, $run, $visible) {
    $report = $asEditor("security-$run-xss", $spec(Entry::class, "section:{$visible->uid}"), [
        'params' => [[
            'name' => 'since',
            'label' => '<svg onload=alert(1)>',
            'type' => 'text',
            'instructions' => '<img src=x onerror=alert(2)> [click](javascript:alert(3))',
        ]],
    ]);

    if ($report === null) {
        return 'not saved';
    }

    [$http] = session('admin', 'claudepassword');
    $html = (string)$http->get('index.php?p=admin/reportr/reports/' . $report->id . '/run')->getBody();

    if (!str_contains($html, 'reportr-preview')) {
        return 'run screen did not render: ' . substr(strip_tags($html), 0, 200);
    }

    foreach (['<svg onload', '<img src=x', 'href="javascript:'] as $needle) {
        if (str_contains($html, $needle)) {
            return "found $needle";
        }
    }

    return true;
});

// -------------------------------------------------------------------------------------------
echo "\nWhat a column can reach\n";

check('a column can’t walk from an asset into its filesystem’s settings', function() {
    $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;

    if ($volume === null) {
        return 'no volume to test with';
    }

    $asset = new \craft\elements\Asset(['volumeId' => $volume->id]);
    $queries = Plugin::getInstance()->queries;
    $read = fn(string $key) => $queries->resolve($asset, new \justinholtweb\reportr\models\Column(['key' => $key]));

    return $read('attr:volume.fs.secret') === null
        && $read('attr:fs.path') === null
        && $read('attr:volume.handle') === $volume->handle
        && $read('attr:fieldLayout.uid') === null
        ?: json_encode([$read('attr:volume.fs.secret'), $read('attr:fs.path'), $read('attr:volume.handle'), $read('attr:fieldLayout.uid')]);
});

check('an editor who can’t view users can’t add a column that reads one', fn() => $asEditor("security-$run-author", $spec(Entry::class, "section:{$visible->uid}", ['attr:title', 'attr:author.email'])) === null ?: 'saved');

check('…but an admin can', fn() => $asAdmin("security-$run-admin-author", $spec(Entry::class, "section:{$visible->uid}", ['attr:title', 'attr:author.email'])) !== null ?: 'not saved');

check('…and the editor can still rename the admin’s report', function() use ($asEditor, $spec, $run, $visible) {
    $report = $asEditor("security-$run-admin-author", $spec(Entry::class, "section:{$visible->uid}", ['attr:title', 'attr:author.email']), ['title' => 'Authors, renamed']);

    return $report?->title === 'Authors, renamed' ?: 'not saved';
});

// -------------------------------------------------------------------------------------------
echo "\nWhat a report runs, and where its output goes\n";

check('an editor can’t make a report that runs a template', function() use ($asEditor, $spec, $run, $visible) {
    $report = $asEditor("security-$run-template", $spec(Entry::class, "section:{$visible->uid}"), ['type' => Report::TYPE_BASIC, 'template' => '_private/anything']);

    return $report === null ?: 'saved';
});

check('an editor can’t move a report’s output to another folder or filename', function() use ($asEditor, $spec, $run, $visible) {
    $handle = "security-$run-visible";
    $asEditor($handle, $spec(Entry::class, "section:{$visible->uid}"), ['fsSubpath' => 'public', 'filenameFormat' => 'index']);
    $report = Report::find()->handle($handle)->status(null)->one();

    return $report !== null && $report->fsSubpath === null && $report->filenameFormat === null ?: json_encode([$report?->fsSubpath, $report?->filenameFormat]);
});

check('someone who can’t download a report can’t have it emailed to them', function() use ($asViewer, $spec, $run, $visible) {
    $handle = "security-$run-visible";
    $asViewer($handle, $spec(Entry::class, "section:{$visible->uid}"), ['title' => 'Viewer was here', 'delivery' => ['when' => 'always', 'recipients' => 'viewer@example.com', 'attach' => 1]]);
    $report = Report::find()->handle($handle)->status(null)->one();

    return $report !== null && $report->title !== 'Viewer was here' && $report->getDelivery()->recipients === [] ?: json_encode($report?->getDelivery()->toArray());
});

check('…while someone who can download it can', function() use ($asEditor, $spec, $run, $visible) {
    $handle = "security-$run-visible";
    $asEditor($handle, $spec(Entry::class, "section:{$visible->uid}"), ['delivery' => ['when' => 'always', 'recipients' => 'editor@example.com', 'attach' => 1]]);
    $report = Report::find()->handle($handle)->status(null)->one();

    return $report?->getDelivery()->recipients === ['editor@example.com'] ?: json_encode($report?->getDelivery()->toArray());
});

// -------------------------------------------------------------------------------------------
// The harness ends an admin session after a few minutes, and a save that bounces to the login
// screen looks exactly like a save that was refused. Sign in again for the rest.
$asAdmin = client('admin', 'claudepassword');

echo "\nWhatever the author\n";

check('an order-by that isn’t a column name is ignored, not run as SQL', function() {
    $spec = QuerySpec::fromArray(['elementType' => Entry::class, 'source' => '*', 'orderBy' => '(SELECT SLEEP(5))', 'columns' => [['key' => 'attr:title']]]);
    $sql = Plugin::getInstance()->queries->build($spec)->limit(1)->createCommand()->getRawSql();

    return !str_contains($sql, 'SLEEP') ?: $sql;
});

check('…while a column name still orders', function() {
    $spec = QuerySpec::fromArray(['elementType' => Entry::class, 'source' => '*', 'orderBy' => 'title', 'direction' => 'desc', 'columns' => [['key' => 'attr:title']]]);
    $sql = Plugin::getInstance()->queries->build($spec)->limit(1)->createCommand()->getRawSql();

    return (bool)preg_match('/ORDER BY .*title.* DESC/i', $sql) ?: $sql;
});

check('a column can’t read a password hash, however it gets there', function() {
    // Craft doesn't select the password column when it loads users, so a path through
    // `entry.author` comes back blank anyway. The deny list is for a user object that does
    // carry the hash — one a module loaded itself, or the current identity.
    $user = new User(['email' => 'hash@example.com', 'password' => '$2y$13$abcdefghijklmnopqrstuv']);
    $queries = Plugin::getInstance()->queries;
    $hash = $queries->resolve($user, new \justinholtweb\reportr\models\Column(['key' => 'attr:password']));
    $email = $queries->resolve($user, new \justinholtweb\reportr\models\Column(['key' => 'attr:email']));

    return $hash === null && $email === 'hash@example.com' ?: 'password column: ' . var_export($hash, true);
});

check('the output folder can’t step outside the filesystem', function() use ($asAdmin, $spec, $run, $visible) {
    // The control proves the session is alive, so the refusal below is a refusal.
    if ($asAdmin("security-$run-folder", $spec(Entry::class, "section:{$visible->uid}"), ['fsSubpath' => 'exports/monthly']) === null) {
        return 'control save failed: the admin session is not working';
    }

    $report = $asAdmin("security-$run-escape", $spec(Entry::class, "section:{$visible->uid}"), ['fsSubpath' => '../../web']);
    $stripped = Plugin::getInstance()->storage->subpathFor(new Report(['fsSubpath' => '../../web/./reports']));

    return $report === null && $stripped === 'web/reports' ?: 'saved: ' . var_export($report?->fsSubpath, true) . ", stripped to $stripped";
});

echo "\nStarting a run\n";

[$adminHttp, $adminCsrf] = session('admin', 'claudepassword');
$getRunReport = clientFor($adminHttp, $adminCsrf)("security-$run-get-run", $spec(Entry::class, "section:{$visible->uid}"));

check('following a run link shows the form and starts nothing', function() use ($getRunReport, $adminHttp) {
    $report = $getRunReport;

    if ($report === null) {
        return 'fixture not saved';
    }

    $before = (int)Run::find()->reportId($report->id)->status(null)->count();
    $response = $adminHttp->get("index.php?p=admin/reportr/reports/{$report->id}/run", ['allow_redirects' => false]);
    $after = (int)Run::find()->reportId($report->id)->status(null)->count();

    return $response->getStatusCode() === 200 && $after === $before
        ? true
        : "status {$response->getStatusCode()}, runs $before → $after";
});

check('…while posting the form does', function() use ($getRunReport, $adminHttp, $adminCsrf) {
    $report = $getRunReport;

    if ($report === null) {
        return 'fixture not saved';
    }

    $before = (int)Run::find()->reportId($report->id)->status(null)->count();
    $adminHttp->post('index.php?p=admin/actions/reportr/reports/run', [
        'form_params' => ['reportId' => $report->id, 'CRAFT_CSRF_TOKEN' => $adminCsrf()],
        'allow_redirects' => false,
    ]);
    $after = (int)Run::find()->reportId($report->id)->status(null)->count();

    return $after === $before + 1 ? true : "runs $before → $after";
});

check('every control-panel screen renders for an admin, not just compiles', function() use ($getRunReport, $adminHttp) {
    $runId = Run::find()->reportId($getRunReport?->id)->status(null)->ids()[0] ?? null;
    $paths = ['reportr/reports', "reportr/reports/{$getRunReport?->id}", "reportr/reports/{$getRunReport?->id}/run", 'reportr/runs', 'reportr/settings', 'reportr/import'];

    if ($runId !== null) {
        $paths[] = "reportr/runs/$runId";
    }

    $broken = [];

    foreach ($paths as $path) {
        $status = $adminHttp->get("index.php?p=admin/$path", ['allow_redirects' => false])->getStatusCode();

        if ($status !== 200) {
            $broken[] = "$path → $status";
        }
    }

    return $broken === [] ? true : implode(', ', $broken);
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

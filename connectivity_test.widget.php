<?php
// Widget for pfSense dashboard

require_once("functions.inc");
require_once("guiconfig.inc");
include_once("includes/functions.inc.php");

define('REPORT_PATH', '/usr/local/pkg/connectivity-test-report.json');
define('DISPLAY_COUNT', 5);

$results = [];
if (file_exists(REPORT_PATH)) {
    $lines = array_filter(file(REPORT_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $lines = array_slice($lines, -DISPLAY_COUNT);
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (!$row) continue;
        $results[] = $row;
    }
}

?>

<div style="padding:8px;">
    <table class="table table-striped table-condensed table-hover">
        <thead>
            <tr>
                <th>Time</th>
                <th>Latency&nbsp;(ms)</th>
                <th>Download</th>
                <th>Upload</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (array_reverse($results) as $row): ?>
            <tr>
                <td><?=htmlspecialchars($row["timestamp"])?></td>
                <td><?=htmlspecialchars($row["latency_ms"])?></td>
                <td><?=htmlspecialchars($row["download"])?></td>
                <td><?=htmlspecialchars($row["upload"])?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post">
        <button name="connectivity_run_now" class="btn btn-primary btn-sm">Run Test Now</button>
    </form>
</div>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['connectivity_run_now'])) {
    exec('/usr/local/bin/connectivity-test.sh > /dev/null 2>&1 &');
    header("Refresh:0");
    exit();
}
?>

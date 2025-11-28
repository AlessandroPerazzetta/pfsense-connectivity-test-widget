<?php
// Widget for pfSense dashboard

require_once("functions.inc");
require_once("guiconfig.inc");
include_once("includes/functions.inc.php");

// Get user-selected counts or use defaults
$display_count = isset($_GET['display_count']) ? intval($_GET['display_count']) : 5;
$chart_count = isset($_GET['chart_count']) ? intval($_GET['chart_count']) : 20;

// Clamp values to reasonable ranges
$display_count = max(1, min($display_count, 50));
$chart_count = max(5, min($chart_count, 100));

define('REPORT_PATH', '/usr/local/pkg/connectivity-test-report.json');

$results = [];
$chart_results = [];
if (file_exists(REPORT_PATH)) {
    $lines = array_filter(file(REPORT_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $chart_lines = array_slice($lines, -$chart_count);
    foreach ($chart_lines as $line) {
        $row = json_decode($line, true);
        if (!$row) continue;
        $chart_results[] = $row;
    }
    $results = array_slice($chart_results, -$display_count);
}

if (isset($_GET['export']) && $_GET['export'] === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="connectivity-test-report.json"');
    if (file_exists(REPORT_PATH)) {
        readfile(REPORT_PATH);
    }
    exit;
}
?>

<div style="padding:8px;">
    <form method="get" style="margin-bottom:12px; display:flex; gap:12px; align-items:center;">
        <label>Show last
            <select name="display_count">
                <?php foreach ([1, 3, 5, 10, 20, 30, 50] as $opt): ?>
                    <option value="<?=$opt?>" <?=($display_count==$opt?'selected':'')?>><?=$opt?></option>
                <?php endforeach; ?>
            </select>
            results in table
        </label>
        <label>Chart history
            <select name="chart_count">
                <?php foreach ([5, 10, 20, 40, 60, 80, 100] as $opt): ?>
                    <option value="<?=$opt?>" <?=($chart_count==$opt?'selected':'')?>><?=$opt?></option>
                <?php endforeach; ?>
            </select>
            points
        </label>
        <button type="submit" class="btn btn-primary btn-xs">Apply</button>
    </form>
    <a href="?export=json" class="btn btn-default btn-xs" style="margin-bottom:8px;">Export JSON</a>
    <table class="table table-striped table-condensed table-hover">
        <thead>
            <tr>
                <th>Time</th>
                <th>Latency&nbsp;(ms)</th>
                <th>Download</th>
                <th>Upload</th>
                <th>Packet Loss</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (array_reverse($results) as $row): ?>
            <tr>
                <td><?=htmlspecialchars($row["timestamp"])?></td>
                <td><?=htmlspecialchars($row["latency_ms"])?></td>
                <td><?=htmlspecialchars($row["download"])?></td>
                <td><?=htmlspecialchars($row["upload"])?></td>
                <td><?=htmlspecialchars($row["packet_loss"])?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" style="display:inline;">
        <button name="connectivity_run_now" class="btn btn-primary btn-sm">Run Test Now</button>
        <button name="clear_results" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to clear all results?');">Clear Results</button>
    </form>
    <hr>
    <div>
        <canvas id="latencyPacketLossChart" height="100"></canvas>
    </div>
    <div style="margin-top:24px;">
        <canvas id="speedChart" height="100"></canvas>
    </div>
</div>

<!-- Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const chartData = <?=json_encode($chart_results)?>;
const labels = chartData.map(r => r.timestamp);
const latency = chartData.map(r => Number(r.latency_ms));
// Remove ' Mbps' from download/upload and convert to float
const download = chartData.map(r => parseFloat((r.download || "0").replace(/[^\d.]/g, '')));
const upload = chartData.map(r => parseFloat((r.upload || "0").replace(/[^\d.]/g, '')));
// Remove '%' from packet_loss and convert to float
const packetLoss = chartData.map(r => parseFloat((r.packet_loss || "0").replace(/[^\d.]/g, '')));

// First chart: Latency and Packet Loss (lines)
const ctx1 = document.getElementById('latencyPacketLossChart').getContext('2d');
new Chart(ctx1, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Latency (ms)',
                data: latency,
                borderColor: 'rgba(54, 162, 235, 1)',
                backgroundColor: 'rgba(54, 162, 235, 0.1)',
                yAxisID: 'y',
                tension: 0.2,
                fill: false,
                pointRadius: 2
            },
            {
                label: 'Packet Loss (%)',
                data: packetLoss,
                borderColor: 'rgba(255, 99, 132, 1)',
                backgroundColor: 'rgba(255, 99, 132, 0.1)',
                yAxisID: 'y2',
                tension: 0.2,
                fill: false,
                pointRadius: 2
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top' } },
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: { display: true, text: 'Latency (ms)' }
            },
            y2: {
                type: 'linear',
                display: true,
                position: 'right',
                grid: { drawOnChartArea: false },
                title: { display: true, text: 'Packet Loss (%)' }
            }
        }
    }
});

// Second chart: Upload/Download (stacked bars)
const ctx2 = document.getElementById('speedChart').getContext('2d');
new Chart(ctx2, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Download (Mbps)',
                data: download,
                backgroundColor: 'rgba(75, 192, 192, 0.7)',
                borderColor: 'rgba(75, 192, 192, 1)',
                stack: 'speed'
            },
            {
                label: 'Upload (Mbps)',
                data: upload,
                backgroundColor: 'rgba(255, 206, 86, 0.7)',
                borderColor: 'rgba(255, 206, 86, 1)',
                stack: 'speed'
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: {
            x: { stacked: true },
            y: {
                stacked: true,
                title: { display: true, text: 'Speed (Mbps)' }
            }
        }
    }
});
</script>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['connectivity_run_now'])) {
    exec('/usr/local/bin/connectivity-test.sh > /dev/null 2>&1 &');
    header("Refresh:0");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_results'])) {
    if (file_exists(REPORT_PATH)) {
        file_put_contents(REPORT_PATH, '');
    }
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit();
}
?>

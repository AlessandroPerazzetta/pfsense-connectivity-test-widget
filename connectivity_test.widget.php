<?php
// Widget for pfSense dashboard

require_once("functions.inc");
require_once("guiconfig.inc");
include_once("includes/functions.inc.php");

define('REPORT_PATH', '/usr/local/pkg/connectivity-test-report.json');
define('DISPLAY_COUNT', 5);
define('CHART_COUNT', 20);

$results = [];
$chart_results = [];
if (file_exists(REPORT_PATH)) {
    $lines = array_filter(file(REPORT_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $chart_lines = array_slice($lines, -CHART_COUNT);
    foreach ($chart_lines as $line) {
        $row = json_decode($line, true);
        if (!$row) continue;
        $chart_results[] = $row;
    }
    $results = array_slice($chart_results, -DISPLAY_COUNT);
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
    <form method="post">
        <button name="connectivity_run_now" class="btn btn-primary btn-sm">Run Test Now</button>
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
?>

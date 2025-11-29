<?php
// Widget for pfSense dashboard

require_once("guiconfig.inc");

define('REPORT_PATH', '/usr/local/pkg/connectivity-test-report.json');
define('CRON_FILE', '/etc/cron.d/connectivity-test');
define('CRON_LINE', '/usr/local/bin/connectivity-test.sh');

// Handle export request
if (isset($_GET['export']) && $_GET['export'] === 'json') {
    if (file_exists(REPORT_PATH) && is_readable(REPORT_PATH)) {
        // Clean output buffer to prevent accidental output before headers
        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="connectivity-test-report.json"');
        readfile(REPORT_PATH);
    } else {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['error' => 'Report file not found']);
    }
    exit;
}
// Handle "Run Test Now" button
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['connectivity_run_now'])) {
    // // Execute the connectivity test script immediately
    // exec("/usr/local/bin/connectivity-test.sh > /dev/null 2>&1 &");
    // // Redirect to avoid resubmission
    // header("Location: /");

    exec('/usr/local/bin/connectivity-test.sh > /dev/null 2>&1 &');
    header("Refresh:0");

    exit;
}
// Handle "Clear Results" button
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_results'])) {
    // Clear the report file
    if (file_exists(REPORT_PATH)) {
        unlink(REPORT_PATH);
    }
    // Redirect to avoid resubmission
    // header("Location: /");
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}


// --- Helper functions ---

function parse_cron_line($line, $cron_line) {
    // Returns ['interval'=>int, 'unit'=>string] or null
    $parts = preg_split('/\s+/', $line);
    if (count($parts) < 6 || strpos($line, $cron_line) === false) return null;
    list($min, $hour, $dom, $month, $dow) = array_slice($parts, 0, 5);

    if (preg_match('/^\*\/(\d+)$/', $min, $m)) {
        return ['interval' => (int)$m[1], 'unit' => 'min'];
    } elseif ($min === '0' && preg_match('/^\*\/(\d+)$/', $hour, $m)) {
        return ['interval' => (int)$m[1], 'unit' => 'hour'];
    } elseif ($min === '0' && $hour === '0' && preg_match('/^\*\/(\d+)$/', $dom, $m)) {
        return ['interval' => (int)$m[1], 'unit' => 'day'];
    } elseif ($min === '0' && $hour === '0' && $dom === '1' && preg_match('/^\*\/(\d+)$/', $month, $m)) {
        return ['interval' => (int)$m[1], 'unit' => 'month'];
    } elseif ($min === '0' && $hour === '0' && $dom === '*' && $month === '*' && $dow !== '*') {
        return ['interval' => (int)$dow, 'unit' => 'week'];
    }
    return null;
}

function build_cron_expr_and_effective($interval, $unit) {
    $interval = max(1, (int)$interval);
    $effective = ['interval' => $interval, 'unit' => $unit, 'warning' => null];

    switch ($unit) {
        case "min":
            if ($interval <= 59) {
                $expr = "*/$interval * * * *";
            } elseif ($interval % 60 === 0) {
                $hours = $interval / 60;
                $expr = "0 */$hours * * *";
                $effective = ['interval' => $hours, 'unit' => 'hour', 'warning' => null];
            } else {
                // Not representable, round down to nearest hour
                $hours = floor($interval / 60);
                $minutes = $interval % 60;
                if ($hours > 0) {
                    $expr = "0 */$hours * * *";
                    $effective = ['interval' => $hours, 'unit' => 'hour', 'warning' => "Requested interval ($interval min) not supported. Using $hours hour(s)."];
                } else {
                    $expr = "*/$minutes * * * *";
                    $effective = ['interval' => $minutes, 'unit' => 'min', 'warning' => "Requested interval ($interval min) not supported. Using $minutes minutes."];
                }
            }
            break;
        case "hour":
            $expr = "0 */$interval * * *";
            break;
        case "day":
            $expr = "0 0 */$interval * *";
            break;
        case "month":
            $expr = "0 0 1 */$interval *";
            break;
        case "week":
            $expr = "0 0 * * 0";
            break;
        default:
            $expr = "*/60 * * * *";
            $effective = ['interval' => 60, 'unit' => 'min', 'warning' => "Unknown unit, defaulting to 60 minutes."];
    }
    return [$expr, $effective];
}

// --- Main logic ---

// Validate widgetkey once at the top
if ($_POST['widgetkey'] || $_GET['widgetkey']) {
    $rwidgetkey = $_POST['widgetkey'] ?? $_GET['widgetkey'] ?? null;
    if (is_valid_widgetkey($rwidgetkey, $user_settings, __FILE__)) {
        $widgetkey = $rwidgetkey;
    } else {
        print gettext("Invalid Widget Key");
        exit;
    }
}

// Handle POST (save settings)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enable_test = !empty($_POST['enable_test']);
    $interval = isset($_POST['custom_interval']) ? max(1, (int)$_POST['custom_interval']) : 60;
    $unit = $_POST['custom_unit'] ?? 'min';

    // Save to settings
    $user_settings['widgets'][$widgetkey]['enable_test'] = $enable_test;
    $user_settings['widgets'][$widgetkey]['custom_interval'] = $interval;
    $user_settings['widgets'][$widgetkey]['custom_unit'] = $unit;

    // Save cron job
    if ($enable_test) {
        list($cron_expr, $effective) = build_cron_expr_and_effective($interval, $unit);
        $user_settings['widgets'][$widgetkey]['custom_interval'] = $effective['interval'];
        $user_settings['widgets'][$widgetkey]['custom_unit'] = $effective['unit'];
        $user_settings['widgets'][$widgetkey]['cron_warning'] = $effective['warning'];
        $new_cron = "";
        if (file_exists(CRON_FILE)) {
            foreach (file(CRON_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos($line, CRON_LINE) === false) $new_cron .= $line . "\n";
            }
        }
        $new_cron .= "$cron_expr root " . CRON_LINE . "\n";
        file_put_contents(CRON_FILE, $new_cron);
    } else {
        if (file_exists(CRON_FILE)) unlink(CRON_FILE);
    }

    save_widget_settings($_SESSION['Username'], $user_settings["widgets"], gettext("Saved connectivity test Widget via Dashboard."));
    header("Location: /");
    exit;
}

// Handle GET (load settings from cron or defaults)
$enable_test = false;
$interval = 60;
$unit = 'min';
if (file_exists(CRON_FILE)) {
    foreach (file(CRON_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parsed = parse_cron_line($line, CRON_LINE);
        if ($parsed) {
            $enable_test = true;
            $interval = $parsed['interval'];
            $unit = $parsed['unit'];
            break;
        }
    }
}
$user_settings['widgets'][$widgetkey]['enable_test'] = $enable_test;
$user_settings['widgets'][$widgetkey]['custom_interval'] = $interval;
$user_settings['widgets'][$widgetkey]['custom_unit'] = $unit;

// Assign local variables for template use (optional, for brevity in HTML)
$enable_test_s = $enable_test;
$custom_interval_s = $interval;
$custom_unit_s = $unit;


// Now use $user_settings['widgets'][$widgetkey]['enable_test'], ['custom_interval'], ['custom_unit'] everywhere in your form and logic

/*
 * Validate the "widgetkey" value.
 * When this widget is present on the Dashboard, $widgetkey is defined before
 * the Dashboard includes the widget. During other types of requests, such as
 * saving settings or AJAX, the value may be set via $_POST or similar.
 */
if ($_POST['widgetkey'] || $_GET['widgetkey']) {
	$rwidgetkey = isset($_POST['widgetkey']) ? $_POST['widgetkey'] : (isset($_GET['widgetkey']) ? $_GET['widgetkey'] : null);
	if (is_valid_widgetkey($rwidgetkey, $user_settings, __FILE__)) {
		$widgetkey = $rwidgetkey;
	} else {
		print gettext("Invalid Widget Key");
		exit;
	}
}

if ($_POST['widgetkey']) {
	set_customwidgettitle($user_settings);
    echo "PIPPO";

	if (is_numeric($_POST['enable_test'])) {
		$user_settings['widgets'][$_POST['widgetkey']]['enable_test'] = $_POST['enable_test'];
	} else {
		$user_settings['widgets'][$_POST['widgetkey']]['enable_test'] = false;
	}
	if (is_numeric($_POST['custom_interval'])) {
		$user_settings['widgets'][$_POST['widgetkey']]['custom_interval'] = $_POST['custom_interval'];
	} else {
		$user_settings['widgets'][$_POST['widgetkey']]['custom_interval'] = 60;
	}
    if (is_numeric($_POST['custom_unit'])) {
        $user_settings['widgets'][$_POST['widgetkey']]['custom_unit'] = $_POST['custom_unit'];
    } else {
        $user_settings['widgets'][$_POST['widgetkey']]['custom_unit'] = 'min';
    }

	save_widget_settings($_SESSION['Username'], $user_settings["widgets"], gettext("Saved connectivity test Widget via Dashboard."));
	header("Location: /");
}

// Use saved feed and max items
if ($user_settings['widgets'][$widgetkey]['enable_test']) {    
	$enable_test_s = (bool) $user_settings['widgets'][$widgetkey]['enable_test'];
    // echo "ENABLE TEST SETTING FOUND: " . htmlspecialchars($enable_test_s) . "<br>";
}

if ($user_settings['widgets'][$widgetkey]['custom_interval']) {
	$custom_interval_s =  (int) $user_settings['widgets'][$widgetkey]['custom_interval'];
    // echo "CUSTOM INTERVAL SETTINGS FOUND: " . htmlspecialchars($custom_interval_s) . "<br>";
}

if ($user_settings['widgets'][$widgetkey]['custom_unit']) {
    $custom_unit_s =  $user_settings['widgets'][$widgetkey]['custom_unit'];
    // echo "CUSTOM UNIT SETTINGS FOUND: " . htmlspecialchars($custom_unit_s) . "<br>";
}



// Update cron job based on current settings
if($enable_test_s == true) {
    $interval = isset($_POST['custom_interval']) ? intval($_POST['custom_interval']) : 0;
    $unit = isset($_POST['custom_unit']) ? $_POST['custom_unit'] : 'min';
    $new_cron = "";

    // Remove any existing line for this script
    if (file_exists(CRON_FILE)) {
        $lines = file(CRON_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, CRON_LINE) === false) {
                $new_cron .= $line . "\n";
            }
        }
    }

    // CRON SYNTAX REFERENCE:
    // * * * * * command to be executed
    // - - - - - -
    // | | | | | | 
    // | | | | +----- day of week (0 - 7) (Sunday=0 or 7)
    // | | | +------- month (1 - 12)
    // | | +--------- day of month (1 - 31)
    // | +----------- hour (0 - 23)
    // +------------- minute (0 - 59)
    
    // Validate interval and set cron expression taking care of each field unit to save into cron file
    // For example:
    // If user selects 5 minutes, cron expression is "*/5 * * * *"
    // If user selects 2 hours, cron expression is "0 */2 * * *"
    // If user selects 1 day, cron expression is "0 0 */1 * *"
    // If user selects 1 week, cron expression is "0 0 * * 0" (every Sunday)
    // If user selects 1 month, cron expression is "0 0 1 */1 *" (first day of every month)
    // If user selects 180 minutes (3 hours), cron expression is "* */3 * * *"
    // If user selects 48 hours (2 days), cron expression is "0 0 */2 * *"
    // If user selects 14 days (2 weeks), cron expression is "0 0 * * 0" (every Sunday)
    if ($interval > 0) {
        switch ($unit) {
            case "min":
                if ($interval <= 59) {
                    // Every N minutes (N <= 59)
                    $cron_expr = "*/$interval * * * *";
                } else {
                    // For intervals > 59 minutes, convert to hours if possible
                    $hours = floor($interval / 60);
                    $minutes = $interval % 60;
                    if ($minutes === 0) {
                        // e.g., 120 min = 2 hours
                        $cron_expr = "0 */$hours * * *";
                    } else {
                        // Not directly supported by cron, fallback to every hour
                        $cron_expr = "0 * * * *";
                    }
                }
                break;
            case "hour":
                // Every N hours
                if ($interval <= 23) {
                    // Every N hours (N <= 23)
                    $cron_expr = "0 */$interval * * *";
                } else {
                    // For intervals > 23 hours, convert to days if possible
                    $days = floor($interval / 24);
                    $hours = $interval % 24;
                    if ($hours === 0) {
                        // e.g., 48 hours = 2 days
                        $cron_expr = "0 0 */$days * *";
                    } else {
                        // Not directly supported by cron, fallback to every day
                        $cron_expr = "0 0 * * *";
                    }
                }
                break;
            case "day":
                // Every N days
                if ($interval <= 31) {
                    // Every N days (N <= 31)
                    $cron_expr = "0 0 */$interval * *";
                } else {
                    // For intervals > 31 days, convert to months if possible
                    $months = floor($interval / 30);
                    $days = $interval % 30;
                    if ($days === 0) {
                        // e.g., 60 days = 2 months
                        $cron_expr = "0 0 1 */$months *";
                    } else {
                        // Not directly supported by cron, fallback to every month
                        $cron_expr = "0 0 1 * *";
                    }
                }
                break;
            case "month":
                // Every N months
                if ($interval <= 12) {
                    // Every N months (N <= 12)
                    $cron_expr = "0 0 1 */$interval *";
                } else {
                    // For intervals > 12 months, fallback to every year (12 months)
                    $cron_expr = "0 0 1 1 *";
                }
                break;
            case "week":
                // Set to run at specific day of week (0 = Sunday)
                if ($interval >= 0 && $interval <= 6) {
                    // Every N weeks (N <= 6)
                    $cron_expr = "0 0 * * $interval";
                } else {
                    // For intervals > 6 weeks, fallback to every month
                    $cron_expr = "0 0 1 * *";
                }
                break;
            default:
                // fallback to every 60 minutes
                $cron_expr = "*/60 * * * *";
        }
        $new_cron .= "$cron_expr root " . CRON_LINE . "\n";
        file_put_contents(CRON_FILE, $new_cron);
    }
} else {
    // Remove cron job if disabled
    if (file_exists(CRON_FILE)) {
        unlink(CRON_FILE);
    }
}


// Get user-selected counts or use defaults
$display_count = isset($_GET['display_count']) ? intval($_GET['display_count']) : 5;
$chart_count = isset($_GET['chart_count']) ? intval($_GET['chart_count']) : 20;


// Clamp values to reasonable ranges
$display_count = max(1, min($display_count, 50));
$chart_count = max(5, min($chart_count, 100));



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
?>

<!-- Widget HTML -->
<div style="padding:8px;">
    <!-- Control buttons -->
    <div style="padding:3px; padding-bottom:16px;" class="form-group">
        <form method="post" style="display:inline;">
            <button name="connectivity_run_now" class="btn btn-primary btn-sm">Run Test Now</button>
            <?php
            // If test results files not exist hide clear results button
            if (file_exists(REPORT_PATH) && is_readable(REPORT_PATH) && !empty($results)): ?>
                <button name="clear_results" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to clear all results?');">Clear Results</button>
            <?php endif; ?>
        </form>

        <form method="get" style="display:inline; margin-left:8px;">
            <?php 
            // If test results files exist show export button, esle hide it and show a message to Run Test Now manually
            if (file_exists(REPORT_PATH) && is_readable(REPORT_PATH) && !empty($results)): ?>
                <button type="submit" name="export" value="json" class="btn btn-info btn-sm">Export JSON</button>
            <?php endif; ?>
        </form>
    </div>
    
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
    
    <?php
    // If test results files exist show export button, esle hide it and show a message to Run Test Now manually
    if (!file_exists(REPORT_PATH) || !is_readable(REPORT_PATH) || empty($results)): ?>
        <div class="alert alert-info" role="alert" style="padding:6px;">
            No test results found. Please click "Run Test Now" to perform the first connectivity test.
        </div>
    <?php endif; ?>

    
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

<!-- close the body we're wrapped in and add a configuration-panel -->
</div><div id="<?=$widget_panel_footer_id?>" class="panel-footer collapse">

<form action="/widgets/widgets/connectivity_test.widget.php" method="post" class="form-horizontal">

	<?=gen_customwidgettitle_div($widgetconfig['title']); ?>
    <div class="panel panel-default col-sm-10">
		<div class="panel-body">
			<input type="hidden" name="widgetkey" value="<?=htmlspecialchars($widgetkey); ?>">
			<div class="table responsive">
                <table class="table table-striped table-hover table-condensed">
                    <thead>
                        <tr>
                            <th><?=gettext("Option")?></th>
                            <th><?=gettext("Value")?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?=gettext("Enable Connectivity Test")?></td>
                            <td>
                                <input type="checkbox" name="enable_test" value="1" <?=($enable_test_s ? "checked" : "")?> />
                            </td>
                        </tr>
                        <tr>
                            <td><?=gettext("Test Frequency")?></td>
                            <td>
                                <div class="form-inline">
                                    <input type="number" class="form-control" name="custom_interval" value="<?=htmlspecialchars($custom_interval_s ?: 60)?>" min="1" style="width:80px;" />
                                    <select name="custom_unit" class="form-control">
                                        <option value="min"  <?=($custom_unit_s === 'min'  ? 'selected' : '')?>><?=gettext("Minutes")?></option>
                                        <option value="hour" <?=($custom_unit_s === 'hour' ? 'selected' : '')?>><?=gettext("Hours")?></option>
                                        <option value="day"  <?=($custom_unit_s === 'day'  ? 'selected' : '')?>><?=gettext("Days")?></option>
                                        <option value="month"<?=($custom_unit_s === 'month'? 'selected' : '')?>><?=gettext("Months")?></option>
                                        <option value="week" <?=($custom_unit_s === 'week' ? 'selected' : '')?>><?=gettext("Weeks")?></option>
                                    </select>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
			</div>
		</div>
	</div>

	<div class="form-group">
		<div class="col-sm-offset-3 col-sm-6">
			<button type="submit" class="btn btn-primary"><i class="fa-solid fa-save icon-embed-btn"></i><?=gettext('Save')?></button>
		</div>
	</div>
</form>

<?php if (!empty($user_settings['widgets'][$widgetkey]['cron_warning'])): ?>
    <div class="alert alert-warning">
        <?=htmlspecialchars($user_settings['widgets'][$widgetkey]['cron_warning'])?>
    </div>
<?php endif; ?>
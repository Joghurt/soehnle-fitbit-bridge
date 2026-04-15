<?php

date_default_timezone_set('Europe/Berlin');
setlocale(LC_TIME, "de_DE.UTF-8", "de_DE@euro", "de_DE", "deu_deu", "de", "ge");

// Parse weight data from log files
function get_weight_data() {
    $log_dir = __DIR__ . '/log';
    $data = [];

    if (!is_dir($log_dir)) {
        return [];
    }

    $log_files = glob($log_dir . '/*.log');

    foreach ($log_files as $log_file) {
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));

            if (count($parts) >= 3) {
                $date_str = $parts[0];
                $time_str = $parts[1];
                $weight_str = $parts[2];

                // Parse dd.mm.yy to yyyy-mm-dd
                $date_parts = explode('.', $date_str);
                if (count($date_parts) === 3) {
                    $day = $date_parts[0];
                    $month = $date_parts[1];
                    $year = '20' . $date_parts[2];
                    $date_key = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $weight = floatval($weight_str);

                    if ($weight > 0) {
                        // Keep only the latest entry per day
                        if (!isset($data[$date_key]) || $data[$date_key]['time'] < $time_str) {
                            $data[$date_key] = [
                                'weight' => $weight,
                                'time' => $time_str,
                            ];
                        }
                    }
                }
            }
        }
    }

    // Sort by date
    ksort($data);
    return $data;
}

$weight_data = get_weight_data();
$dates = array_keys($weight_data);
$weights = array_column($weight_data, 'weight');

// Calculate statistics
$latest_weight = !empty($weights) ? end($weights) : 0;
$min_weight = !empty($weights) ? min($weights) : 0;
$max_weight = !empty($weights) ? max($weights) : 0;
$avg_weight = !empty($weights) ? round(array_sum($weights) / count($weights), 2) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weight Measurement Points</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .header h1 {
            color: #333;
            margin-bottom: 20px;
            font-size: 28px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }

        .stat-box label {
            display: block;
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-box .value {
            font-size: 32px;
            font-weight: bold;
        }

        .stat-box .unit {
            font-size: 14px;
            opacity: 0.8;
            margin-left: 5px;
        }

        .chart-container {
            position: relative;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            height: 400px;
        }

        .info {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            color: #666;
            font-size: 14px;
        }

        .info strong {
            color: #333;
        }

        @media (max-width: 768px) {
            .header {
                padding: 20px;
            }

            .header h1 {
                font-size: 20px;
            }

            .stat-box .value {
                font-size: 24px;
            }

            .chart-container {
                height: 300px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Weight Tracking</h1>
            <div class="stats">
                <div class="stat-box">
                    <label>Current Weight</label>
                    <span class="value"><?php echo $latest_weight > 0 ? number_format($latest_weight, 1, ',', '.') : '—'; ?><span class="unit">kg</span></span>
                </div>
                <div class="stat-box">
                    <label>Minimum</label>
                    <span class="value"><?php echo $min_weight > 0 ? number_format($min_weight, 1, ',', '.') : '—'; ?><span class="unit">kg</span></span>
                </div>
                <div class="stat-box">
                    <label>Maximum</label>
                    <span class="value"><?php echo $max_weight > 0 ? number_format($max_weight, 1, ',', '.') : '—'; ?><span class="unit">kg</span></span>
                </div>
                <div class="stat-box">
                    <label>Average</label>
                    <span class="value"><?php echo $avg_weight > 0 ? number_format($avg_weight, 1, ',', '.') : '—'; ?><span class="unit">kg</span></span>
                </div>
            </div>
        </div>

        <?php if (!empty($dates)): ?>
        <div class="chart-container">
            <canvas id="weightChart"></canvas>
        </div>

        <div class="info">
            <strong>Data:</strong> <?php echo count($dates); ?> measurement points
            | <strong>Time period:</strong> <?php echo date('d.m.Y', strtotime($dates[0])); ?> – <?php echo date('d.m.Y', strtotime(end($dates))); ?>
        </div>

        <script>
            const ctx = document.getElementById('weightChart').getContext('2d');
            const chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(function($d) {
                        return date('d.m.Y', strtotime($d));
                    }, $dates)); ?>,
                    datasets: [{
                        label: 'Weight (kg)',
                        data: <?php echo json_encode($weights); ?>,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        pointRadius: 4,
                        pointBackgroundColor: '#667eea',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointHoverRadius: 6,
                        tension: 0.4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleFont: { size: 14 },
                            bodyFont: { size: 13 },
                            padding: 12,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y.toFixed(1) + ' kg';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(1) + ' kg';
                                }
                            },
                            title: {
                                display: true,
                                text: 'Weight (kg)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Date'
                            }
                        }
                    }
                }
            });
        </script>
        <?php else: ?>
        <div class="info">
            <strong>No data available</strong> – No weight measurements have been recorded yet.
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
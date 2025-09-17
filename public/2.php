<?php
session_start();

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // CPU kullanımını /proc/stat üzerinden hesapla
    function getCpuUsage() {
        if (!is_readable('/proc/stat')) {
            return null;
        }
        $stat1 = file_get_contents('/proc/stat');
        usleep(500000);
        $stat2 = file_get_contents('/proc/stat');

        preg_match('/cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat1, $m1);
        preg_match('/cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat2, $m2);

        if (!$m1 || !$m2) return null;

        $idle1 = $m1[4] + $m1[5];
        $idle2 = $m2[4] + $m2[5];
        $total1 = array_sum(array_slice($m1, 1, 7));
        $total2 = array_sum(array_slice($m2, 1, 7));

        if ($total2 == $total1) return null;

        $cpuUsage = (1 - ($idle2 - $idle1) / ($total2 - $total1)) * 100;
        return round($cpuUsage, 2);
    }

    // RAM kullanımını /proc/meminfo üzerinden hesapla
    function getRamUsage() {
        if (!is_readable('/proc/meminfo')) return null;
        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+) kB/', $meminfo, $totalMatch);
        preg_match('/MemAvailable:\s+(\d+) kB/', $meminfo, $availMatch);
        if (!$totalMatch) return null;

        $total = (int)$totalMatch[1];
        if ($availMatch) {
            $available = (int)$availMatch[1];
        } else {
            preg_match('/MemFree:\s+(\d+) kB/', $meminfo, $freeMatch);
            preg_match('/Buffers:\s+(\d+) kB/', $meminfo, $buffersMatch);
            preg_match('/Cached:\s+(\d+) kB/', $meminfo, $cachedMatch);
            $free = $freeMatch[1] ?? 0;
            $buffers = $buffersMatch[1] ?? 0;
            $cached = $cachedMatch[1] ?? 0;
            $available = $free + $buffers + $cached;
        }
        
        $used = $total - $available;
        $percent = ($used / $total) * 100;
        return [
            'total' => round($total / 1024, 2),
            'used' => round($used / 1024, 2),
            'percent' => round($percent, 2)
        ];
    }

    // Disk kullanımını hesapla
    function getDiskUsage($path = '/') {
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;
        $percent = ($used / $total) * 100;

        return [
            'total' => round($total / 1024 / 1024 / 1024, 2),
            'used' => round($used / 1024 / 1024 / 1024, 2),
            'percent' => round($percent, 2)
        ];
    }

    // Ziyaretçi ve DDoS tespiti
    $visitorFile = __DIR__ . '/visitors.log';
    $ip = $_SERVER['REMOTE_ADDR'];
    $now = time();

    // Ziyaretçi kaydet
    $visitors = [];
    if (file_exists($visitorFile)) {
        $lines = file($visitorFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            list($vIp, $vTime) = explode('|', $line);
            if ($now - (int)$vTime <= 300) { // son 5 dakika
                $visitors[] = [$vIp, (int)$vTime];
            }
        }
    }
    $visitors[] = [$ip, $now];

    // Dosyaya yaz (sadece son 5 dakikadakiler)
    $linesToWrite = [];
    foreach ($visitors as $v) {
        $linesToWrite[] = $v[0] . '|' . $v[1];
    }
    file_put_contents($visitorFile, implode("\n", $linesToWrite));

    // Anlık ziyaretçi sayısı (farklı IP sayısı)
    $uniqueIps = [];
    foreach ($visitors as $v) {
        $uniqueIps[$v[0]] = true;
    }
    $visitorCount = count($uniqueIps);

    // DDoS tespiti: aynı IP'den son 10 saniyede kaç istek geldiğine bak
    $recentRequests = 0;
    foreach ($visitors as $v) {
        if ($v[0] === $ip && $now - $v[1] <= 10) {
            $recentRequests++;
        }
    }

    $ddosDetected = false;
    $ddosIps = [];

    if ($recentRequests > 20) {
        $ddosDetected = true;
        $ddosIps[] = $ip;

        // Mail gönder (sadece 10 dakikada bir gönder)
        if (empty($_SESSION['ddos_alert_sent']) || $_SESSION['ddos_alert_sent'] + 600 < $now) {
            $_SESSION['ddos_alert_sent'] = $now;

            $to = 'admin@example.com';
            $subject = 'DDoS Saldırısı Tespit Edildi';
            $message = "Sunucunuza DDoS saldırısı tespit edildi.\n\n"
                . "Saldırı yapan IP: $ip\n"
                . "Zaman: " . date('Y-m-d H:i:s') . "\n\n"
                . "Lütfen sunucunuzu kontrol edin.";
            $headers = 'From: no-reply@yourdomain.com' . "\r\n";

            @mail($to, $subject, $message, $headers);
        }
    }

    // --- JSON çıktısı ---
    echo json_encode([
        'cpu' => getCpuUsage(),
        'ram' => getRamUsage(),
        'disk' => getDiskUsage('/'),
        'visitorCount' => $visitorCount,
        'ddosDetected' => $ddosDetected,
        'ddosIps' => $ddosIps,
        'server_ip' => $_SERVER['SERVER_ADDR'] ?? shell_exec('hostname -I'),
        'hostname' => gethostname(),
        'os' => php_uname('s'),
        'process_count' => intval(trim(shell_exec('ps aux | wc -l'))) - 1,
        'php_version' => phpversion(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'UNKNOWN',
        'network_info' => trim(shell_exec('hostname -I') ?? 'UNKNOWN'),
        'timestamp' => date('H:i:s')
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>:: DARKNET SURVEILLANCE ::</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap');
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: #000000;
            color: #00ff41;
            font-family: 'Share Tech Mono', monospace;
            overflow: hidden;
            height: 100vh;
            background-image: 
                linear-gradient(rgba(0, 255, 65, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 255, 65, 0.03) 1px, transparent 1px);
            background-size: 20px 20px;
        }
        
        .terminal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: repeating-linear-gradient(
                0deg,
                rgba(0, 0, 0, 0.1),
                rgba(0, 0, 0, 0.1) 1px,
                transparent 1px,
                transparent 3px
            );
            pointer-events: none;
            z-index: 1;
        }
        
        .scan-line {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: linear-gradient(to bottom, rgba(0, 255, 65, 0.4), transparent);
            box-shadow: 0 0 15px rgba(0, 255, 65, 0.7);
            animation: scan 2s linear infinite;
            z-index: 1000;
            pointer-events: none;
        }
        
        @keyframes scan {
            0% { top: 0; }
            100% { top: 100%; }
        }
        
        .container {
            position: relative;
            z-index: 10;
            width: 100%;
            margin: 0 auto;
            padding: 20px;
            background-color: rgba(0, 20, 0, 0.9);
            border: 1px solid #00ff41;
            box-shadow: 
                0 0 20px rgba(0, 255, 65, 0.3),
                inset 0 0 25px rgba(0, 60, 0, 0.9);
            min-height: 100vh;
        }
        
        .header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #00ff41;
            position: relative;
        }
        
        .header h1 {
            font-size: 26px;
            letter-spacing: 4px;
            text-shadow: 
                0 0 8px #00ff41,
                0 0 15px #00ff41,
                0 0 25px #008822;
            margin-bottom: 12px;
            font-weight: normal;
        }
        
        .header-status {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            font-size: 14px;
        }
        
        .status-led {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background-color: #00ff41;
            box-shadow: 0 0 12px #00ff41;
            animation: pulse 0.8s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; box-shadow: 0 0 12px #00ff41; }
            50% { opacity: 0.4; box-shadow: 0 0 4px #008822; }
        }
        
        .main-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        table {
            border-collapse: collapse;
            width: 100%;
            border: 2px solid #00ff41;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 255, 65, 0.5);
            background: #001100;
            transition: all 0.5s ease;
            color: #00ff41;
            overflow-x: auto;
            min-width: 300px;
        }
        
        table.high-usage {
            border-color: #ff0000;
            box-shadow: 0 0 20px #ff0000;
            color: #ff0000;
            background: #330000;
        }
        
        caption {
            font-size: 1.8rem;
            font-weight: 700;
            padding: 12px 0;
            border-bottom: 1px solid currentColor;
            letter-spacing: 2px;
        }
        
        th, td {
            padding: 12px 20px;
            text-align: center;
            font-size: 1.3rem;
            border-bottom: 1px solid #003300;
            transition: color 0.5s ease;
        }
        
        table.high-usage th, table.high-usage td {
            border-color: #660000;
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        .bar-container {
            background: #003300;
            border-radius: 10px;
            height: 16px;
            width: 100%;
            box-shadow: inset 0 0 5px currentColor;
            margin-top: 6px;
        }
        
        .bar-fill {
            height: 100%;
            background: currentColor;
            border-radius: 10px 0 0 10px;
            transition: width 0.4s ease;
        }
        
        .unit {
            font-size: 1rem;
            font-weight: 600;
            color: inherit;
            margin-left: 5px;
        }
        
        .note {
            margin-top: 20px;
            font-size: 1rem;
            color: #007700;
            font-style: italic;
            letter-spacing: 1.5px;
        }
        
        .system-info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 25px;
        }
        
        .info-card {
            background-color: rgba(0, 25, 0, 0.7);
            border: 1px solid #006622;
            padding: 18px;
            font-size: 14px;
            min-width: 300px;
        }
        
        .info-card:hover {
            border-color: #00aa33;
            box-shadow: 0 0 15px rgba(0, 170, 51, 0.3);
        }
        
        .info-card h3 {
            color: #00ff41;
            margin-bottom: 12px;
            font-size: 18px;
            border-bottom: 1px solid #004411;
            padding-bottom: 8px;
            text-shadow: 0 0 5px rgba(0, 255, 65, 0.7);
        }
        
        .terminal-log {
            background-color: rgba(0, 15, 0, 0.95);
            border: 1px solid #004411;
            padding: 15px;
            height: 180px;
            overflow-y: auto;
            font-size: 13px;
            margin-top: 25px;
            box-shadow: inset 0 0 20px rgba(0, 30, 0, 0.8);
        }
        
        .log-entry {
            margin: 6px 0;
            padding: 4px 8px;
            border-left: 2px solid #008822;
            background-color: rgba(0, 20, 0, 0.3);
            animation: logFadeIn 0.3s;
        }
        
        @keyframes logFadeIn {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .critical {
            color: #ff0044;
            text-shadow: 0 0 8px rgba(255, 0, 68, 0.8);
        }
        
        .warning {
            color: #ffaa00;
            text-shadow: 0 0 8px rgba(255, 170, 0, 0.8);
        }
        
        .info {
            color: #00aaff;
            text-shadow: 0 0 5px rgba(0, 170, 255, 0.7);
        }
        
        .binary-rain {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }
        
        .binary-column {
            position: absolute;
            top: -100px;
            color: #00ff41;
            opacity: 0.4;
            font-size: 18px;
            writing-mode: vertical-rl;
            text-orientation: mixed;
            text-shadow: 0 0 5px rgba(0, 255, 65, 0.8);
            animation: fall linear infinite;
        }
        
        @keyframes fall {
            to { transform: translateY(100vh); }
        }
        
        .time-display {
            text-align: center;
            font-size: 20px;
            margin: 18px 0;
            color: #00ff41;
            text-shadow: 0 0 10px rgba(0, 255, 65, 0.9);
            letter-spacing: 2px;
        }
        
        .controls {
            text-align: center;
            margin-top: 25px;
        }
        
        .refresh-btn {
            background-color: #000000;
            color: #00ff41;
            border: 1px solid #00ff41;
            padding: 14px 30px;
            font-family: 'Courier New', monospace;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s;
            letter-spacing: 2px;
            text-shadow: 0 0 5px rgba(0, 255, 65, 0.7);
            width: 100%;
            max-width: 300px;
        }
        
        .refresh-btn:hover {
            background-color: #003311;
            box-shadow: 0 0 25px rgba(0, 255, 65, 0.9);
            transform: scale(1.05);
        }
        
        .connection-status {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 10px;
            font-size: 12px;
            flex-wrap: wrap;
        }
        
        .status-item {
            padding: 8px 15px;
            background-color: rgba(0, 25, 0, 0.6);
            border: 1px solid #006622;
            white-space: nowrap;
        }
        
        footer {
            margin-top: auto;
            font-size: 0.9rem;
            color: #004400;
            font-family: monospace;
            padding: 10px 0;
            text-align: center;
        }
        
        /* Tablet için özel stiller */
        @media (max-width: 1024px) and (min-width: 768px) {
            .container {
                padding: 15px;
                margin: 0;
                width: 100%;
                max-width: 100%;
                min-height: 100vh;
                box-sizing: border-box;
            }
            
            .main-grid {
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 10px;
            }
            
            .system-info-grid {
                grid-template-columns: repeat(3, 1fr) !important;
            }
            
            table {
                min-width: unset;
                font-size: 0.9em;
            }
            
            th, td {
                padding: 8px 12px;
                font-size: 1.1rem;
            }
            
            caption {
                font-size: 1.5rem;
            }
            
            .terminal-log {
                height: 120px;
            }
            
            .info-card {
                min-width: unset;
                padding: 12px;
            }
            
            .header h1 {
                font-size: 22px;
            }
            
            .time-display {
                font-size: 18px;
                margin: 12px 0;
            }
        }
        
        /* Daha küçük tabletler için ek ayarlar */
        @media (max-width: 900px) and (min-width: 768px) {
            .main-grid {
                gap: 8px;
            }
            
            th, td {
                padding: 6px 8px;
                font-size: 1rem;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .terminal-log {
                height: 100px;
                font-size: 12px;
            }
            
            .info-card {
                padding: 10px;
                font-size: 12px;
            }
            
            .info-card h3 {
                font-size: 16px;
            }
        }
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .main-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .system-info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 900px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
            .system-info-grid {
                grid-template-columns: 1fr;
            }
            .connection-status {
                flex-direction: column;
                align-items: center;
                gap: 10px;
            }
            .status-item {
                width: 100%;
                max-width: 300px;
                text-align: center;
            }
        }
        
        @media (max-width: 700px) {
            .header h1 {
                font-size: 22px;
            }
            .header-status {
                font-size: 12px;
            }
            .time-display {
                font-size: 18px;
            }
            .binary-column {
                font-size: 14px;
            }
            .main-grid, .system-info-grid {
                gap: 10px;
            }
            table {
                min-width: 280px;
            }
            .info-card {
                min-width: 280px;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 15px;
                margin: 10px auto;
            }
            .header h1 {
                font-size: 18px;
            }
            .header-status {
                font-size: 10px;
            }
            .time-display {
                font-size: 16px;
            }
            caption {
                font-size: 1.4rem;
            }
            th, td {
                padding: 8px 10px;
                font-size: 1rem;
            }
            .bar-container {
                height: 12px;
            }
            .terminal-log {
                height: 150px;
                font-size: 12px;
            }
            .refresh-btn {
                font-size: 14px;
                padding: 10px 20px;
            }
            .connection-status {
                font-size: 10px;
            }
            .status-item {
                padding: 6px 10px;
                font-size: 10px;
            }
            .binary-column {
                font-size: 12px;
            }
        }
        
        /* Landscape modu için özel düzenlemeler */
        @media (max-width: 1024px) and (min-width: 768px) and (orientation: landscape) {
            .container {
                padding: 10px;
                margin: 0;
                width: 100%;
                min-height: 100vh;
            }
            
            .main-grid {
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 8px;
            }
            
            .system-info-grid {
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 10px;
            }
            
            table {
                min-width: unset;
            }
            
            .info-card {
                min-width: unset;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .terminal-log {
                height: 100px;
            }
            
            .connection-status {
                flex-direction: row;
                justify-content: center;
            }
            
            .status-item {
                width: auto;
                max-width: none;
            }
        }
        
        /* Daha küçük tabletlerde landscape modu */
        @media (max-width: 900px) and (min-width: 768px) and (orientation: landscape) {
            .header h1 {
                font-size: 18px;
            }
            
            .main-grid {
                gap: 6px;
            }
            
            th, td {
                padding: 5px 6px;
                font-size: 0.9rem;
            }
            
            caption {
                font-size: 1.3rem;
            }
            
            .info-card h3 {
                font-size: 14px;
            }
            
            .info-card {
                font-size: 11px;
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="scan-line"></div>
    <div class="terminal-overlay"></div>
    <div class="binary-rain" id="binaryRain"></div>
    
    <div class="container">
        <div class="header">
            <h1>DARKNET :: REAL-TIME SURVEILLANCE :: v6.1</h1>
            <div class="header-status">
                <div class="status-led"></div>
                <span>SYSTEM STATUS: <span id="systemStatus">ONLINE</span> | ACCESS: ROOT | SECURITY: MILITARY GRADE</span>
                <div class="status-led"></div>
            </div>
        </div>
        
        <div class="time-display">
            <span>> LAST UPDATE: <span id="updateTime"><?php echo date('H:i:s'); ?></span> <<</span>
        </div>
        
        <div class="main-grid" id="metricsGrid">
            <table id="cpu-table" aria-label="CPU Usage">
                <caption>CPU USAGE</caption>
                <tr><th>PERCENTAGE</th></tr>
                <tr>
                    <td id="cpu-value">
                        <div style="font-size:2.8rem; font-weight:900; text-shadow: 0 0 10px currentColor;">
                            -- <span class="unit">%</span>
                        </div>
                        <div class="bar-container" aria-label="CPU usage bar">
                            <div class="bar-fill" style="width: 0%;"></div>
                        </div>
                    </td>
                </tr>
            </table>
            
            <table id="ram-table" aria-label="RAM Usage">
                <caption>MEMORY USAGE</caption>
                <tr>
                    <th>USED</th>
                    <th>TOTAL</th>
                    <th>PERCENTAGE</th>
                </tr>
                <tr>
                    <td id="ram-used">-- MB</td>
                    <td id="ram-total">-- MB</td>
                    <td id="ram-percent">
                        -- <span class="unit">%</span>
                        <div class="bar-container" aria-label="RAM usage bar">
                            <div class="bar-fill" style="width: 0%;"></div>
                        </div>
                    </td>
                </tr>
            </table>
            
            <table id="disk-table" aria-label="Disk Usage">
                <caption>STORAGE USAGE (/)</caption>
                <tr>
                    <th>USED</th>
                    <th>TOTAL</th>
                    <th>PERCENTAGE</th>
                </tr>
                <tr>
                    <td id="disk-used">-- GB</td>
                    <td id="disk-total">-- GB</td>
                    <td id="disk-percent">
                        -- <span class="unit">%</span>
                        <div class="bar-container" aria-label="Disk usage bar">
                            <div class="bar-fill" style="width: 0%;"></div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="connection-status">
            <div class="status-item">ENCRYPTION: <span class="info">AES-256</span></div>
            <div class="status-item">PROTOCOL: <span class="info">TCP/IP v4.0</span></div>
            <div class="status-item">SESSION: <span class="info"><?php echo substr(session_id(), 0, 8); ?>***</span></div>
        </div>
        
        <div class="terminal-log" id="terminalLog">
            <div class="log-entry">>> DARKNET SURVEILLANCE ACTIVATED...</div>
            <div class="log-entry">>> REAL-TIME SYSTEM MONITORING INITIATED...</div>
            <div class="log-entry">>> CONNECTION ESTABLISHED WITH ROOT ACCESS...</div>
        </div>
        
        <div class="system-info-grid">
            <div class="info-card">
                <h3>[SERVER NODE]</h3>
                <p>IP ADDRESS: <span id="serverIP" class="info">0.0.0.0</span></p>
                <p>HOSTNAME: <span id="hostname" class="info"><?php echo gethostname(); ?></span></p>
                <p>OS: <span id="osInfo" class="info"><?php echo php_uname('s'); ?></span></p>
            </div>
            
            <div class="info-card">
                <h3>[PROCESS DATA]</h3>
                <p>ACTIVE PROCESSES: <span id="processCount" class="info">0</span></p>
                <p>PHP VERSION: <span id="phpVersion" class="info"><?php echo phpversion(); ?></span></p>
                <p>SERVER TYPE: <span id="serverType" class="info"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'UNKNOWN'; ?></span></p>
            </div>
            
            <div class="info-card">
                <h3>[NETWORK PORTS]</h3>
                <p>INTERFACES: <span id="networkInfo" class="info">LOADING...</span></p>
                <p>MONITORING PORT: <span class="info">80/HTTP</span></p>
                <p>SECURITY LEVEL: <span class="info">MAXIMUM</span></p>
            </div>
        </div>
        
        <div class="controls">
            <button class="refresh-btn" onclick="manualRefresh()">[ FORCE REFRESH ]</button>
        </div>
    </div>

    <footer>
        © <?php echo date('Y'); ?> - DARKNET SURVEILLANCE | 
        <span id="visitor-count">VISITORS: --</span>
        <span id="ddos-alert" style="color:#ff4444; font-weight:bold; margin-left:20px;"></span>
    </footer>

    <script>
        // Binary rain efekti oluştur
        function createBinaryRain() {
            const rain = document.getElementById('binaryRain');
            rain.innerHTML = '';
            
            // Mobile devices get fewer columns for better performance
            const columns = Math.floor(window.innerWidth / (window.innerWidth > 768 ? 25 : 15));
            
            for(let i = 0; i < columns; i++) {
                const column = document.createElement('div');
                column.className = 'binary-column';
                column.style.left = (i * (window.innerWidth > 768 ? 25 : 15)) + 'px';
                column.style.animationDuration = (Math.random() * 5 + 3) + 's';
                column.style.animationDelay = (Math.random() * 2) + 's';
                column.style.opacity = Math.random() * 0.3 + 0.2;
                
                let binaryString = '';
                const length = Math.floor(Math.random() * (window.innerWidth > 768 ? 40 : 30)) + 20;
                for(let j = 0; j < length; j++) {
                    binaryString += Math.random() > 0.5 ? '1' : '0';
                }
                column.innerHTML = binaryString;
                
                rain.appendChild(column);
            }
        }
        
        // Terminal loguna mesaj ekle
        function addLogMessage(message) {
            const log = document.getElementById('terminalLog');
            const entry = document.createElement('div');
            entry.className = 'log-entry';
            entry.innerHTML = message;
            log.appendChild(entry);
            log.scrollTop = log.scrollHeight;
            
            // Maksimum log sayısı kontrolü
            while(log.children.length > 20) {
                log.removeChild(log.firstChild);
            }
        }
        
        // Sunucu verilerini AJAX ile al
        function fetchServerData() {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', '<?php echo $_SERVER['PHP_SELF']; ?>?ajax=1&t=' + Date.now(), true);
            xhr.timeout = 5000;
            xhr.onreadystatechange = function() {
                if(xhr.readyState === 4) {
                    if(xhr.status === 200) {
                        try {
                            const data = JSON.parse(xhr.responseText);
                            updateDisplay(data);
                            addLogMessage(`[${data.timestamp}] SYSTEM DATA REFRESHED | CPU: ${data.cpu}% | RAM: ${data.ram.used}MB | DISK: ${data.disk.percent}%`);
                        } catch(e) {
                            addLogMessage('[ERROR] Data parsing failed: ' + e.message);
                        }
                    } else {
                        addLogMessage('[ERROR] Server connection failed');
                    }
                }
            };
            xhr.onerror = function() {
                addLogMessage('[ERROR] Network error occurred');
            };
            xhr.ontimeout = function() {
                addLogMessage('[ERROR] Request timeout');
            };
            xhr.send();
        }
        
        // Verileri ekranda güncelle
        function updateDisplay(data) {
            // CPU
            const cpuVal = data.cpu !== null ? data.cpu.toFixed(2) : '--';
            document.querySelector('#cpu-value > div:first-child').innerHTML = cpuVal + ' <span class="unit">%</span>';
            document.querySelector('#cpu-value .bar-fill').style.width = (data.cpu !== null ? data.cpu : 0) + '%';
            
            const cpuTable = document.getElementById('cpu-table');
            if (data.cpu !== null && data.cpu >= 70) {
                cpuTable.classList.add('high-usage');
            } else {
                cpuTable.classList.remove('high-usage');
            }
            
            // RAM
            if (data.ram !== null) {
                document.getElementById('ram-used').textContent = data.ram.used + ' MB';
                document.getElementById('ram-total').textContent = data.ram.total + ' MB';
                document.getElementById('ram-percent').childNodes[0].nodeValue = data.ram.percent + ' ';
                document.querySelector('#ram-percent .bar-fill').style.width = data.ram.percent + '%';
                
                const ramTable = document.getElementById('ram-table');
                if (data.ram.percent >= 70) {
                    ramTable.classList.add('high-usage');
                } else {
                    ramTable.classList.remove('high-usage');
                }
            } else {
                document.getElementById('ram-used').textContent = 'N/A';
                document.getElementById('ram-total').textContent = 'N/A';
                document.getElementById('ram-percent').childNodes[0].nodeValue = 'N/A ';
                document.querySelector('#ram-percent .bar-fill').style.width = '0%';
            }
            
            // Disk
            document.getElementById('disk-used').textContent = data.disk.used + ' GB';
            document.getElementById('disk-total').textContent = data.disk.total + ' GB';
            document.getElementById('disk-percent').childNodes[0].nodeValue = data.disk.percent + ' ';
            document.querySelector('#disk-percent .bar-fill').style.width = data.disk.percent + '%';
            
            const diskTable = document.getElementById('disk-table');
            if (data.disk.percent >= 70) {
                diskTable.classList.add('high-usage');
            } else {
                diskTable.classList.remove('high-usage');
            }
            
            // System Info
            document.getElementById('serverIP').textContent = data.server_ip || 'N/A';
            document.getElementById('processCount').textContent = data.process_count;
            document.getElementById('networkInfo').textContent = data.network_info || 'N/A';
            document.getElementById('updateTime').textContent = data.timestamp;
            
            // Ziyaretçi ve DDoS
            document.getElementById('visitor-count').textContent = 'VISITORS: ' + data.visitorCount;
            
            const ddosAlert = document.getElementById('ddos-alert');
            if (data.ddosDetected && data.ddosIps.length > 0) {
                ddosAlert.textContent = 'DDoS ATTACK DETECTED! IP: ' + data.ddosIps.join(', ');
            } else {
                ddosAlert.textContent = '';
            }
        }
        
        // Manuel yenileme
        function manualRefresh() {
            addLogMessage('[INFO] Manual refresh initiated...');
            fetchServerData();
        }
        
        // Sayfa yüklendiğinde başlat
        window.onload = function() {
            createBinaryRain();
            fetchServerData();
            
            // İlk yükleme logu
            addLogMessage('[SYSTEM] DARKNET SURVEILLANCE v6.1 ACTIVATED');
            addLogMessage('[STATUS] REAL-TIME MONITORING ENGINE RUNNING');
            addLogMessage('[SECURITY] AES-256 ENCRYPTION ENABLED');
            
            // Otomatik güncelleme
            setInterval(fetchServerData, 3000);
        };
        
        // Window resize event
        window.addEventListener('resize', function() {
            createBinaryRain();
        });
    </script>
</body>
</html>
<?php
// run.php - HAPUS FILE INI SETELAH SELESAI DIPAKAI!
// Taruh file ini di folder: HaloAPU/public/run.php
// Akses: https://dev.haloapu.id/run.php?key=KODE_RAHASIA&cmd=COMMAND

$SECRET_KEY = 'haloapu_secret_2026'; // GANTI dengan kode rahasia kamu sendiri!

if (($_GET['key'] ?? '') !== $SECRET_KEY) {
    http_response_code(403);
    die('Unauthorized');
}

set_time_limit(300);
ini_set('memory_limit', '512M');

$command = $_GET['cmd'] ?? '';

// Kalau tidak ada command, tampilkan menu
if (empty($command)) {
    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
    $key = urlencode($SECRET_KEY);
    
    echo "<html><head><title>HaloAPU Runner</title>";
    echo "<style>
        body { font-family: 'Courier New', monospace; background: #1a1a2e; color: #e0e0e0; padding: 30px; max-width: 800px; margin: 0 auto; }
        h1 { color: #7c4dff; }
        a { color: #40c4ff; text-decoration: none; display: block; padding: 8px 12px; margin: 4px 0; background: #16213e; border-radius: 6px; border-left: 3px solid #7c4dff; }
        a:hover { background: #1a2744; }
        .section { margin: 20px 0; }
        .section h2 { color: #ffab40; font-size: 1em; }
        .danger { background: #3e1621; border-left-color: #ff5252; padding: 15px; border-radius: 8px; margin-top: 30px; }
    </style></head><body>";
    echo "<h1>🚀 HaloAPU Runner</h1>";
    
    echo "<div class='section'><h2>📦 Storage / Image Fix</h2>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=try-symlink'>🔗 try-symlink — Buat symlink storage</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=fix-storage-copy'>📋 fix-storage-copy — Copy file storage (kalau symlink gagal)</a>";
    echo "</div>";
    
    echo "<div class='section'><h2>⚙️ Artisan Commands</h2>";
    $cmds = ['optimize:clear', 'config:clear', 'cache:clear', 'route:clear', 'view:clear', 'config:cache', 'route:cache', 'view:cache', 'storage:link', 'migrate --force', 'key:generate --force', 'ziggy:generate', 'schedule:run', 'sla:check', 'simulate:sla-and-reminders'];
    foreach ($cmds as $c) {
        $label = $c;
        echo "<a href='{$baseUrl}?key={$key}&cmd=" . urlencode($c) . "'>▶ {$label}</a>";
    }
    echo "</div>";
    
    echo "<div class='section'><h2>🚀 Deployment</h2>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=deploy' style='border-left-color: #00e676;'>▶ deploy — Jalankan migrate --force & optimize:clear</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=deploy-cpanel' style='border-left-color: #00b0ff;'>▶ deploy-cpanel — Full cPanel Deploy (Down, Cache, Migrate, Storage, Up)</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=seed-permissions' style='border-left-color: #ffab40;'>🔑 seed-permissions — Perbaiki hak akses sidebar</a>";
    echo "</div>";
    
    echo "<div class='section'><h2>⏱️ SLA Management</h2>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=sla-diagnose' style='border-left-color: #ff5252;'>🔧 sla-diagnose — Diagnosa & perbaiki tabel SLA (jalankan pertama!)</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=sla-view' style='border-left-color: #00e676;'>📊 sla-view — Lihat semua konfigurasi SLA saat ini</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=sla-update' style='border-left-color: #ffab40;'>✏️ sla-update — Update waktu SLA (via form)</a>";
    echo "</div>";
    
    echo "<div class='section'><h2>🔍 Diagnostik</h2>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=check-route'>🔍 check-route — Cek route web.php di server</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=opcache-reset'>🧹 opcache-reset — Hapus cache RAM PHP</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=check-storage'>🔍 check-storage — Cek status storage link</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=check-permissions'>🔍 check-permissions — Cek permission folder</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=read-log'>📄 read-log — Lihat error log terbaru</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=check-env'>⚙️ check-env — Cek konfigurasi Mailer & Env</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=force-test-reset'>🚀 force-test-reset — Tes Fitur Lupa Password (Bypass Mail)</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=clear-throttle'>🧹 clear-throttle — Hapus limit lupa password</a>";
    echo "<a href='{$baseUrl}?key={$key}&cmd=test-email'>📧 test-email — Tes Koneksi SMTP Server</a>";
    echo "</div>";
    
    echo "<div class='danger'><p>⚠️ <b>HAPUS file ini setelah selesai dipakai!</b></p></div>";
    echo "</body></html>";
    exit;
}

// ==============================
// Command khusus: Cek status storage
// ==============================
if ($command === 'check-storage') {
    echo "<pre>";
    $link = __DIR__ . '/storage';
    $target = __DIR__ . '/../storage/app/public';
    
    echo "=== Storage Status ===\n\n";
    
    echo "Public storage path: $link\n";
    echo "  Exists: " . (file_exists($link) ? 'YES' : 'NO') . "\n";
    echo "  Is symlink: " . (is_link($link) ? 'YES' : 'NO') . "\n";
    echo "  Is directory: " . (is_dir($link) ? 'YES' : 'NO') . "\n";
    if (is_link($link)) {
        echo "  Symlink target: " . readlink($link) . "\n";
    }
    
    echo "\nSource path: $target\n";
    echo "  Exists: " . (file_exists($target) ? 'YES' : 'NO') . "\n";
    echo "  Is directory: " . (is_dir($target) ? 'YES' : 'NO') . "\n";
    
    if (is_dir($target)) {
        $files = scandir($target);
        $files = array_diff($files, ['.', '..', '.gitignore']);
        echo "  Files/folders: " . count($files) . "\n";
        foreach ($files as $f) {
            $full = "$target/$f";
            echo "    - $f " . (is_dir($full) ? '[DIR]' : '(' . filesize($full) . ' bytes)') . "\n";
        }
    }
    
    echo "\nsymlink() available: " . (function_exists('symlink') ? 'YES' : 'NO') . "\n";
    
    // Cek apakah image bisa diakses
    if (is_dir($link)) {
        echo "\n=== Test Akses ===\n";
        echo "Folder public/storage bisa diakses: YES\n";
    } else {
        echo "\n⚠️ public/storage TIDAK ADA — image tidak akan tampil!\n";
        echo "Jalankan: try-symlink atau fix-storage-copy\n";
    }
    
    echo "</pre>";
    exit;
}

// ==============================
// Command khusus: Cek route web.php
// ==============================
if ($command === 'check-route') {
    echo "<pre>=== Cek File routes/web.php ===\n\n";
    $path = __DIR__ . '/../routes/web.php';
    if (!file_exists($path)) {
        echo "❌ File routes/web.php TIDAK DITEMUKAN!\n";
    } else {
        $content = file_get_contents($path);
        if (strpos($content, 'system.notification-sound') !== false) {
            echo "✅ Route 'system.notification-sound' ADA di dalam file routes/web.php server.\n";
            echo "Jika masih error, berarti masalahnya ada di OPcache. Silakan jalankan 'opcache-reset'.\n";
        } else {
            echo "❌ Route 'system.notification-sound' BELUM ADA di server!\n\n";
            echo "Alasan: File routes/web.php yang ada di server ini masih versi LAMA.\n";
            echo "Solusi: Silakan UPLOAD file routes/web.php yang terbaru dari komputer Anda ke server (cPanel).\n";
        }
    }
    echo "</pre>";
    exit;
}

// ==============================
// Command khusus: Reset OPcache
// ==============================
if ($command === 'opcache-reset') {
    echo "<pre>=== Reset OPcache ===\n\n";
    if (function_exists('opcache_reset')) {
        $result = opcache_reset();
        echo $result ? "✅ OPcache berhasil di-reset!\n" : "⚠️ OPcache gagal di-reset (mungkin dinonaktifkan).\n";
    } else {
        echo "❌ Fungsi opcache_reset() tidak tersedia di server ini.\n";
    }
    echo "</pre>";
    exit;
}

// ==============================
// Command khusus: Cek permissions
// ==============================
if ($command === 'check-permissions') {
    echo "<pre>";
    echo "=== Permission Check ===\n\n";
    
    $dirs = [
        'storage' => __DIR__ . '/../storage',
        'storage/app' => __DIR__ . '/../storage/app',
        'storage/app/public' => __DIR__ . '/../storage/app/public',
        'storage/framework' => __DIR__ . '/../storage/framework',
        'storage/framework/cache' => __DIR__ . '/../storage/framework/cache',
        'storage/framework/sessions' => __DIR__ . '/../storage/framework/sessions',
        'storage/framework/views' => __DIR__ . '/../storage/framework/views',
        'storage/logs' => __DIR__ . '/../storage/logs',
        'bootstrap/cache' => __DIR__ . '/../bootstrap/cache',
        'public' => __DIR__,
    ];
    
    foreach ($dirs as $label => $path) {
        $exists = is_dir($path);
        $perms = $exists ? substr(sprintf('%o', fileperms($path)), -4) : 'N/A';
        $writable = $exists ? (is_writable($path) ? 'YES' : 'NO') : 'N/A';
        $status = !$exists ? '❌ NOT FOUND' : ($writable === 'YES' ? '✅' : '⚠️ NOT WRITABLE');
        echo "$status $label — perms: $perms, writable: $writable\n";
        
        if (!$exists) {
            @mkdir($path, 0755, true);
            if (is_dir($path)) {
                echo "   → Created!\n";
            }
        }
    }
    
    echo "\nPHP user: " . get_current_user() . "\n";
    echo "PHP version: " . PHP_VERSION . "\n";
    echo "</pre>";
    exit;
}

// ==============================
// Command khusus: Baca log
// ==============================
if ($command === 'read-log') {
    $logPath = __DIR__ . '/../storage/logs/laravel.log';
    echo "<pre>=== Laravel Log ===\n\n";
    if (!file_exists($logPath)) {
        echo "TIDAK ADA LOG: File $logPath tidak ditemukan.";
    } else {
        // Read last 200 lines
        $file = file($logPath);
        if ($file === false) {
            echo "Gagal membaca file log.";
        } else {
            $lines = array_slice($file, -200);
            echo htmlspecialchars(implode("", $lines));
        }
    }
    echo "</pre>";
    exit;
}

// ==============================
// Command khusus: Cek Env & Mailer
// ==============================
if ($command === 'check-env') {
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    echo "<pre>=== Current Environment ===\n\n";
    echo "APP_ENV: " . env('APP_ENV') . "\n";
    echo "APP_DEBUG: " . (env('APP_DEBUG') ? 'true' : 'false') . "\n";
    echo "MAIL_MAILER: " . env('MAIL_MAILER') . "\n";
    echo "Config mail.default: " . config('mail.default') . "\n";
    echo "Config mail.mailers.smtp.host: " . config('mail.mailers.smtp.host') . "\n";
    echo "DB_CONNECTION: " . env('DB_CONNECTION') . "\n";
    
    try {
        DB::connection()->getPdo();
        echo "\nDB Connection: SUCCESS\n";
    } catch (\Exception $e) {
        echo "\nDB Connection: FAILED - " . $e->getMessage() . "\n";
    }
    
    echo "</pre>";
    exit;
}

// ==============================
// Command khusus: Force Test Reset
// ==============================
if ($command === 'force-test-reset') {
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    echo "<pre>=== Menguji Lupa Password (Force Log Mailer) ===\n\n";
    
    // Force konfigurasi mailer ke log saat runtime
    config(['mail.default' => 'log']);
    echo "Mail driver diubah ke: " . config('mail.default') . "\n";
    
    try {
        $email = 'sarpraslazalazhar@gmail.com'; // dari screenshot user
        $status = \Illuminate\Support\Facades\Password::broker('users')->sendResetLink(
            ['email' => $email]
        );
        
        echo "Status Broker: " . $status . "\n";
        if ($status === \Illuminate\Support\Facades\Password::RESET_LINK_SENT) {
            echo "✅ BERHASIL! Link reset telah diproses (masuk ke log, tidak dikirim betulan).\n";
        } else {
            echo "⚠️ GAGAL. Pesan: " . __($status) . "\n";
        }
    } catch (\Exception $e) {
        echo "❌ FATAL ERROR: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . " baris " . $e->getLine() . "\n";
    }
    
    echo "</pre>";
    exit;
}

// ==============================
// Command khusus: Clear Throttle
// ==============================
if ($command === 'clear-throttle') {
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    echo "<pre>=== Menghapus Limit (Throttle) Lupa Password ===\n\n";
    try {
        DB::table('password_reset_tokens')->truncate();
        echo "✅ BERHASIL: Limit telah direset. Kamu bisa mencoba fitur Lupa Password lagi sekarang.\n";
    } catch (\Exception $e) {
        echo "❌ GAGAL: " . $e->getMessage() . "\n";
    }
    echo "</pre>";
    exit;
}

// ==============================
// Command khusus: Test Email SMTP
// ==============================
if ($command === 'test-email') {
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    echo "<pre>=== Menguji Koneksi SMTP / Pengiriman Email ===\n\n";
    
    echo "Host: " . config('mail.mailers.smtp.host') . "\n";
    echo "Port: " . config('mail.mailers.smtp.port') . "\n";
    echo "Username: " . config('mail.mailers.smtp.username') . "\n";
    echo "Encryption: " . config('mail.mailers.smtp.encryption') . "\n\n";
    
    try {
        // Coba kirim email mentah
        \Illuminate\Support\Facades\Mail::raw('Ini adalah email pengetesan dari HaloAPU diagnostic tool.', function ($message) {
            $message->to(config('mail.from.address')) // kirim ke diri sendiri
                    ->subject('Test Koneksi SMTP HaloAPU');
        });
        
        echo "✅ BERHASIL! Server berhasil mengirim email menggunakan konfigurasi SMTP di atas.\n";
        echo "Cek inbox email " . config('mail.from.address') . " sekarang.";
    } catch (\Exception $e) {
        echo "❌ GAGAL MENGIRIM EMAIL!\n\n";
        echo "Error Detail:\n";
        echo $e->getMessage() . "\n\n";
        
        // Saran perbaikan otomatis
        $err = strtolower($e->getMessage());
        if (str_contains($err, 'certificate')) {
            echo "💡 SARAN: Masalah SSL Certificate. Coba ubah MAIL_HOST=127.0.0.1, MAIL_PORT=25, dan MAIL_ENCRYPTION=null.\n";
        } elseif (str_contains($err, 'authentication')) {
            echo "💡 SARAN: Password email salah, atau email belum dibuat di cPanel.\n";
        } elseif (str_contains($err, 'connection refused') || str_contains($err, 'timeout')) {
            echo "💡 SARAN: Port diblokir atau salah. Coba ubah MAIL_HOST=127.0.0.1, MAIL_PORT=25, dan MAIL_ENCRYPTION=null.\n";
        }
    }
    
    echo "</pre>";
    exit;
}

// ==============================
// Command khusus: Lihat semua SLA Config
// ==============================
if ($command === 'sla-view') {
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    // Auto-migrate old Kritis to Urgen safely
    try {
        DB::table('sla_configs')->where('priority', 'Kritis')->update(['priority' => 'Urgen', 'updated_at' => now()]);
    } catch (\Exception $e) {
        // If unique constraint fails (e.g. Urgen already exists), just delete the old Kritis
        DB::table('sla_configs')->where('priority', 'Kritis')->delete();
    }



    $configs = DB::table('sla_configs')
        ->leftJoin('sub_units', 'sla_configs.sub_unit_id', '=', 'sub_units.id')
        ->leftJoin('units', 'sub_units.unit_id', '=', 'units.id')
        ->select(
            'sla_configs.id',
            'sla_configs.sub_unit_id',
            'sub_units.name as sub_unit_name',
            'units.name as unit_name',
            'sla_configs.priority',
            'sla_configs.jenis',
            'sla_configs.threshold_minutes'
        )
        ->orderBy('sla_configs.sub_unit_id')
        ->orderBy('sla_configs.jenis')
        ->orderBy('sla_configs.priority')
        ->get();

    echo "<html><head><title>SLA Config Viewer</title>";
    echo "<style>
        body { font-family: 'Courier New', monospace; background: #1a1a2e; color: #e0e0e0; padding: 30px; margin: 0 auto; }
        h1 { color: #7c4dff; }
        h2 { color: #ffab40; font-size: 1em; }
        table { border-collapse: collapse; width: 100%; margin: 15px 0; }
        th { background: #16213e; color: #40c4ff; padding: 10px 12px; text-align: left; border: 1px solid #2a3a5e; }
        td { padding: 8px 12px; border: 1px solid #2a3a5e; }
        tr:nth-child(even) { background: #16213e; }
        tr:hover { background: #1a2744; }
        .global { color: #00e676; font-weight: bold; }
        .back { display: inline-block; margin-top: 20px; color: #40c4ff; text-decoration: none; padding: 8px 16px; background: #16213e; border-radius: 6px; border-left: 3px solid #7c4dff; }
        .back:hover { background: #1a2744; }
    </style></head><body>";
    echo "<h1>📊 SLA Config — Semua Konfigurasi</h1>";

    // Pisahkan global dan per-subunit
    $globals = $configs->filter(fn($c) => $c->sub_unit_id === null);
    $perSubUnit = $configs->filter(fn($c) => $c->sub_unit_id !== null)->groupBy('sub_unit_id');

    // Tabel Global
    echo "<h2>🌐 Default Global</h2>";
    echo "<table><tr><th>ID</th><th>Jenis</th><th>Priority</th><th>Threshold (menit)</th></tr>";
    foreach ($globals as $g) {
        echo "<tr><td>{$g->id}</td><td>{$g->jenis}</td><td>{$g->priority}</td><td><span class='global'>{$g->threshold_minutes}</span></td></tr>";
    }
    echo "</table>";

    // Tabel Per Sub Unit
    if ($perSubUnit->isNotEmpty()) {
        echo "<h2>🏢 Override Per Sub Unit</h2>";
        foreach ($perSubUnit as $subUnitId => $items) {
            $first = $items->first();
            $label = ($first->unit_name ?? '?') . ' — ' . ($first->sub_unit_name ?? '?');
            echo "<h3 style='color:#40c4ff; margin-top:20px;'>$label (sub_unit_id: $subUnitId)</h3>";
            echo "<table><tr><th>ID</th><th>Jenis</th><th>Priority</th><th>Threshold (menit)</th></tr>";
            foreach ($items as $item) {
                echo "<tr><td>{$item->id}</td><td>{$item->jenis}</td><td>{$item->priority}</td><td>{$item->threshold_minutes}</td></tr>";
            }
            echo "</table>";
        }
    }

    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
    $key = urlencode($SECRET_KEY);
    echo "<a class='back' href='{$baseUrl}?key={$key}'>← Kembali ke Menu</a>";
    echo "</body></html>";
    exit;
}

// ==============================
// Command khusus: Update SLA Config via Form
// ==============================
if ($command === 'sla-update') {
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
    $key = urlencode($SECRET_KEY);
    $message = '';

    // Auto-migrate old Kritis to Urgen safely
    try {
        DB::table('sla_configs')->where('priority', 'Kritis')->update(['priority' => 'Urgen', 'updated_at' => now()]);
    } catch (\Exception $e) {
        // If unique constraint fails (e.g. Urgen already exists), just delete the old Kritis
        DB::table('sla_configs')->where('priority', 'Kritis')->delete();
    }



    // Proses update jika ada parameter action=save
    if (($_GET['action'] ?? '') === 'save') {
        $priority = $_GET['priority'] ?? '';
        $jenis = $_GET['jenis'] ?? '';
        $minutes = (int) ($_GET['minutes'] ?? 0);
        $subUnitId = ($_GET['sub_unit_id'] ?? '') === '' ? null : (int) $_GET['sub_unit_id'];

        $validPriorities = ['Rendah', 'Sedang', 'Tinggi', 'Urgen'];
        $validJenis = ['respon', 'penyelesaian'];

        if (!in_array($priority, $validPriorities)) {
            $message = '❌ Priority tidak valid. Harus: ' . implode(', ', $validPriorities);
        } elseif (!in_array($jenis, $validJenis)) {
            $message = '❌ Jenis tidak valid. Harus: respon atau penyelesaian';
        } elseif ($minutes < 1) {
            $message = '❌ Waktu (minutes) harus minimal 1';
        } else {
            $exists = DB::table('sla_configs')
                ->where('sub_unit_id', $subUnitId)
                ->where('priority', $priority)
                ->where('jenis', $jenis)
                ->exists();
                
            if ($exists) {
                DB::table('sla_configs')
                    ->where('sub_unit_id', $subUnitId)
                    ->where('priority', $priority)
                    ->where('jenis', $jenis)
                    ->update(['threshold_minutes' => $minutes, 'updated_at' => now()]);
            } else {
                DB::table('sla_configs')->insert([
                    'sub_unit_id' => $subUnitId,
                    'priority' => $priority,
                    'jenis' => $jenis,
                    'threshold_minutes' => $minutes,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
            $scope = $subUnitId === null ? 'Global' : "Sub Unit ID: $subUnitId";
            $message = "✅ Berhasil update! [$scope] {$jenis} - {$priority} = {$minutes} menit";
        }
    }

    // Proses bulk update jika ada parameter action=bulk-save
    if (($_GET['action'] ?? '') === 'bulk-save') {
        $priorities = ['Rendah', 'Sedang', 'Tinggi', 'Urgen'];
        $jenisTypes = ['respon', 'penyelesaian'];
        $subUnitId = ($_GET['sub_unit_id'] ?? '') === '' ? null : (int) $_GET['sub_unit_id'];
        $count = 0;

        foreach ($jenisTypes as $jenis) {
            foreach ($priorities as $priority) {
                $paramKey = "{$jenis}_" . strtolower($priority);
                $val = $_GET[$paramKey] ?? '';
                if ($val !== '' && (int)$val >= 1) {
                    $exists = DB::table('sla_configs')
                        ->where('sub_unit_id', $subUnitId)
                        ->where('priority', $priority)
                        ->where('jenis', $jenis)
                        ->exists();
                        
                    if ($exists) {
                        DB::table('sla_configs')
                            ->where('sub_unit_id', $subUnitId)
                            ->where('priority', $priority)
                            ->where('jenis', $jenis)
                            ->update(['threshold_minutes' => (int)$val, 'updated_at' => now()]);
                    } else {
                        DB::table('sla_configs')->insert([
                            'sub_unit_id' => $subUnitId,
                            'priority' => $priority,
                            'jenis' => $jenis,
                            'threshold_minutes' => (int)$val,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                    $count++;
                }
            }
        }
        $scope = $subUnitId === null ? 'Global' : "Sub Unit ID: $subUnitId";
        $message = "✅ Berhasil update {$count} konfigurasi SLA! [$scope]";
    }

    // Ambil data saat ini untuk pre-fill form
    $currentConfigs = DB::table('sla_configs')
        ->whereNull('sub_unit_id')
        ->get()
        ->keyBy(fn($c) => "{$c->jenis}_{$c->priority}");

    // Ambil daftar sub_units untuk dropdown
    $subUnits = DB::table('sub_units')
        ->leftJoin('units', 'sub_units.unit_id', '=', 'units.id')
        ->select('sub_units.id', 'sub_units.name as sub_unit_name', 'units.name as unit_name')
        ->orderBy('units.name')
        ->orderBy('sub_units.name')
        ->get();

    echo "<html><head><title>SLA Config Updater</title>";
    echo "<style>
        body { font-family: 'Courier New', monospace; background: #1a1a2e; color: #e0e0e0; padding: 30px; max-width: 900px; margin: 0 auto; }
        h1 { color: #7c4dff; }
        h2 { color: #ffab40; font-size: 1em; margin-top: 25px; }
        .msg-ok { background: #1b3a2a; border-left: 4px solid #00e676; padding: 12px 16px; margin: 15px 0; border-radius: 6px; }
        .msg-err { background: #3e1621; border-left: 4px solid #ff5252; padding: 12px 16px; margin: 15px 0; border-radius: 6px; }
        table { border-collapse: collapse; width: 100%; margin: 15px 0; }
        th { background: #16213e; color: #40c4ff; padding: 10px 12px; text-align: left; border: 1px solid #2a3a5e; }
        td { padding: 8px 12px; border: 1px solid #2a3a5e; }
        input[type=number] { background: #0d1b2a; color: #e0e0e0; border: 1px solid #40c4ff; padding: 6px 10px; width: 80px; border-radius: 4px; text-align: center; }
        input[type=number]:focus { outline: none; border-color: #7c4dff; box-shadow: 0 0 5px rgba(124,77,255,0.5); }
        select { background: #0d1b2a; color: #e0e0e0; border: 1px solid #40c4ff; padding: 6px 10px; border-radius: 4px; }
        .btn { display: inline-block; color: #fff; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-family: inherit; font-size: 14px; text-decoration: none; margin: 5px 3px; }
        .btn-save { background: #00e676; color: #1a1a2e; font-weight: bold; }
        .btn-save:hover { background: #69f0ae; }
        .btn-view { background: #40c4ff; color: #1a1a2e; }
        .btn-view:hover { background: #80d8ff; }
        .back { display: inline-block; margin-top: 20px; color: #40c4ff; text-decoration: none; padding: 8px 16px; background: #16213e; border-radius: 6px; border-left: 3px solid #7c4dff; }
        .back:hover { background: #1a2744; }
        .help { color: #888; font-size: 0.85em; margin-top: 5px; }
    </style></head><body>";
    echo "<h1>✏️ SLA Config — Update Waktu</h1>";

    if ($message) {
        $cls = str_starts_with($message, '✅') ? 'msg-ok' : 'msg-err';
        echo "<div class='{$cls}'>{$message}</div>";
    }

    // Form Bulk Update Global
    echo "<h2>🌐 Update Default Global (Semua Sekaligus)</h2>";
    echo "<form method='GET' action='{$baseUrl}'>";
    echo "<input type='hidden' name='key' value='{$SECRET_KEY}'>";
    echo "<input type='hidden' name='cmd' value='sla-update'>";
    echo "<input type='hidden' name='action' value='bulk-save'>";
    echo "<input type='hidden' name='sub_unit_id' value=''>";
    echo "<table>";
    echo "<tr><th>Jenis</th><th>Rendah (menit)</th><th>Sedang (menit)</th><th>Tinggi (menit)</th><th>Urgen (menit)</th></tr>";

    foreach (['respon', 'penyelesaian'] as $jenis) {
        $label = $jenis === 'respon' ? 'SLA Respon' : 'SLA Penyelesaian';
        echo "<tr><td><strong>{$label}</strong></td>";
        foreach (['Rendah', 'Sedang', 'Tinggi', 'Urgen'] as $priority) {
            $paramKey = "{$jenis}_" . strtolower($priority);
            $current = $currentConfigs["{$jenis}_{$priority}"] ?? null;
            $val = $current ? $current->threshold_minutes : '';
            echo "<td><input type='number' name='{$paramKey}' value='{$val}' min='1' placeholder='menit'></td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    echo "<button type='submit' class='btn btn-save'>💾 Simpan Semua Global</button>";
    echo "</form>";

    // Form Update Per Sub Unit
    echo "<h2>🏢 Update Per Sub Unit (Satu per Satu)</h2>";
    echo "<form method='GET' action='{$baseUrl}'>";
    echo "<input type='hidden' name='key' value='{$SECRET_KEY}'>";
    echo "<input type='hidden' name='cmd' value='sla-update'>";
    echo "<input type='hidden' name='action' value='save'>";
    echo "<table>";
    echo "<tr><td><strong>Sub Unit</strong></td><td><select name='sub_unit_id'><option value=''>— Global (Default) —</option>";
    foreach ($subUnits as $su) {
        echo "<option value='{$su->id}'>{$su->unit_name} — {$su->sub_unit_name}</option>";
    }
    echo "</select></td></tr>";
    echo "<tr><td><strong>Priority</strong></td><td><select name='priority'><option value='Rendah'>Rendah</option><option value='Sedang'>Sedang</option><option value='Tinggi'>Tinggi</option><option value='Urgen'>Urgen</option></select></td></tr>";
    echo "<tr><td><strong>Jenis</strong></td><td><select name='jenis'><option value='respon'>Respon</option><option value='penyelesaian'>Penyelesaian</option></select></td></tr>";
    echo "<tr><td><strong>Waktu (menit)</strong></td><td><input type='number' name='minutes' min='1' placeholder='contoh: 60'></td></tr>";
    echo "</table>";
    echo "<button type='submit' class='btn btn-save'>💾 Simpan</button>";
    echo "</form>";

    echo "<p class='help'>💡 Tips: Gunakan <a href='{$baseUrl}?key={$key}&cmd=sla-view' style='color:#40c4ff;'>sla-view</a> untuk melihat semua konfigurasi saat ini.</p>";
    echo "<br>";
    echo "<a class='btn btn-view' href='{$baseUrl}?key={$key}&cmd=sla-view'>📊 Lihat Semua Config</a>";
    echo "<a class='back' href='{$baseUrl}?key={$key}'>← Kembali ke Menu</a>";
    echo "</body></html>";
    exit;
}

// ==============================
// Command khusus: Diagnosa & perbaiki tabel SLA
// ==============================
if ($command === 'sla-diagnose') {
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
    $key = urlencode($SECRET_KEY);

    // Auto-migrate old Kritis to Urgen safely
    try {
        DB::table('sla_configs')->where('priority', 'Kritis')->update(['priority' => 'Urgen', 'updated_at' => now()]);
    } catch (\Exception $e) {
        // If unique constraint fails (e.g. Urgen already exists), just delete the old Kritis
        DB::table('sla_configs')->where('priority', 'Kritis')->delete();
    }



    echo "<html><head><title>SLA Diagnose</title>";
    echo "<style>
        body { font-family: 'Courier New', monospace; background: #1a1a2e; color: #e0e0e0; padding: 30px; max-width: 900px; margin: 0 auto; }
        h1 { color: #7c4dff; }
        .ok { color: #00e676; }
        .err { color: #ff5252; }
        .warn { color: #ffab40; }
        .step { background: #16213e; padding: 12px 16px; margin: 8px 0; border-radius: 6px; border-left: 3px solid #2a3a5e; }
        .step.success { border-left-color: #00e676; }
        .step.error { border-left-color: #ff5252; }
        .step.warning { border-left-color: #ffab40; }
        .back { display: inline-block; margin-top: 20px; color: #40c4ff; text-decoration: none; padding: 8px 16px; background: #16213e; border-radius: 6px; border-left: 3px solid #7c4dff; }
        .back:hover { background: #1a2744; }
        pre { background: #0d1b2a; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style></head><body>";
    echo "<h1>🔧 SLA Diagnose & Fix</h1>";

    // Step 1: Cek tabel sla_configs ada atau tidak
    echo "<div class='step'>";
    echo "<strong>Step 1:</strong> Cek tabel sla_configs... ";
    $tableExists = Schema::hasTable('sla_configs');
    if ($tableExists) {
        echo "<span class='ok'>✅ ADA</span>";
    } else {
        echo "<span class='err'>❌ TIDAK ADA</span>";
    }
    echo "</div>";

    // Step 2: Jalankan migration jika tabel belum ada
    if (!$tableExists) {
        echo "<div class='step warning'>";
        echo "<strong>Step 2:</strong> Menjalankan migration... ";
        try {
            $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
            $kernel->call('migrate', ['--force' => true]);
            echo "<span class='ok'>✅ Migration berhasil</span>";
            echo "<pre>" . Artisan::output() . "</pre>";
            $tableExists = Schema::hasTable('sla_configs');
        } catch (\Exception $e) {
            echo "<span class='err'>❌ GAGAL: " . htmlspecialchars($e->getMessage()) . "</span>";
        }
        echo "</div>";
    }

    if ($tableExists) {
        // Step 3: Cek kolom
        echo "<div class='step'>";
        echo "<strong>Step 3:</strong> Cek struktur kolom...<br>";
        $columns = Schema::getColumnListing('sla_configs');
        echo "Kolom yang ada: <code>" . implode(', ', $columns) . "</code><br>";
        
        $hasPriority = in_array('priority', $columns);
        $hasTier = in_array('tier', $columns);
        
        if ($hasPriority) {
            echo "<span class='ok'>✅ Kolom 'priority' sudah ada (migration terbaru sudah jalan)</span>";
            
            // Cleanup data lama yang priority-nya kosong/blank
            $deletedCount = DB::table('sla_configs')->where('priority', '')->orWhereNull('priority')->delete();
            if ($deletedCount > 0) {
                echo "<br><span class='warn'>⚠️ Membersihkan {$deletedCount} data lama yang rusak (priority kosong)</span>";
            }
            
        } elseif ($hasTier) {
            echo "<span class='warn'>⚠️ Kolom 'tier' masih ada, kolom 'priority' belum ada</span><br>";
            echo "Menjalankan migration pending...";
            try {
                $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
                $kernel->call('migrate', ['--force' => true]);
                echo "<br><span class='ok'>✅ Migration berhasil</span>";
                echo "<pre>" . Artisan::output() . "</pre>";
                $hasPriority = Schema::hasColumn('sla_configs', 'priority');
            } catch (\Exception $e) {
                echo "<br><span class='err'>❌ Migration GAGAL: " . htmlspecialchars($e->getMessage()) . "</span>";
            }
        } else {
            echo "<span class='err'>❌ Kolom 'priority' dan 'tier' tidak ditemukan — struktur tabel rusak</span>";
        }
        echo "</div>";

        // Step 4: Cek data global
        echo "<div class='step'>";
        echo "<strong>Step 4:</strong> Cek data default global...<br>";
        
        if ($hasPriority) {
            $globalCount = DB::table('sla_configs')->whereNull('sub_unit_id')->count();
            echo "Data global saat ini: <strong>{$globalCount} baris</strong><br>";

            if ($globalCount === 0) {
                echo "<span class='warn'>⚠️ Tidak ada data global. Membuat default...</span><br>";
                $defaults = [
                    ['priority' => 'Rendah',  'jenis' => 'respon',       'threshold_minutes' => 60],
                    ['priority' => 'Sedang',  'jenis' => 'respon',       'threshold_minutes' => 60],
                    ['priority' => 'Tinggi',  'jenis' => 'respon',       'threshold_minutes' => 7],
                    ['priority' => 'Urgen',   'jenis' => 'respon',       'threshold_minutes' => 1],
                    ['priority' => 'Rendah',  'jenis' => 'penyelesaian', 'threshold_minutes' => 60],
                    ['priority' => 'Sedang',  'jenis' => 'penyelesaian', 'threshold_minutes' => 60],
                    ['priority' => 'Tinggi',  'jenis' => 'penyelesaian', 'threshold_minutes' => 60],
                    ['priority' => 'Urgen',   'jenis' => 'penyelesaian', 'threshold_minutes' => 60],
                ];
                foreach ($defaults as $d) {
                    DB::table('sla_configs')->insert([
                        'sub_unit_id' => null,
                        'priority' => $d['priority'],
                        'jenis' => $d['jenis'],
                        'threshold_minutes' => $d['threshold_minutes'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                echo "<span class='ok'>✅ 8 baris data default global berhasil dibuat!</span>";
            } else if ($globalCount < 8) {
                echo "<span class='warn'>⚠️ Data global kurang dari 8 (seharusnya 4 priority × 2 jenis = 8). Melengkapi...</span><br>";
                $priorities = ['Rendah', 'Sedang', 'Tinggi', 'Urgen'];
                $jenisTypes = ['respon', 'penyelesaian'];
                $added = 0;
                foreach ($jenisTypes as $jenis) {
                    foreach ($priorities as $priority) {
                        $exists = DB::table('sla_configs')
                            ->whereNull('sub_unit_id')
                            ->where('priority', $priority)
                            ->where('jenis', $jenis)
                            ->exists();
                        if (!$exists) {
                            DB::table('sla_configs')->insert([
                                'sub_unit_id' => null,
                                'priority' => $priority,
                                'jenis' => $jenis,
                                'threshold_minutes' => 60,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $added++;
                        }
                    }
                }
                echo "<span class='ok'>✅ {$added} baris ditambahkan. Total sekarang: " . DB::table('sla_configs')->whereNull('sub_unit_id')->count() . "</span>";
            } else {
                echo "<span class='ok'>✅ Data global lengkap ({$globalCount} baris)</span>";
            }
        } else {
            echo "<span class='err'>❌ Kolom 'priority' tidak ada — tidak bisa seed data</span>";
        }
        echo "</div>";

        // Step 5: Tampilkan data saat ini
        echo "<div class='step success'>";
        echo "<strong>Step 5:</strong> Data SLA saat ini:<br>";
        if ($hasPriority) {
            $allConfigs = DB::table('sla_configs')->orderBy('sub_unit_id')->orderBy('jenis')->orderBy('priority')->get();
            echo "<pre>";
            echo str_pad('ID', 6) . str_pad('SubUnit', 10) . str_pad('Priority', 12) . str_pad('Jenis', 16) . "Menit\n";
            echo str_repeat('-', 55) . "\n";
            foreach ($allConfigs as $c) {
                echo str_pad($c->id, 6) . str_pad($c->sub_unit_id ?? 'Global', 10) . str_pad($c->priority, 12) . str_pad($c->jenis, 16) . $c->threshold_minutes . "\n";
            }
            echo "</pre>";
        }
        echo "</div>";
    }

    // Step 6: Clear cache
    echo "<div class='step'>";
    echo "<strong>Step 6:</strong> Clear cache... ";
    try {
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->call('optimize:clear');
        echo "<span class='ok'>✅ Cache dibersihkan</span>";
    } catch (\Exception $e) {
        echo "<span class='warn'>⚠️ " . htmlspecialchars($e->getMessage()) . "</span>";
    }
    echo "</div>";

    echo "<br><p class='ok'><strong>Selesai!</strong> Sekarang coba buka halaman <a href='/admin/sla-config' style='color:#40c4ff'>Konfigurasi SLA</a> dan ubah nilainya.</p>";
    echo "<a class='back' href='{$baseUrl}?key={$key}'>← Kembali ke Menu</a>";
    echo "</body></html>";
    exit;
}

// ==============================
// Command khusus: copy storage (pengganti symlink kalau symlink() diblokir)
// ==============================
if ($command === 'fix-storage-copy') {
    $source = __DIR__ . '/../storage/app/public';
    $dest = __DIR__ . '/storage';

    if (!is_dir($source)) {
        die("<pre>❌ Folder source tidak ditemukan: $source\nPastikan folder storage/app/public ada.</pre>");
    }

    if (!file_exists($dest)) {
        mkdir($dest, 0755, true);
    }

    function copyDirRecursive($src, $dst) {
        $count = 0;
        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $srcPath = "$src/$file";
            $dstPath = "$dst/$file";
            if (is_dir($srcPath)) {
                if (!file_exists($dstPath)) {
                    mkdir($dstPath, 0755, true);
                }
                $count += copyDirRecursive($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
                $count++;
            }
        }
        closedir($dir);
        return $count;
    }

    $total = copyDirRecursive($source, $dest);
    echo "<pre>✅ Selesai! Total $total file di-copy.\n\nDari: $source\nKe:   $dest\n\nImage seharusnya sudah bisa diakses sekarang.</pre>";
    exit;
}

// ==============================
// Command khusus: coba buat symlink asli dulu
// ==============================
if ($command === 'try-symlink') {
    $target = __DIR__ . '/../storage/app/public';
    $link = __DIR__ . '/storage';

    echo "<pre>";
    
    if (is_link($link)) {
        echo "✅ Symlink sudah ada: $link -> " . readlink($link) . "\n";
        exit;
    }
    
    if (is_dir($link) && !is_link($link)) {
        echo "⚠️ Folder storage/ sudah ada sebagai folder biasa (bukan symlink).\n";
        echo "Ini mungkin hasil dari fix-storage-copy sebelumnya.\n";
        echo "Kalau mau ganti ke symlink, hapus dulu folder public/storage/ secara manual.\n";
        exit;
    }

    if (!function_exists('symlink')) {
        die("❌ Fungsi symlink() tidak tersedia di server ini (disabled).\nGunakan fix-storage-copy sebagai alternatif.");
    }

    if (!is_dir($target)) {
        @mkdir($target, 0755, true);
        echo "📁 Created: $target\n";
    }

    $result = @symlink($target, $link);
    if ($result) {
        echo "✅ Symlink berhasil dibuat!\n$link -> $target\n\nImage seharusnya sudah bisa diakses sekarang.";
    } else {
        $error = error_get_last();
        echo "❌ Symlink GAGAL.\nError: " . ($error['message'] ?? 'Unknown error') . "\n\n";
        echo "Gunakan fix-storage-copy sebagai alternatif.";
    }
    echo "</pre>";
    exit;
}

// ==============================
// Command khusus: Generate Ziggy (manual karena di luar console)
// ==============================
if ($command === 'ziggy:generate') {
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    echo "<pre>=== Generate Ziggy Routes ===\n\n";
    try {
        if (!class_exists(\Tighten\Ziggy\Ziggy::class)) {
            throw new \Exception("Class Tighten\Ziggy\Ziggy tidak ditemukan.");
        }
        $ziggy = new \Tighten\Ziggy\Ziggy();
        $output = new \Tighten\Ziggy\Output\File($ziggy);
        $path = __DIR__ . '/../resources/js/ziggy.js';
        
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, (string) $output);
        
        echo "✅ BERHASIL! File ziggy.js telah diupdate.\n";
        echo "Path: " . realpath($path) . "\n\n";
        echo "Silakan Refresh halaman web Anda.";
    } catch (\Exception $e) {
        echo "❌ GAGAL: " . $e->getMessage() . "\n";
    }
    echo "</pre>";
    exit;
}

// Command Artisan standar
// ==============================
$allowed = [
    'config:clear',
    'cache:clear',
    'route:clear',
    'view:clear',
    'config:cache',
    'route:cache',
    'view:cache',
    'migrate --force',
    'storage:link',
    'optimize:clear',
    'key:generate --force',
    'ziggy:generate',
    'deploy',
    'deploy-cpanel',
    'schedule:run',
    'sla:check',
    'simulate:sla-and-reminders',
    'sla-view',
    'sla-update',
    'sla-diagnose',
    'npm-build',
    'package:discover',
    'seed-permissions'
];

if (!in_array($command, $allowed)) {
    die('Command tidak diizinkan. Akses tanpa parameter cmd untuk lihat menu.');
}

// Boot Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

if ($command === 'deploy') {
    echo "<pre>=== Memulai Deployment ===\n\n";
    try {
        echo "1. Menjalankan migrate --force...\n";
        $kernel->call('migrate', ['--force' => true]);
        echo Artisan::output() . "\n";
        
        echo "2. Menjalankan optimize:clear...\n";
        $kernel->call('optimize:clear');
        echo Artisan::output() . "\n";
        
        echo "✅ Deployment Selesai!\n";
    } catch (\Throwable $e) {
        echo "❌ Error: " . htmlspecialchars($e->getMessage()) . "\n\n";
    }
    echo "</pre>";
    exit;
}

if ($command === 'npm-build') {
    echo "<pre>=== Menjalankan npm run build ===\n\n";
    $output = [];
    $return_var = 0;
    
    // Gunakan direktori root proyek
    $basePath = realpath(__DIR__ . '/..');
    
    // Coba deteksi npm
    $npmPath = trim(shell_exec('which npm 2>/dev/null') ?? '');
    if (!$npmPath) {
        $npmPath = 'npm'; // fallback
    }
    
    $cmd = "cd " . escapeshellarg($basePath) . " && $npmPath run build 2>&1";
    echo "Command: $cmd\n\n";
    
    exec($cmd, $output, $return_var);
    
    echo implode("\n", $output);
    echo "\n\nExit Code: $return_var";
    if ($return_var !== 0) {
        echo "\n\nCatatan: Jika error 'npm: command not found', server Anda (shared hosting) mungkin tidak menginstal Node.js.\n";
        echo "Solusi: Build (npm run build) di lokal komputer Anda, lalu upload ulang seluruh isi folder 'public/build' ke server.";
    }
    echo "</pre>";
    exit;
}

if ($command === 'seed-permissions') {
    echo "<pre>=== Perbaiki Hak Akses Sidebar ===\n\n";
    try {
        $kernel->call('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);
        echo "✅ Hak akses berhasil diperbaiki (Seeded)!\n";
        echo Artisan::output();
        
        // Clear cache so frontend picks up new permissions immediately
        $kernel->call('cache:clear');
        echo "\n✅ Cache dibersihkan!\n";
    } catch (\Throwable $e) {
        echo "❌ Error: " . htmlspecialchars($e->getMessage()) . "\n";
    }
    echo "</pre>";
    exit;
}

if ($command === 'deploy-cpanel') {
    echo "<pre>=== Memulai Deployment ke cPanel ===\n\n";
    try {
        echo "0. Memperbarui konfigurasi .env untuk Push Notification...\n";
        $envPath = __DIR__ . '/../.env';
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);
            if (!str_contains($envContent, 'VAPID_PUBLIC_KEY')) {
                $vapidKeys = "\n\n# Web Push Notifications\n";
                $vapidKeys .= "VAPID_PUBLIC_KEY=BPBkAYR05euwJRFbfWaqMYtdGshrYOSm3a3jIcQY16_9jV7dHf8f1q5MGksolLx3evAfKvPHs5N_GiqfM5Llj8g\n";
                $vapidKeys .= "VAPID_PRIVATE_KEY=accagDJbsxklfPUv8npLj7HPPHOlYz4h9XMLANCGaWA\n";
                $vapidKeys .= "VITE_VAPID_PUBLIC_KEY=BPBkAYR05euwJRFbfWaqMYtdGshrYOSm3a3jIcQY16_9jV7dHf8f1q5MGksolLx3evAfKvPHs5N_GiqfM5Llj8g\n";
                file_put_contents($envPath, $vapidKeys, FILE_APPEND);
                echo "✅ VAPID keys berhasil ditambahkan ke .env\n\n";
            } else {
                echo "⚡ VAPID keys sudah ada di .env (skip)\n\n";
            }
        }

        echo "1. Mengaktifkan Maintenance Mode...\n";
        $kernel->call('down');
        echo Artisan::output() . "\n";
        
        echo "2. Clear Cache, Optimize & Discover Packages...\n";
        $kernel->call('optimize:clear');
        echo Artisan::output() . "\n";
        $kernel->call('package:discover');
        echo Artisan::output() . "\n";
        
        echo "3. Menjalankan Migrasi Database...\n";
        $kernel->call('migrate', ['--force' => true]);
        echo Artisan::output() . "\n";
        
        echo "4. Caching Ulang (Config & Route)...\n";
        $kernel->call('config:cache');
        $kernel->call('route:cache');
        $kernel->call('view:cache');
        echo "Cache berhasil dibangun.\n\n";
        
        echo "5. Membuat Storage Link (jika belum ada)...\n";
        $kernel->call('storage:link');
        echo Artisan::output() . "\n";

        echo "6. Mematikan Maintenance Mode...\n";
        $kernel->call('up');
        echo Artisan::output() . "\n";
        
        echo "✅ Deployment cPanel Selesai!\n";
    } catch (\Throwable $e) {
        echo "❌ Error: " . htmlspecialchars($e->getMessage()) . "\n\n";
        // Force UP just in case
        $kernel->call('up');
    }
    echo "</pre>";
    exit;
}

// Parse command
$parts = explode(' ', $command);
$artisanCmd = $parts[0];
$params = [];
foreach (array_slice($parts, 1) as $p) {
    if (str_starts_with($p, '--')) {
        $params[$p] = true;
    }
}

try {
    $exitCode = $kernel->call($artisanCmd, $params);
    $output = Artisan::output();
    echo "<pre>";
    echo "Command: $command\n";
    echo "Exit code: $exitCode\n\n";
    echo htmlspecialchars($output ?: '(no output)');
    echo "</pre>";
} catch (\Throwable $e) {
    echo "<pre>❌ Error: " . htmlspecialchars($e->getMessage()) . "\n\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "</pre>";
}

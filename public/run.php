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
    $cmds = ['optimize:clear', 'config:clear', 'cache:clear', 'route:clear', 'view:clear', 'config:cache', 'route:cache', 'view:cache', 'storage:link', 'migrate --force', 'key:generate --force', 'ziggy:generate'];
    foreach ($cmds as $c) {
        $label = $c;
        echo "<a href='{$baseUrl}?key={$key}&cmd=" . urlencode($c) . "'>▶ {$label}</a>";
    }
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

// ==============================
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
];

if (!in_array($command, $allowed)) {
    die('Command tidak diizinkan. Akses tanpa parameter cmd untuk lihat menu.');
}

// Boot Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

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

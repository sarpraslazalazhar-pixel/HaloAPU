<?php
$pngPath = dirname(__DIR__) . '/public/images/bg-login.png';
$webpPath = dirname(__DIR__) . '/public/images/bg-login.webp';

if (!file_exists($pngPath)) {
    echo "PNG not found: $pngPath\n";
    exit(1);
}

if (!function_exists('imagecreatefrompng') || !function_exists('imagewebp')) {
    echo "GD / imagewebp not available in this PHP build\n";
    exit(1);
}

$img = imagecreatefrompng($pngPath);
if (!$img) {
    echo "Failed to load PNG image\n";
    exit(1);
}

imagepalettetotruecolor($img);
imagealphablending($img, true);
imagesavealpha($img, true);

$result = imagewebp($img, $webpPath, 82);
imagedestroy($img);

if ($result && file_exists($webpPath)) {
    echo "SUCCESS: " . filesize($pngPath) . " -> " . filesize($webpPath) . " bytes\n";
} else {
    echo "FAILED\n";
    exit(1);
}

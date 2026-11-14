<?php

// Deterministic icon export: preserve the supplied artwork; resize for browser/OS icons.
$root = dirname(__DIR__);
$directory = $root.'/public/images/brand';
$original = $directory.'/siem-salabim-official-source.png';
$source = $argv[1] ?? $original;
if (! extension_loaded('gd') || ! is_file($source)) {
    throw new RuntimeException('PNG source and PHP GD are required.');
}
if (realpath($source) !== realpath($original) && ! copy($source, $original)) {
    throw new RuntimeException('Cannot copy official logo source.');
}
$image = imagecreatefrompng($original);
if ($image === false) {
    throw new RuntimeException('Cannot read official PNG logo.');
}
$width = imagesx($image);
$height = imagesy($image);
$bounds = [$width, $height, -1, -1];
for ($y = 0; $y < $height; $y++) {
    for ($x = 0; $x < $width; $x++) {
        if (((imagecolorat($image, $x, $y) >> 24) & 127) < 127) {
            $bounds = [min($bounds[0], $x), min($bounds[1], $y), max($bounds[2], $x), max($bounds[3], $y)];
        }
    }
}
if ($bounds[2] < 0) {
    throw new RuntimeException('The logo is completely transparent.');
}
$sourceWidth = $bounds[2] - $bounds[0] + 1;
$sourceHeight = $bounds[3] - $bounds[1] + 1;
$exports = [16 => 'favicon-16.png', 32 => 'favicon-32.png', 180 => 'apple-touch-icon.png', 192 => 'icon-192.png', 512 => 'siem-salabim-logo.png'];
$encoded = [];
foreach ([16, 32, 48, 64, 180, 192, 256, 512] as $size) {
    $canvas = imagecreatetruecolor($size, $size);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 32, 42, 46));
    $scale = ($size * 0.92) / max($sourceWidth, $sourceHeight);
    $targetWidth = max(1, (int) round($sourceWidth * $scale));
    $targetHeight = max(1, (int) round($sourceHeight * $scale));
    imagecopyresampled($canvas, $image, (int) (($size - $targetWidth) / 2), (int) (($size - $targetHeight) / 2), $bounds[0], $bounds[1], $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    ob_start();
    imagepng($canvas, null, 9);
    $encoded[$size] = ob_get_clean();
    if (isset($exports[$size])) {
        file_put_contents($directory.'/'.$exports[$size], $encoded[$size]);
    }
    imagedestroy($canvas);
}
imagedestroy($image);

$sizes = [16, 32, 48, 64, 256];
$icon = pack('vvv', 0, 1, count($sizes));
$offset = 6 + count($sizes) * 16;
foreach ($sizes as $size) {
    $icon .= pack('CCCCvvVV', $size === 256 ? 0 : $size, $size === 256 ? 0 : $size, 0, 0, 1, 32, strlen($encoded[$size]), $offset);
    $offset += strlen($encoded[$size]);
}
foreach ($sizes as $size) {
    $icon .= $encoded[$size];
}
file_put_contents($root.'/public/favicon.ico', $icon);
echo "Official logo and browser icons exported.\n";

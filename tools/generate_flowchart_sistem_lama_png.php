<?php

declare(strict_types=1);

if (! extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$output = $root.'/docs/flowchart-sistem-lama-gudang.png';

$width = 1800;
$height = 1500;

$image = imagecreatetruecolor($width, $height);
imagealphablending($image, true);
imagesavealpha($image, true);

$rgb = [
    'bg' => [255, 255, 255],
    'title' => [15, 23, 42],
    'line' => [17, 24, 39],
    'muted' => [71, 85, 105],
    'header' => [21, 94, 117],
    'lane' => [248, 250, 252],
    'box' => [255, 255, 255],
    'diamond' => [255, 247, 237],
    'orange' => [249, 115, 22],
    'shadow' => [203, 213, 225],
];

$c = [];
foreach ($rgb as $name => $value) {
    $c[$name] = imagecolorallocate($image, ...$value);
}

imagefilledrectangle($image, 0, 0, $width, $height, $c['bg']);

$fontRegular = firstExistingFont([
    'C:/Windows/Fonts/segoeui.ttf',
    'C:/Windows/Fonts/arial.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
]);
$fontBold = firstExistingFont([
    'C:/Windows/Fonts/segoeuib.ttf',
    'C:/Windows/Fonts/arialbd.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
]);

// Header
imagefilledrectangle($image, 40, 30, $width - 40, 105, $c['header']);
drawCenteredText($image, $fontBold, 26, 40, $width - 80, 78, 'Flowchart Sistem Lama Pengelolaan Gudang PT ISS', imagecolorallocate($image, 255, 255, 255));

$laneTop = 105;
$laneBottom = 1440;
$laneWidth = (int) (($width - 80) / 3);
$x1 = 40;
$x2 = $x1 + $laneWidth;
$x3 = $x2 + $laneWidth;

drawLane($image, $fontBold, $x1, $laneTop, $laneWidth, $laneBottom, 'Admin Gudang', $c);
drawLane($image, $fontBold, $x2, $laneTop, $laneWidth, $laneBottom, 'Sistem Lama / Microsoft Excel', $c);
drawLane($image, $fontBold, $x3, $laneTop, $laneWidth, $laneBottom, 'Pimpinan', $c);

// Admin nodes
$start = terminator($image, $fontBold, $x1 + 205, 170, 190, 58, 'Mulai', $c);
$a1 = documentBox($image, $fontRegular, $x1 + 145, 280, 310, 86, 'Form kertas\naktivitas gudang', $c);
$a2 = inputOutputBox($image, $fontRegular, $x1 + 145, 430, 310, 92, 'Input data barang masuk,\nbarang keluar, dan stok', $c);
$a3 = diamond($image, $fontRegular, $x1 + 300, 640, 220, 96, 'Data sudah\nvalid?', $c);
$a4 = processBox($image, $fontRegular, $x1 + 145, 760, 310, 78, 'Koreksi data\nsecara manual', $c);
$a5 = documentBox($image, $fontRegular, $x1 + 145, 930, 310, 86, 'Membuat laporan\ngudang', $c);

// Excel nodes
$s1 = dataStore($image, $fontRegular, $x2 + 145, 430, 310, 92, 'File Excel\nmingguan', $c);
$s2 = processBox($image, $fontRegular, $x2 + 145, 585, 310, 86, 'Hitung stok menggunakan\nrumus Excel', $c);
$s3 = dataStore($image, $fontRegular, $x2 + 145, 760, 310, 92, 'File Excel\nbulanan', $c);
$s4 = dataStore($image, $fontRegular, $x2 + 145, 935, 310, 92, 'Arsip laporan\nExcel', $c);

// Pimpinan nodes
$p1 = documentBox($image, $fontRegular, $x3 + 145, 800, 310, 86, 'Menerima / membuka\nlaporan Excel', $c);
$p2 = inputOutputBox($image, $fontRegular, $x3 + 145, 940, 310, 86, 'Membaca informasi stok\ndan pergerakan barang', $c);
$p3 = diamond($image, $fontRegular, $x3 + 300, 1090, 220, 92, 'Perlu informasi\natau restock?', $c);
$end = terminator($image, $fontBold, $x3 + 205, 1370, 190, 58, 'Selesai', $c);

// Optional/manual restock branch kept compact inside right lane.
$p4 = processBox($image, $fontRegular, $x3 + 145, 1175, 310, 78, 'Mencari data manual\ndi file Excel', $c);
$p5 = processBox($image, $fontRegular, $x3 + 145, 1275, 310, 78, 'Menentukan prioritas restock\nsecara manual', $c);

// Arrows
arrow($image, bottom($start), top($a1), '', $fontRegular, $c);
arrow($image, bottom($a1), top($a2), '', $fontRegular, $c);
arrow($image, right($a2), left($s1), 'Data aktivitas gudang', $fontRegular, $c);
arrow($image, bottom($s1), top($s2), '', $fontRegular, $c);
arrow($image, left($s2), right($a3), 'Hasil perhitungan stok', $fontRegular, $c);
arrow($image, bottom($a3), top($a4), 'Tidak', $fontRegular, $c);
arrow($image, right($a3), left($s3), 'Ya', $fontRegular, $c);
arrow($image, right($a4), left($s3), 'Setelah koreksi', $fontRegular, $c);
arrow($image, bottom($s3), top($s4), '', $fontRegular, $c);
arrow($image, left($s4), right($a5), 'Arsip laporan', $fontRegular, $c);
arrow($image, right($a5), left($p1), 'Laporan Excel', $fontRegular, $c);
arrow($image, bottom($p1), top($p2), '', $fontRegular, $c);
arrow($image, bottom($p2), top($p3), '', $fontRegular, $c);
arrow($image, bottom($p3), top($p4), 'Ya', $fontRegular, $c);
arrow($image, bottom($p4), top($p5), '', $fontRegular, $c);
arrow($image, [$p5['x'] + 140, $p5['y'] + $p5['h']], [$end['x'] + 95, $end['y']], '', $fontRegular, $c);
polyArrow($image, [
    [$p3['x'], $p3['y'] + (int) ($p3['h'] / 2)],
    [$x3 + 85, $p3['y'] + (int) ($p3['h'] / 2)],
    [$x3 + 85, $end['y'] + 29],
    left($end),
], 'Tidak', $fontRegular, $c);

imagepng($image, $output, 9);
imagedestroy($image);

echo str_replace('\\', '/', $output).PHP_EOL;

function firstExistingFont(array $paths): ?string
{
    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function drawLane(GdImage $image, ?string $font, int $x, int $top, int $w, int $bottom, string $title, array $c): void
{
    imagefilledrectangle($image, $x, $top, $x + $w, $bottom, $c['lane']);
    imagerectangle($image, $x, $top, $x + $w, $bottom, $c['line']);
    imagefilledrectangle($image, $x, $top, $x + $w, $top + 70, $c['box']);
    imagerectangle($image, $x, $top, $x + $w, $top + 70, $c['line']);
    drawCenteredText($image, $font, 18, $x, $w, $top + 45, $title, $c['title']);
}

function processBox(GdImage $image, ?string $font, int $x, int $y, int $w, int $h, string $text, array $c): array
{
    imagefilledrectangle($image, $x + 5, $y + 6, $x + $w + 5, $y + $h + 6, $c['shadow']);
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $c['box']);
    imagerectangle($image, $x, $y, $x + $w, $y + $h, $c['line']);
    drawCenteredMultiline($image, $font, 15, $x, $y, $w, $h, $text, $c['title']);

    return compact('x', 'y', 'w', 'h');
}

function inputOutputBox(GdImage $image, ?string $font, int $x, int $y, int $w, int $h, string $text, array $c): array
{
    $slant = 34;
    $shadow = [
        $x + $slant + 5, $y + 6,
        $x + $w + 5, $y + 6,
        $x + $w - $slant + 5, $y + $h + 6,
        $x + 5, $y + $h + 6,
    ];
    $points = [
        $x + $slant, $y,
        $x + $w, $y,
        $x + $w - $slant, $y + $h,
        $x, $y + $h,
    ];

    imagefilledpolygon($image, $shadow, $c['shadow']);
    imagefilledpolygon($image, $points, $c['box']);
    imagepolygon($image, $points, $c['line']);
    drawCenteredMultiline($image, $font, 15, $x + 12, $y, $w - 24, $h, $text, $c['title']);

    return compact('x', 'y', 'w', 'h');
}

function documentBox(GdImage $image, ?string $font, int $x, int $y, int $w, int $h, string $text, array $c): array
{
    imagefilledrectangle($image, $x + 5, $y + 6, $x + $w + 5, $y + $h + 6, $c['shadow']);

    $waveHeight = 13;
    $points = [
        $x, $y,
        $x + $w, $y,
        $x + $w, $y + $h - $waveHeight,
        $x + (int) ($w * 0.76), $y + $h,
        $x + (int) ($w * 0.50), $y + $h - $waveHeight,
        $x + (int) ($w * 0.24), $y + $h,
        $x, $y + $h - $waveHeight,
    ];

    imagefilledpolygon($image, $points, $c['box']);
    imageline($image, $x, $y, $x + $w, $y, $c['line']);
    imageline($image, $x + $w, $y, $x + $w, $y + $h - $waveHeight, $c['line']);
    imageline($image, $x + $w, $y + $h - $waveHeight, $x + (int) ($w * 0.76), $y + $h, $c['line']);
    imageline($image, $x + (int) ($w * 0.76), $y + $h, $x + (int) ($w * 0.50), $y + $h - $waveHeight, $c['line']);
    imageline($image, $x + (int) ($w * 0.50), $y + $h - $waveHeight, $x + (int) ($w * 0.24), $y + $h, $c['line']);
    imageline($image, $x + (int) ($w * 0.24), $y + $h, $x, $y + $h - $waveHeight, $c['line']);
    imageline($image, $x, $y + $h - $waveHeight, $x, $y, $c['line']);
    drawCenteredMultiline($image, $font, 15, $x, $y, $w, $h - 8, $text, $c['title']);

    return compact('x', 'y', 'w', 'h');
}

function dataStore(GdImage $image, ?string $font, int $x, int $y, int $w, int $h, string $text, array $c): array
{
    $ellipseH = 28;
    imagefilledrectangle($image, $x + 5, $y + (int) ($ellipseH / 2) + 6, $x + $w + 5, $y + $h - (int) ($ellipseH / 2) + 6, $c['shadow']);
    imagefilledellipse($image, $x + (int) ($w / 2) + 5, $y + (int) ($ellipseH / 2) + 6, $w, $ellipseH, $c['shadow']);
    imagefilledellipse($image, $x + (int) ($w / 2) + 5, $y + $h - (int) ($ellipseH / 2) + 6, $w, $ellipseH, $c['shadow']);

    imagefilledrectangle($image, $x, $y + (int) ($ellipseH / 2), $x + $w, $y + $h - (int) ($ellipseH / 2), $c['box']);
    imagefilledellipse($image, $x + (int) ($w / 2), $y + (int) ($ellipseH / 2), $w, $ellipseH, $c['box']);
    imagefilledellipse($image, $x + (int) ($w / 2), $y + $h - (int) ($ellipseH / 2), $w, $ellipseH, $c['box']);

    imageline($image, $x, $y + (int) ($ellipseH / 2), $x, $y + $h - (int) ($ellipseH / 2), $c['line']);
    imageline($image, $x + $w, $y + (int) ($ellipseH / 2), $x + $w, $y + $h - (int) ($ellipseH / 2), $c['line']);
    imageellipse($image, $x + (int) ($w / 2), $y + (int) ($ellipseH / 2), $w, $ellipseH, $c['line']);
    imagearc($image, $x + (int) ($w / 2), $y + $h - (int) ($ellipseH / 2), $w, $ellipseH, 0, 180, $c['line']);
    imagearc($image, $x + (int) ($w / 2), $y + $h - (int) ($ellipseH / 2), $w, $ellipseH, 180, 360, $c['line']);
    drawCenteredMultiline($image, $font, 15, $x, $y + 5, $w, $h - 5, $text, $c['title']);

    return compact('x', 'y', 'w', 'h');
}

function terminator(GdImage $image, ?string $font, int $x, int $y, int $w, int $h, string $text, array $c): array
{
    imagefilledellipse($image, $x + (int) ($w / 2) + 4, $y + (int) ($h / 2) + 5, $w, $h, $c['shadow']);
    imagefilledellipse($image, $x + (int) ($w / 2), $y + (int) ($h / 2), $w, $h, $c['box']);
    imageellipse($image, $x + (int) ($w / 2), $y + (int) ($h / 2), $w, $h, $c['line']);
    drawCenteredText($image, $font, 16, $x, $w, $y + 37, $text, $c['title']);

    return compact('x', 'y', 'w', 'h');
}

function diamond(GdImage $image, ?string $font, int $cx, int $cy, int $w, int $h, string $text, array $c): array
{
    $points = [$cx, $cy - (int) ($h / 2), $cx + (int) ($w / 2), $cy, $cx, $cy + (int) ($h / 2), $cx - (int) ($w / 2), $cy];
    imagefilledpolygon($image, [$points[0] + 5, $points[1] + 6, $points[2] + 5, $points[3] + 6, $points[4] + 5, $points[5] + 6, $points[6] + 5, $points[7] + 6], $c['shadow']);
    imagefilledpolygon($image, $points, $c['diamond']);
    imagepolygon($image, $points, $c['orange']);
    drawCenteredMultiline($image, $font, 14, $cx - (int) ($w * 0.34), $cy - (int) ($h * 0.25), (int) ($w * 0.68), (int) ($h * 0.5), $text, $c['title']);

    return ['x' => $cx - (int) ($w / 2), 'y' => $cy - (int) ($h / 2), 'w' => $w, 'h' => $h];
}

function arrow(GdImage $image, array $from, array $to, string $label, ?string $font, array $c): void
{
    imagesetthickness($image, 3);
    imageline($image, $from[0], $from[1], $to[0], $to[1], $c['line']);
    imagesetthickness($image, 1);
    arrowHead($image, $from, $to, $c['line']);

    if ($label !== '') {
        $x = (int) (($from[0] + $to[0]) / 2);
        $y = (int) (($from[1] + $to[1]) / 2) - 8;
        imagefilledrectangle($image, $x - 80, $y - 18, $x + 80, $y + 5, $c['lane']);
        drawCenteredText($image, $font, 12, $x - 80, 160, $y, $label, $c['muted']);
    }
}

function polyArrow(GdImage $image, array $points, string $label, ?string $font, array $c): void
{
    imagesetthickness($image, 3);
    for ($i = 0; $i < count($points) - 1; $i++) {
        imageline($image, $points[$i][0], $points[$i][1], $points[$i + 1][0], $points[$i + 1][1], $c['line']);
    }
    imagesetthickness($image, 1);
    arrowHead($image, $points[count($points) - 2], $points[count($points) - 1], $c['line']);

    if ($label !== '') {
        $p = $points[1];
        imagefilledrectangle($image, $p[0] - 36, $p[1] - 26, $p[0] + 36, $p[1] - 4, $c['lane']);
        drawCenteredText($image, $font, 12, $p[0] - 36, 72, $p[1] - 9, $label, $c['muted']);
    }
}

function arrowHead(GdImage $image, array $from, array $to, int $color): void
{
    $angle = atan2($to[1] - $from[1], $to[0] - $from[0]);
    $length = 13;
    $spread = 0.45;
    $p1 = [
        (int) round($to[0] - $length * cos($angle - $spread)),
        (int) round($to[1] - $length * sin($angle - $spread)),
    ];
    $p2 = [
        (int) round($to[0] - $length * cos($angle + $spread)),
        (int) round($to[1] - $length * sin($angle + $spread)),
    ];
    imagefilledpolygon($image, [$to[0], $to[1], $p1[0], $p1[1], $p2[0], $p2[1]], $color);
}

function left(array $box): array
{
    return [$box['x'], $box['y'] + (int) ($box['h'] / 2)];
}

function right(array $box): array
{
    return [$box['x'] + $box['w'], $box['y'] + (int) ($box['h'] / 2)];
}

function top(array $box): array
{
    return [$box['x'] + (int) ($box['w'] / 2), $box['y']];
}

function bottom(array $box): array
{
    return [$box['x'] + (int) ($box['w'] / 2), $box['y'] + $box['h']];
}

function drawCenteredMultiline(GdImage $image, ?string $font, int $size, int $x, int $y, int $w, int $h, string $text, int $color): void
{
    $lines = explode('\n', $text);
    $lineHeight = $size + 7;
    $totalHeight = count($lines) * $lineHeight;
    $startY = (int) ($y + ($h - $totalHeight) / 2 + $size + 1);
    foreach ($lines as $i => $line) {
        drawCenteredText($image, $font, $size, $x, $w, $startY + ($i * $lineHeight), $line, $color);
    }
}

function drawCenteredText(GdImage $image, ?string $font, int $size, int $x, int $w, int $y, string $text, int $color): void
{
    $textWidth = textWidth($font, $size, $text);
    drawText($image, $font, $size, (int) ($x + ($w - $textWidth) / 2), $y, $text, $color);
}

function drawText(GdImage $image, ?string $font, int $size, int $x, int $y, string $text, int $color): void
{
    if ($font !== null && function_exists('imagettftext')) {
        imagettftext($image, $size, 0, $x, $y, $color, $font, $text);

        return;
    }
    imagestring($image, 5, $x, $y - $size, $text, $color);
}

function textWidth(?string $font, int $size, string $text): int
{
    if ($font !== null && function_exists('imagettfbbox')) {
        $box = imagettfbbox($size, 0, $font, $text);
        return abs(($box[2] ?? 0) - ($box[0] ?? 0));
    }

    return strlen($text) * imagefontwidth(5);
}

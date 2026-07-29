<?php

declare(strict_types=1);

if (! extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$output = $root.'/docs/dfd-level-2-spk-restock.png';

$width = 1760;
$height = 1500;

$image = imagecreatetruecolor($width, $height);
imagealphablending($image, true);
imagesavealpha($image, true);

$palette = [
    'bg' => [255, 255, 255],
    'title' => [15, 23, 42],
    'muted' => [71, 85, 105],
    'line' => [17, 24, 39],
    'processFill' => [255, 255, 255],
    'processStroke' => [17, 24, 39],
    'storeFill' => [248, 250, 252],
    'storeStroke' => [17, 24, 39],
    'externalFill' => [255, 255, 255],
    'noteFill' => [255, 255, 255],
    'noteStroke' => [100, 116, 139],
    'shadow' => [226, 232, 240],
];

$c = [];
foreach ($palette as $name => $rgb) {
    $c[$name] = imagecolorallocate($image, ...$rgb);
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

drawText($image, $fontBold, 30, 54, 58, 'DFD Level 2 - Sistem Pendukung Keputusan Restock', $c['title']);
drawText($image, $fontRegular, 17, 56, 88, 'Alur data disesuaikan dengan flowchart dashboard monitoring: ambil data, hitung pemakaian, normalisasi SAW, lalu tampilkan ranking restock.', $c['muted']);

$processes = [
    'p61' => [470, 145, 350, 90, '6.1\nSistem Mengambil\nData'],
    'p62' => [470, 295, 350, 90, '6.2\nHitung Frekuensi\nPemakaian'],
    'p63' => [470, 445, 350, 90, '6.3\nHitung Total Jumlah\nPemakaian'],
    'p64' => [470, 595, 350, 90, '6.4\nHitung Total Sisa Stok\nTiap Gudang'],
    'p65' => [470, 745, 350, 90, '6.5\nNormalisasi Kriteria\nMetode SAW'],
    'p66' => [470, 895, 350, 90, '6.6\nUrutkan Barang\nBerdasarkan Ranking'],
    'p67' => [470, 1055, 350, 90, '6.7\nTampilkan Rekomendasi\nRestock'],
];

$stores = [
    'd1' => [1120, 145, 510, 58, 'D1', 'tb_barang'],
    'd2' => [1120, 255, 510, 58, 'D2', 'tb_lokasi'],
    'd3' => [1120, 365, 510, 58, 'D3', 'tb_barang_lokasi'],
    'd4' => [1120, 475, 510, 58, 'D4', 'tb_mutasi'],
];

$user = [80, 155, 240, 120, 'Pengguna\nmembuka dashboard\nmonitoring'];
$leader = [80, 1040, 240, 130, 'Admin dan\nPimpinan'];
$dashboard = [910, 1010, 300, 160];
$criteria = [900, 700, 360, 126];

// Main vertical process flow.
arrow($image, [320, 215], [470, 190], $c, $fontRegular, 'Permintaan data', [335, 135]);
arrow($image, [645, 235], [645, 295], $c, $fontRegular, 'Data barang, lokasi, stok, mutasi', [665, 270]);
arrow($image, [645, 385], [645, 445], $c, $fontRegular, 'Frekuensi pemakaian', [665, 420]);
arrow($image, [645, 535], [645, 595], $c, $fontRegular, 'Total jumlah pemakaian', [665, 570]);
arrow($image, [645, 685], [645, 745], $c, $fontRegular, 'Kriteria SPK', [665, 720]);
arrow($image, [645, 835], [645, 895], $c, $fontRegular, 'Matriks ternormalisasi', [665, 870]);
arrow($image, [645, 985], [645, 1055], $c, $fontRegular, 'Ranking barang', [665, 1030]);

// Data store flows mengikuti flowchart: tabel sumber dibaca pada proses ambil data.
polyArrow($image, [[1120, 174], [980, 174], [980, 190], [820, 190]], $c, $fontRegular, 'Data barang', [920, 145]);
polyArrow($image, [[1120, 284], [1010, 284], [1010, 200], [820, 200]], $c, $fontRegular, 'Data lokasi', [1025, 248]);
polyArrow($image, [[1120, 394], [1040, 394], [1040, 210], [820, 210]], $c, $fontRegular, 'Data stok', [1055, 358]);
polyArrow($image, [[1120, 504], [1070, 504], [1070, 220], [820, 220]], $c, $fontRegular, 'Data mutasi approved', [1082, 468]);

// Kriteria SAW berasal dari proses pemakaian dan stok.
dashArrow($image, [820, 640], [900, 760], $c, $fontRegular, 'Membentuk kriteria', [830, 705]);

// Output rekomendasi ke dashboard dan pengguna.
arrow($image, [820, 1100], [910, 1090], $c, $fontRegular, '', null);
arrow($image, [470, 1100], [320, 1105], $c, $fontRegular, '', null);

// Draw nodes last so lines stay behind boxes.
foreach ($processes as $process) {
    drawProcess($image, $fontRegular, $fontBold, ...$process, c: $c);
}

foreach ($stores as $store) {
    drawDataStore($image, $fontRegular, $fontBold, ...$store, c: $c);
}

drawCriteria($image, $fontRegular, $fontBold, ...$criteria, c: $c);
drawDashboard($image, $fontRegular, $fontBold, ...$dashboard, c: $c);
drawExternal($image, $fontRegular, $fontBold, ...$user, c: $c);
drawExternal($image, $fontRegular, $fontBold, ...$leader, c: $c);

drawLegend($image, $fontRegular, $fontBold, $c);

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

function drawProcess(GdImage $image, ?string $regular, ?string $bold, int $x, int $y, int $w, int $h, string $text, array $c): void
{
    imagefilledroundedrectangle($image, $x + 5, $y + 6, $x + $w + 5, $y + $h + 6, 22, $c['shadow']);
    imagefilledroundedrectangle($image, $x, $y, $x + $w, $y + $h, 22, $c['processFill']);
    imageroundedrectangle($image, $x, $y, $x + $w, $y + $h, 22, $c['processStroke'], 3);
    drawCenteredMultiline($image, $bold, 17, $x, $y, $w, $h, $text, $c['title']);
}

function drawDataStore(GdImage $image, ?string $regular, ?string $bold, int $x, int $y, int $w, int $h, string $code, string $label, array $c): void
{
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $c['storeFill']);
    imagesetthickness($image, 3);
    imageline($image, $x, $y, $x + $w, $y, $c['storeStroke']);
    imageline($image, $x, $y + $h, $x + $w, $y + $h, $c['storeStroke']);
    imageline($image, $x, $y, $x, $y + $h, $c['storeStroke']);
    imageline($image, $x + 86, $y, $x + 86, $y + $h, $c['storeStroke']);
    imagesetthickness($image, 1);

    drawCenteredMultiline($image, $bold, 16, $x, $y, 86, $h, $code, $c['title']);
    drawText($image, $regular, 16, $x + 110, $y + 37, $label, $c['title']);
}

function drawCriteria(GdImage $image, ?string $regular, ?string $bold, int $x, int $y, int $w, int $h, array $c): void
{
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $c['noteFill']);
    imagedashedrectangle($image, $x, $y, $x + $w, $y + $h, $c['noteStroke']);
    drawText($image, $bold, 16, $x + 24, $y + 32, 'Kriteria SPK:', $c['title']);
    drawText($image, $regular, 15, $x + 24, $y + 60, '1. Frekuensi Pemakaian', $c['title']);
    drawText($image, $regular, 15, $x + 24, $y + 85, '2. Jumlah Pemakaian', $c['title']);
    drawText($image, $regular, 15, $x + 24, $y + 110, '3. Sisa Stok', $c['title']);
}

function drawDashboard(GdImage $image, ?string $regular, ?string $bold, int $x, int $y, int $w, int $h, array $c): void
{
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $c['processFill']);
    imagerectangle($image, $x, $y, $x + $w, $y + $h, $c['processStroke']);
    imageline($image, $x, $y + 40, $x + $w, $y + 40, $c['processStroke']);
    imageline($image, $x, $y + 80, $x + $w, $y + 80, $c['processStroke']);
    imageline($image, $x, $y + 120, $x + $w, $y + 120, $c['processStroke']);
    drawCenteredMultiline($image, $bold, 14, $x, $y, $w, 40, 'Dashboard Monitoring', $c['title']);
    drawCenteredMultiline($image, $regular, 14, $x, $y + 40, $w, 40, 'Informasi Stok', $c['title']);
    drawCenteredMultiline($image, $regular, 14, $x, $y + 80, $w, 40, 'Top 5 Restock', $c['title']);
    drawCenteredMultiline($image, $regular, 14, $x, $y + 120, $w, 40, 'Laporan', $c['title']);
}

function drawExternal(GdImage $image, ?string $regular, ?string $bold, int $x, int $y, int $w, int $h, string $text, array $c): void
{
    imagefilledrectangle($image, $x + 5, $y + 6, $x + $w + 5, $y + $h + 6, $c['shadow']);
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $c['externalFill']);
    imagerectangle($image, $x, $y, $x + $w, $y + $h, $c['processStroke']);
    imagerectangle($image, $x + 1, $y + 1, $x + $w - 1, $y + $h - 1, $c['processStroke']);
    drawCenteredMultiline($image, $bold, 19, $x, $y, $w, $h, $text, $c['title']);
}

function drawLegend(GdImage $image, ?string $regular, ?string $bold, array $c): void
{
    $x = 70;
    $y = 1335;
    drawText($image, $fontBold ?? $bold, 15, $x, $y, 'Catatan:', $c['title']);
    drawText($image, $regular, 15, $x + 82, $y, 'DFD ini mengalir dari atas ke bawah. Data SPK dihitung dari data barang, stok gudang, lokasi, dan mutasi approved; tidak memakai tabel khusus SPK.', $c['muted']);
}

function arrow(GdImage $image, array $from, array $to, array $c, ?string $font, string $label = '', ?array $labelAt = null): void
{
    imagesetthickness($image, 3);
    imageline($image, $from[0], $from[1], $to[0], $to[1], $c['line']);
    imagesetthickness($image, 1);
    arrowHead($image, $from, $to, $c['line']);

    if ($label !== '' && $labelAt !== null) {
        drawText($image, $font, 13, $labelAt[0], $labelAt[1], $label, $c['title']);
    }
}

function polyArrow(GdImage $image, array $points, array $c, ?string $font, string $label = '', ?array $labelAt = null): void
{
    imagesetthickness($image, 3);
    for ($i = 0; $i < count($points) - 1; $i++) {
        imageline($image, $points[$i][0], $points[$i][1], $points[$i + 1][0], $points[$i + 1][1], $c['line']);
    }
    imagesetthickness($image, 1);
    arrowHead($image, $points[count($points) - 2], $points[count($points) - 1], $c['line']);

    if ($label !== '' && $labelAt !== null) {
        drawText($image, $font, 13, $labelAt[0], $labelAt[1], $label, $c['title']);
    }
}

function dashArrow(GdImage $image, array $from, array $to, array $c, ?string $font, string $label = '', ?array $labelAt = null): void
{
    imagesetstyle($image, [$c['line'], $c['line'], IMG_COLOR_TRANSPARENT, IMG_COLOR_TRANSPARENT]);
    imagesetthickness($image, 2);
    imageline($image, $from[0], $from[1], $to[0], $to[1], IMG_COLOR_STYLED);
    imagesetthickness($image, 1);
    arrowHead($image, $from, $to, $c['line']);

    if ($label !== '' && $labelAt !== null) {
        drawText($image, $font, 13, $labelAt[0], $labelAt[1], $label, $c['title']);
    }
}

function arrowHead(GdImage $image, array $from, array $to, int $color): void
{
    $angle = atan2($to[1] - $from[1], $to[0] - $from[0]);
    $length = 14;
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

function imagefilledroundedrectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

function imageroundedrectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color, int $thickness = 1): void
{
    imagesetthickness($image, $thickness);
    imageline($image, $x1 + $radius, $y1, $x2 - $radius, $y1, $color);
    imageline($image, $x1 + $radius, $y2, $x2 - $radius, $y2, $color);
    imageline($image, $x1, $y1 + $radius, $x1, $y2 - $radius, $color);
    imageline($image, $x2, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagearc($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, 180, 270, $color);
    imagearc($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, 270, 360, $color);
    imagearc($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, 90, 180, $color);
    imagearc($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, 0, 90, $color);
    imagesetthickness($image, 1);
}

function imagedashedrectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $color): void
{
    imagesetstyle($image, [$color, $color, IMG_COLOR_TRANSPARENT, IMG_COLOR_TRANSPARENT]);
    imageline($image, $x1, $y1, $x2, $y1, IMG_COLOR_STYLED);
    imageline($image, $x2, $y1, $x2, $y2, IMG_COLOR_STYLED);
    imageline($image, $x2, $y2, $x1, $y2, IMG_COLOR_STYLED);
    imageline($image, $x1, $y2, $x1, $y1, IMG_COLOR_STYLED);
}

function drawCenteredMultiline(GdImage $image, ?string $font, int $size, int $x, int $y, int $w, int $h, string $text, int $color): void
{
    $lines = explode('\n', $text);
    $lineHeight = $size + 8;
    $totalHeight = count($lines) * $lineHeight;
    $startY = (int) ($y + ($h - $totalHeight) / 2 + $size + 1);

    foreach ($lines as $i => $line) {
        $textWidth = textWidth($font, $size, $line);
        drawText($image, $font, $size, (int) ($x + ($w - $textWidth) / 2), $startY + ($i * $lineHeight), $line, $color);
    }
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

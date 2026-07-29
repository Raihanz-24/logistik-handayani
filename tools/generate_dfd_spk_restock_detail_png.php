<?php

declare(strict_types=1);

if (! extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$output = $root.'/docs/dfd-level-2-spk-restock-detail.png';

$width = 2300;
$height = 1750;

$image = imagecreatetruecolor($width, $height);
imagealphablending($image, true);
imagesavealpha($image, true);

$rgb = [
    'bg' => [255, 255, 255],
    'title' => [15, 23, 42],
    'muted' => [71, 85, 105],
    'line' => [17, 24, 39],
    'softLine' => [100, 116, 139],
    'processFill' => [255, 255, 255],
    'processStroke' => [17, 24, 39],
    'storeFill' => [248, 250, 252],
    'externalFill' => [255, 255, 255],
    'pillFill' => [239, 246, 255],
    'pillStroke' => [37, 99, 235],
    'noteFill' => [255, 247, 237],
    'noteStroke' => [249, 115, 22],
    'shadow' => [226, 232, 240],
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

drawText($image, $fontBold, 31, 56, 60, 'DFD Level 2 Detail - Sistem Pendukung Keputusan Restock', $c['title']);
drawText($image, $fontRegular, 17, 58, 92, 'Menjelaskan proses mana mengambil data store mana, serta hasil proses mana yang menjadi input proses berikutnya.', $c['muted']);

$processes = [
    'p61' => [620, 150, 390, 92, '6.1\nSistem Mengambil Data'],
    'p62' => [620, 330, 390, 92, '6.2\nHitung Frekuensi Pemakaian'],
    'p63' => [620, 510, 390, 92, '6.3\nHitung Total Jumlah Pemakaian'],
    'p64' => [620, 690, 390, 92, '6.4\nHitung Total Sisa Stok Tiap Gudang'],
    'p65' => [620, 870, 390, 92, '6.5\nNormalisasi Kriteria Metode SAW'],
    'p66' => [620, 1050, 390, 92, '6.6\nUrutkan Barang\nBerdasarkan Ranking'],
    'p67' => [620, 1230, 390, 92, '6.7\nTampilkan Rekomendasi Restock'],
];

$stores = [
    'd1' => [1470, 150, 650, 64, 'D1', 'Data Barang (barangs)'],
    'd2' => [1470, 305, 650, 64, 'D2', 'Data Lokasi / Gudang (lokasis)'],
    'd3' => [1470, 460, 650, 64, 'D3', 'Stok Barang per Lokasi (barang_lokasi)'],
    'd4' => [1470, 615, 650, 64, 'D4', 'Data Mutasi Barang (mutasis)'],
];

$externalUser = [80, 160, 300, 116, 'Pengguna\nmembuka dashboard\nmonitoring'];
$externalOutput = [80, 1210, 300, 128, 'Admin dan\nPimpinan\nmenerima hasil'];
$dashboard = [1180, 1195, 330, 160];
$criteria = [1130, 815, 430, 138];

// Input dari pengguna.
arrow($image, [380, 218], [620, 196], $c, $fontRegular, 'Permintaan dashboard monitoring', [385, 168]);

// Data store ke proses 6.1.
polyArrow($image, [[1470, 182], [1240, 182], [1240, 182], [1010, 182]], $c, $fontRegular, 'D1 Data barang', [1248, 156]);
polyArrow($image, [[1470, 337], [1285, 337], [1285, 198], [1010, 198]], $c, $fontRegular, 'D2 Data lokasi', [1300, 304]);
polyArrow($image, [[1470, 492], [1330, 492], [1330, 214], [1010, 214]], $c, $fontRegular, 'D3 Data stok', [1344, 459]);
polyArrow($image, [[1470, 647], [1375, 647], [1375, 230], [1010, 230]], $c, $fontRegular, 'D4 Mutasi approved', [1388, 614]);

// Aliran antar proses, dibuat menurun.
arrow($image, [815, 242], [815, 330], $c, $fontRegular, 'Hasil 6.1: data mutasi keluar approved dari D4', [842, 290]);
arrow($image, [815, 422], [815, 510], $c, $fontRegular, 'Hasil 6.2 + data D4: frekuensi pemakaian', [842, 470]);
arrow($image, [815, 602], [815, 690], $c, $fontRegular, 'Hasil 6.3 + data D2/D3: jumlah pemakaian & stok', [842, 650]);
arrow($image, [815, 782], [815, 870], $c, $fontRegular, 'Hasil 6.2, 6.3, 6.4: kriteria SPK', [842, 830]);
arrow($image, [815, 962], [815, 1050], $c, $fontRegular, 'Matriks ternormalisasi & nilai preferensi', [842, 1010]);
arrow($image, [815, 1142], [815, 1230], $c, $fontRegular, 'Ranking barang prioritas restock', [842, 1190]);

// D1 dipakai lagi untuk menampilkan nama/kode barang pada hasil ranking.
polyArrow($image, [[1470, 182], [1420, 182], [1420, 1280], [1010, 1280]], $c, $fontRegular, '', null);

// Kriteria box.
dashArrow($image, [1010, 736], [1130, 875], $c, $fontRegular, 'Membentuk kriteria', [1025, 805]);

// Output.
arrow($image, [620, 1280], [380, 1274], $c, $fontRegular, 'Informasi rekomendasi restock', [392, 1238]);
arrow($image, [1010, 1275], [1180, 1275], $c, $fontRegular, 'Output dashboard', [1042, 1245]);

drawExternal($image, $fontRegular, $fontBold, ...$externalUser, c: $c);
drawExternal($image, $fontRegular, $fontBold, ...$externalOutput, c: $c);
foreach ($processes as $process) {
    drawProcess($image, $fontRegular, $fontBold, ...$process, c: $c);
}
foreach ($stores as $store) {
    drawDataStore($image, $fontRegular, $fontBold, ...$store, c: $c);
}
drawCriteria($image, $fontRegular, $fontBold, ...$criteria, c: $c);
drawDashboard($image, $fontRegular, $fontBold, ...$dashboard, c: $c);

drawInputPill($image, $fontRegular, 1040, 154, 340, 70, 'Input 6.1:\nD1, D2, D3, D4', $c);
drawInputPill($image, $fontRegular, 1040, 344, 340, 58, 'Input 6.2: hasil 6.1 + D4', $c);
drawInputPill($image, $fontRegular, 1040, 524, 340, 58, 'Input 6.3: hasil 6.1 + D4', $c);
drawInputPill($image, $fontRegular, 1040, 704, 340, 58, 'Input 6.4: hasil 6.1 + D2/D3', $c);
drawInputPill($image, $fontRegular, 1040, 974, 340, 58, 'Input 6.6: hasil 6.5', $c);
drawInputPill($image, $fontRegular, 1040, 1148, 340, 58, 'Input 6.7: hasil 6.6 + D1', $c);

drawFlowSummary($image, $fontRegular, $fontBold, $c);

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
    imagefilledroundedrectangle($image, $x + 6, $y + 7, $x + $w + 6, $y + $h + 7, 24, $c['shadow']);
    imagefilledroundedrectangle($image, $x, $y, $x + $w, $y + $h, 24, $c['processFill']);
    imageroundedrectangle($image, $x, $y, $x + $w, $y + $h, 24, $c['processStroke'], 3);
    drawCenteredMultiline($image, $bold, 17, $x, $y, $w, $h, $text, $c['title']);
}

function drawExternal(GdImage $image, ?string $regular, ?string $bold, int $x, int $y, int $w, int $h, string $text, array $c): void
{
    imagefilledrectangle($image, $x + 6, $y + 7, $x + $w + 6, $y + $h + 7, $c['shadow']);
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $c['externalFill']);
    imagerectangle($image, $x, $y, $x + $w, $y + $h, $c['processStroke']);
    imagerectangle($image, $x + 1, $y + 1, $x + $w - 1, $y + $h - 1, $c['processStroke']);
    drawCenteredMultiline($image, $bold, 18, $x, $y, $w, $h, $text, $c['title']);
}

function drawDataStore(GdImage $image, ?string $regular, ?string $bold, int $x, int $y, int $w, int $h, string $code, string $label, array $c): void
{
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $c['storeFill']);
    imagesetthickness($image, 3);
    imageline($image, $x, $y, $x + $w, $y, $c['processStroke']);
    imageline($image, $x, $y + $h, $x + $w, $y + $h, $c['processStroke']);
    imageline($image, $x, $y, $x, $y + $h, $c['processStroke']);
    imageline($image, $x + 95, $y, $x + 95, $y + $h, $c['processStroke']);
    imagesetthickness($image, 1);
    drawCenteredMultiline($image, $bold, 16, $x, $y, 95, $h, $code, $c['title']);
    drawText($image, $regular, 16, $x + 125, $y + 40, $label, $c['title']);
}

function drawCriteria(GdImage $image, ?string $regular, ?string $bold, int $x, int $y, int $w, int $h, array $c): void
{
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $c['noteFill']);
    imagedashedrectangle($image, $x, $y, $x + $w, $y + $h, $c['noteStroke']);
    drawText($image, $bold, 17, $x + 24, $y + 36, 'Kriteria SAW:', $c['title']);
    drawText($image, $regular, 15, $x + 24, $y + 66, '1. Frekuensi pemakaian dari 6.2', $c['title']);
    drawText($image, $regular, 15, $x + 24, $y + 92, '2. Jumlah pemakaian dari 6.3', $c['title']);
    drawText($image, $regular, 15, $x + 24, $y + 118, '3. Sisa stok dari 6.4', $c['title']);
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

function drawInputPill(GdImage $image, ?string $regular, int $x, int $y, int $w, int $h, string $text, array $c): void
{
    imagefilledroundedrectangle($image, $x, $y, $x + $w, $y + $h, 14, $c['pillFill']);
    imageroundedrectangle($image, $x, $y, $x + $w, $y + $h, 14, $c['pillStroke'], 2);
    drawCenteredMultiline($image, $regular, 13, $x, $y, $w, $h, $text, $c['title']);
}

function drawFlowSummary(GdImage $image, ?string $regular, ?string $bold, array $c): void
{
    $x = 70;
    $y = 1455;
    drawText($image, $bold, 15, $x, $y, 'Ringkasan:', $c['title']);
    drawText($image, $regular, 15, $x + 112, $y, '6.1 membaca D1-D4; 6.2 dan 6.3 memakai mutasi keluar approved dari D4; 6.4 memakai D2-D3; 6.5 memakai hasil 6.2-6.4; 6.6 ranking; 6.7 menampilkan hasil.', $c['muted']);
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
    imagesetstyle($image, [$c['softLine'], $c['softLine'], IMG_COLOR_TRANSPARENT, IMG_COLOR_TRANSPARENT]);
    imagesetthickness($image, 2);
    imageline($image, $from[0], $from[1], $to[0], $to[1], IMG_COLOR_STYLED);
    imagesetthickness($image, 1);
    arrowHead($image, $from, $to, $c['softLine']);
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

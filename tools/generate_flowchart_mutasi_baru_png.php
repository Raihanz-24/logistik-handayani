<?php

declare(strict_types=1);

if (! extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$output = $root.'/docs/flowchart-sistem-mutasi-baru.png';

$width = 1900;
$height = 2200;

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
    'green' => [236, 253, 245],
    'red' => [254, 242, 242],
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
drawCenteredText($image, $fontBold, 26, 40, $width - 80, 78, 'Flowchart Sistem Mutasi Barang Baru PT ISS', imagecolorallocate($image, 255, 255, 255));

$laneTop = 105;
$laneBottom = 2140;
$laneWidth = (int) (($width - 80) / 3);
$x1 = 40;
$x2 = $x1 + $laneWidth;
$x3 = $x2 + $laneWidth;

drawLane($image, $fontBold, $x1, $laneTop, $laneWidth, $laneBottom, 'Admin Gudang', $c);
drawLane($image, $fontBold, $x2, $laneTop, $laneWidth, $laneBottom, 'Sistem Warehouse Monitoring', $c);
drawLane($image, $fontBold, $x3, $laneTop, $laneWidth, $laneBottom, 'Pimpinan', $c);

// Admin Gudang
$start = terminator($image, $fontBold, $x1 + 210, 170, 190, 58, 'Mulai', $c);
$a1 = inputOutputBox($image, $fontRegular, $x1 + 145, 270, 330, 82, 'Login sebagai\nAdmin Gudang', $c);
$a2 = processBox($image, $fontRegular, $x1 + 145, 410, 330, 82, 'Pilih menu\nMutasi Barang', $c);
$a3 = inputOutputBox($image, $fontRegular, $x1 + 145, 560, 330, 116, 'Input jenis mutasi,\ngudang, tujuan,\nbarang, dan jumlah', $c);
$a4 = processBox($image, $fontRegular, $x1 + 145, 740, 330, 82, 'Klik simpan\npengajuan mutasi', $c);
$a5 = inputOutputBox($image, $fontRegular, $x1 + 145, 1940, 330, 92, 'Melihat status mutasi\npending / disetujui /\ndibatalkan', $c);
$end = terminator($image, $fontBold, $x1 + 210, 2060, 190, 58, 'Selesai', $c);

// Sistem
$s1 = processBox($image, $fontRegular, $x2 + 130, 270, 355, 82, 'Validasi login dan\nhak akses pengguna', $c);
$s2 = processBox($image, $fontRegular, $x2 + 130, 740, 355, 100, 'Validasi form mutasi:\ngudang, tujuan, item unik,\ndan jumlah barang', $c);
$s3 = diamond($image, $fontRegular, $x2 + 307, 930, 250, 100, 'Data mutasi\nvalid?', $c);
$s4 = documentBox($image, $fontRegular, $x2 + 130, 1070, 355, 86, 'Tampilkan pesan error\nuntuk diperbaiki', $c);
$s5 = processBox($image, $fontRegular, $x2 + 130, 1220, 355, 100, 'Jika mutasi keluar,\nsistem cek stok tersedia\ndi barang_lokasi', $c);
$s6 = dataStore($image, $fontRegular, $x2 + 130, 1390, 355, 96, 'Simpan ke tabel mutasis\nstatus: pending', $c);
$s7 = documentBox($image, $fontRegular, $x2 + 130, 1545, 355, 86, 'Tampilkan daftar mutasi\nMenunggu Persetujuan', $c);
$s8 = dataStore($image, $fontRegular, $x2 + 130, 1815, 355, 118, 'Jika disetujui:\nupdate barang_lokasi,\nstok_awal, stok_akhir,\napproved_by, approved_at', $c);
$s9 = dataStore($image, $fontRegular, $x2 + 130, 1948, 355, 88, 'Jika dibatalkan:\nupdate status cancelled\nstok tidak berubah', $c);
$s10 = documentBox($image, $fontRegular, $x2 + 130, 2055, 355, 70, 'Tampilkan status akhir\nmutasi', $c);

// Pimpinan
$p1 = inputOutputBox($image, $fontRegular, $x3 + 145, 1390, 330, 82, 'Login sebagai\nPimpinan', $c);
$p2 = processBox($image, $fontRegular, $x3 + 145, 1545, 330, 86, 'Buka tab\nMenunggu Persetujuan', $c);
$p3 = inputOutputBox($image, $fontRegular, $x3 + 145, 1665, 330, 92, 'Klik data mutasi\nuntuk melihat detail', $c);
$p4 = processBox($image, $fontRegular, $x3 + 145, 1790, 330, 92, 'Review barang,\njumlah, gudang,\ndan tujuan', $c);
$p5 = diamond($image, $fontRegular, $x3 + 310, 1945, 230, 100, 'Setujui\nmutasi?', $c);
$p6 = processBox($image, $fontRegular, $x3 + 330, 2055, 250, 70, 'Klik Approve\nMutasi', $c);
$p7 = processBox($image, $fontRegular, $x3 + 35, 2055, 250, 70, 'Batalkan / Tolak\nMutasi', $c);

// Admin flow arrows
arrow($image, bottom($start), top($a1), '', $fontRegular, $c);
arrow($image, bottom($a1), top($a2), '', $fontRegular, $c);
arrow($image, right($a1), left($s1), 'Akun pengguna', $fontRegular, $c);
arrow($image, bottom($a2), top($a3), '', $fontRegular, $c);
arrow($image, bottom($a3), top($a4), '', $fontRegular, $c);
arrow($image, right($a4), left($s2), 'Data pengajuan', $fontRegular, $c);

// System validation flow
arrow($image, bottom($s2), top($s3), '', $fontRegular, $c);
arrow($image, bottom($s3), top($s4), 'Tidak', $fontRegular, $c);
polyArrow($image, [
    left($s4),
    [$x2 + 58, $s4['y'] + (int) ($s4['h'] / 2)],
    [$x2 + 58, $a3['y'] + (int) ($a3['h'] / 2)],
    right($a3),
], 'Perbaiki input', $fontRegular, $c);
polyArrow($image, [
    right($s3),
    [$x2 + 555, $s3['y'] + (int) ($s3['h'] / 2)],
    [$x2 + 555, $s5['y'] - 25],
    top($s5),
], 'Ya', $fontRegular, $c);
arrow($image, bottom($s5), top($s6), 'Stok cukup / masuk', $fontRegular, $c);
arrow($image, bottom($s6), top($s7), '', $fontRegular, $c);
polyArrow($image, [
    left($s7),
    [$x1 + 535, $s7['y'] + (int) ($s7['h'] / 2)],
    [$x1 + 535, $a5['y'] + (int) ($a5['h'] / 2)],
    right($a5),
], 'Status pending', $fontRegular, $c);

// Pimpinan review flow
arrow($image, right($s7), left($p2), 'Daftar pending', $fontRegular, $c);
arrow($image, bottom($p1), top($p2), '', $fontRegular, $c);
arrow($image, bottom($p2), top($p3), '', $fontRegular, $c);
arrow($image, bottom($p3), top($p4), '', $fontRegular, $c);
arrow($image, bottom($p4), top($p5), '', $fontRegular, $c);
arrow($image, bottom($p5), top($p6), 'Ya', $fontRegular, $c);
arrow($image, left($p6), right($s8), 'Approval', $fontRegular, $c);
polyArrow($image, [
    [$p5['x'], $p5['y'] + (int) ($p5['h'] / 2)],
    [$x3 + 95, $p5['y'] + (int) ($p5['h'] / 2)],
    [$x3 + 95, $p7['y'] + (int) ($p7['h'] / 2)],
    left($p7),
], 'Tidak', $fontRegular, $c);
arrow($image, left($p7), right($s9), 'Pembatalan', $fontRegular, $c);
arrow($image, bottom($s8), top($s10), '', $fontRegular, $c);
arrow($image, bottom($s9), top($s10), '', $fontRegular, $c);

// Final status back to admin
polyArrow($image, [
    left($s10),
    [$x1 + 520, $s10['y'] + (int) ($s10['h'] / 2)],
    [$x1 + 520, $a5['y'] + (int) ($a5['h'] / 2)],
    right($a5),
], 'Status akhir', $fontRegular, $c);
arrow($image, bottom($a5), top($end), '', $fontRegular, $c);

// Small note
drawNote($image, $fontRegular, $x1 + 65, 2148, 520, 44, 'Catatan: Admin Gudang hanya membuat dan memantau mutasi.\nApproval dilakukan oleh akun pimpinan/super admin.', $c);

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
    imagepolygon($image, $points, $c['line']);
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
    imagearc($image, $x + (int) ($w / 2), $y + $h - (int) ($ellipseH / 2), $w, $ellipseH, 0, 360, $c['line']);
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

function drawNote(GdImage $image, ?string $font, int $x, int $y, int $w, int $h, string $text, array $c): void
{
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, imagecolorallocate($image, 241, 245, 249));
    imagerectangle($image, $x, $y, $x + $w, $y + $h, $c['muted']);
    drawCenteredMultiline($image, $font, 11, $x + 10, $y + 2, $w - 20, $h - 4, $text, $c['muted']);
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
        imagefilledrectangle($image, $x - 86, $y - 18, $x + 86, $y + 5, $c['lane']);
        drawCenteredText($image, $font, 11, $x - 86, 172, $y, $label, $c['muted']);
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
        imagefilledrectangle($image, $p[0] - 76, $p[1] - 26, $p[0] + 76, $p[1] - 4, $c['lane']);
        drawCenteredText($image, $font, 11, $p[0] - 76, 152, $p[1] - 9, $label, $c['muted']);
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

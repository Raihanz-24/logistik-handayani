<?php

declare(strict_types=1);

if (! extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$output = $root.'/docs/erd-warehouse-monitoring-pt-iss.png';

$width = 2200;
$height = 1880;

$image = imagecreatetruecolor($width, $height);
imagealphablending($image, true);
imagesavealpha($image, true);

$colors = [
    'bgTop' => [248, 251, 255],
    'bgBottom' => [255, 255, 255],
    'title' => [15, 23, 42],
    'muted' => [71, 85, 105],
    'blue' => [37, 99, 235],
    'blueSoft' => [239, 246, 255],
    'blueSoft2' => [238, 242, 255],
    'orange' => [249, 115, 22],
    'orangeSoft' => [255, 247, 237],
    'line' => [51, 65, 85],
    'green' => [5, 150, 105],
    'greenSoft' => [236, 253, 245],
    'white' => [255, 255, 255],
    'shadow' => [203, 213, 225],
    'note' => [100, 116, 139],
];

$c = [];
foreach ($colors as $name => $rgb) {
    $c[$name] = imagecolorallocate($image, ...$rgb);
}

// Soft vertical background.
for ($y = 0; $y < $height; $y++) {
    $ratio = $y / max(1, $height - 1);
    $r = (int) round($colors['bgTop'][0] * (1 - $ratio) + $colors['bgBottom'][0] * $ratio);
    $g = (int) round($colors['bgTop'][1] * (1 - $ratio) + $colors['bgBottom'][1] * $ratio);
    $b = (int) round($colors['bgTop'][2] * (1 - $ratio) + $colors['bgBottom'][2] * $ratio);
    imageline($image, 0, $y, $width, $y, imagecolorallocate($image, $r, $g, $b));
}

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

drawText($image, $fontBold, 38, 0, 42, 74, 'ERD Sistem Warehouse Monitoring PT ISS', $c['title']);
drawText($image, $fontRegular, 18, 0, 44, 112, 'Diagram konseptual relasi utama, stok, mutasi, serta role-permission Spatie Laravel Permission.', $c['muted']);

drawSectionTitle($image, $fontBold, '1. ERD Utama Sistem', 44, 170, $c);
drawMainErd($image, $fontRegular, $fontBold, $c);

drawSectionTitle($image, $fontBold, '2. Role & Permission Spatie', 44, 870, $c);
drawSpatieErd($image, $fontRegular, $fontBold, $c);

drawSectionTitle($image, $fontBold, '3. Flow Mutasi dan Pembentukan Stok', 44, 1370, $c);
drawMutationFlow($image, $fontRegular, $fontBold, $c);

drawLegend($image, $fontRegular, $fontBold, $c, 44, 1700);

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

function drawSectionTitle(GdImage $image, ?string $font, string $text, int $x, int $y, array $c): void
{
    imagefilledrectangle($image, $x, $y - 28, $x + 10, $y + 7, $c['orange']);
    drawText($image, $font, 24, 0, $x + 24, $y, $text, $c['title']);
}

function drawMainErd(GdImage $image, ?string $regular, ?string $bold, array $c): void
{
    $boxes = [
        'kategori' => [80, 290, 170, 78, 'Kategori\nBarang'],
        'barang' => [390, 290, 160, 78, 'Barang'],
        'stok' => [760, 290, 220, 78, 'Stok Barang\nper Lokasi'],
        'lokasi' => [760, 525, 170, 78, 'Lokasi'],
        'mutasi' => [1180, 390, 185, 82, 'Mutasi\nBarang'],
        'user' => [1510, 220, 160, 78, 'User'],
        'role' => [1870, 220, 160, 78, 'Role'],
        'permission' => [1870, 520, 190, 78, 'Permission'],
    ];

    $diamonds = [
        'mengelompokkan' => [300, 329, 170, 76, 'Mengelompokkan'],
        'memilikiStok' => [650, 329, 160, 76, 'Memiliki\nStok'],
        'terjadiDi' => [1060, 431, 155, 76, 'Terjadi\ndi'],
        'mencatat' => [1460, 342, 130, 68, 'Mencatat'],
        'membuat' => [1460, 458, 130, 68, 'Membuat'],
        'menyetujui' => [1460, 574, 140, 70, 'Menyetujui'],
        'diberiRole' => [1760, 259, 140, 70, 'Diberi\nRole'],
        'mengaturAkses' => [1965, 395, 160, 76, 'Mengatur\nAkses'],
    ];

    // Domain utama.
    connect($image, rightOf($boxes['kategori']), leftOfDiamond($diamonds['mengelompokkan']), '1', $regular, $c);
    connect($image, rightOfDiamond($diamonds['mengelompokkan']), leftOf($boxes['barang']), 'N', $regular, $c);

    connect($image, rightOf($boxes['barang']), leftOfDiamond($diamonds['memilikiStok']), '1', $regular, $c);
    connect($image, rightOfDiamond($diamonds['memilikiStok']), leftOf($boxes['stok']), 'N', $regular, $c);
    connect($image, bottomOf($boxes['lokasi']), [650, 603], '1', $regular, $c);
    connect($image, [650, 603], bottomOfDiamond($diamonds['memilikiStok']), '', $regular, $c);

    connect($image, rightOf($boxes['stok']), leftOfDiamond($diamonds['terjadiDi']), '1', $regular, $c);
    connect($image, rightOfDiamond($diamonds['terjadiDi']), leftOf($boxes['mutasi']), 'N', $regular, $c);
    connect($image, rightOf($boxes['lokasi']), [1060, 564], '1', $regular, $c);
    connect($image, [1060, 564], bottomOfDiamond($diamonds['terjadiDi']), '', $regular, $c);

    // User ke mutasi.
    connect($image, bottomOf($boxes['user']), topOfDiamond($diamonds['mencatat']), '1', $regular, $c);
    connect($image, bottomOfDiamond($diamonds['mencatat']), [1365, 410], 'N', $regular, $c);
    connect($image, [1365, 410], rightOf($boxes['mutasi']), '', $regular, $c);

    connect($image, [1510, 259], [1425, 259], '', $regular, $c);
    connect($image, [1425, 259], topOfDiamond($diamonds['membuat']), '1', $regular, $c);
    connect($image, bottomOfDiamond($diamonds['membuat']), [1365, 438], 'N', $regular, $c);

    connect($image, [1590, 298], [1590, 574], '1', $regular, $c);
    connect($image, [1590, 574], rightOfDiamond($diamonds['menyetujui']), '', $regular, $c);
    connect($image, leftOfDiamond($diamonds['menyetujui']), [1365, 456], 'N', $regular, $c);

    // User-role-permission ringkas di ERD utama.
    connect($image, rightOf($boxes['user']), leftOfDiamond($diamonds['diberiRole']), 'N', $regular, $c);
    connect($image, rightOfDiamond($diamonds['diberiRole']), leftOf($boxes['role']), 'N', $regular, $c);
    connect($image, bottomOf($boxes['role']), topOfDiamond($diamonds['mengaturAkses']), 'N', $regular, $c);
    connect($image, bottomOfDiamond($diamonds['mengaturAkses']), topOf($boxes['permission']), 'N', $regular, $c);

    foreach ($boxes as $box) {
        drawEntity($image, $regular, $bold, $box[0], $box[1], $box[2], $box[3], $box[4], $c);
    }
    foreach ($diamonds as $diamond) {
        drawDiamond($image, $regular, $diamond[0], $diamond[1], $diamond[2], $diamond[3], $diamond[4], $c);
    }

    drawNote($image, $regular, 80, 675, 1980, 88, 'Catatan: stok barang terbentuk dari mutasi masuk/pengadaan. Mutasi keluar baru mengurangi stok setelah status disetujui.', $c);
}

function drawSpatieErd(GdImage $image, ?string $regular, ?string $bold, array $c): void
{
    $boxes = [
        'user' => [95, 1010, 150, 72, 'User'],
        'mhr' => [465, 1000, 230, 90, 'model_has_roles\n(Pivot)'],
        'role' => [900, 1010, 150, 72, 'Role'],
        'rhp' => [1240, 1000, 250, 90, 'role_has_permissions\n(Pivot)'],
        'permission' => [1695, 1010, 190, 72, 'Permission'],
        'mhp' => [900, 1150, 280, 90, 'model_has_permissions\n(Pivot langsung)'],
        'fitur' => [1695, 1160, 190, 72, 'Fitur\nSistem'],
    ];

    $diamonds = [
        'mendapatRole' => [345, 1045, 145, 70, 'Mendapat\nRole'],
        'rolePermission' => [1135, 1045, 160, 74, 'Memiliki\nIzin'],
        'directPermission' => [620, 1195, 190, 78, 'Permission\nLangsung'],
        'akses' => [1575, 1195, 130, 68, 'Akses'],
    ];

    connect($image, rightOf($boxes['user']), leftOfDiamond($diamonds['mendapatRole']), '1', $regular, $c);
    connect($image, rightOfDiamond($diamonds['mendapatRole']), leftOf($boxes['mhr']), 'N', $regular, $c);
    connect($image, rightOf($boxes['mhr']), leftOf($boxes['role']), 'N', $regular, $c);

    connect($image, rightOf($boxes['role']), leftOfDiamond($diamonds['rolePermission']), '1', $regular, $c);
    connect($image, rightOfDiamond($diamonds['rolePermission']), leftOf($boxes['rhp']), 'N', $regular, $c);
    connect($image, rightOf($boxes['rhp']), leftOf($boxes['permission']), 'N', $regular, $c);

    connect($image, bottomOf($boxes['user']), [245, 1195], '1', $regular, $c);
    connect($image, [245, 1195], leftOfDiamond($diamonds['directPermission']), '', $regular, $c);
    connect($image, rightOfDiamond($diamonds['directPermission']), leftOf($boxes['mhp']), 'N', $regular, $c);
    connect($image, rightOf($boxes['mhp']), [1510, 1195], 'N', $regular, $c);
    connect($image, [1510, 1195], leftOfDiamond($diamonds['akses']), '', $regular, $c);
    connect($image, rightOfDiamond($diamonds['akses']), leftOf($boxes['fitur']), 'N', $regular, $c);
    connect($image, bottomOf($boxes['permission']), topOf($boxes['fitur']), 'N', $regular, $c);

    foreach ($boxes as $key => $box) {
        $isPivot = in_array($key, ['mhr', 'rhp', 'mhp'], true);
        drawEntity($image, $regular, $bold, $box[0], $box[1], $box[2], $box[3], $box[4], $c, $isPivot);
    }
    foreach ($diamonds as $diamond) {
        drawDiamond($image, $regular, $diamond[0], $diamond[1], $diamond[2], $diamond[3], $diamond[4], $c);
    }

    drawNote($image, $regular, 95, 1260, 1790, 54, 'Spatie memakai relasi polymorphic: model_has_roles/model_has_permissions menyimpan model_id dan model_type. Untuk sistem ini, model_type utamanya App\\Models\\User.', $c);
}

function drawMutationFlow(GdImage $image, ?string $regular, ?string $bold, array $c): void
{
    $nodes = [
        [95, 1480, 230, 74, 'Pengadaan /\nBarang Baru'],
        [415, 1480, 190, 74, 'Mutasi\nMasuk'],
        [690, 1482, 155, 70, 'Approval'],
        [930, 1480, 230, 74, 'Stok Gudang\nBertambah'],
        [1245, 1480, 190, 74, 'Mutasi\nKeluar'],
        [1510, 1482, 155, 70, 'Approval'],
        [1750, 1480, 230, 74, 'Stok Gudang\nBerkurang'],
    ];

    for ($i = 0; $i < count($nodes) - 1; $i++) {
        $from = $nodes[$i];
        $to = $nodes[$i + 1];
        connect($image, [$from[0] + $from[2], $from[1] + (int) ($from[3] / 2)], [$to[0], $to[1] + (int) ($to[3] / 2)], '', $regular, $c, true);
    }

    foreach ($nodes as $i => $node) {
        if (str_contains($node[4], 'Approval')) {
            drawDiamond($image, $regular, $node[0] + (int) ($node[2] / 2), $node[1] + (int) ($node[3] / 2), $node[2], $node[3], $node[4], $c);
        } else {
            drawEntity($image, $regular, $bold, $node[0], $node[1], $node[2], $node[3], $node[4], $c, false, $i === 0 || $i === 3 || $i === 6);
        }
    }

    drawNote($image, $regular, 95, 1585, 1885, 54, 'Kesimpulan flow: stok bukan data awal yang berdiri sendiri; stok dihitung/terbentuk dari mutasi masuk yang telah disetujui, lalu berkurang saat mutasi keluar disetujui.', $c);
}

function drawLegend(GdImage $image, ?string $regular, ?string $bold, array $c, int $x, int $y): void
{
    imagefilledrectangle($image, $x, $y, $x + 610, $y + 118, $c['white']);
    imagerectangle($image, $x, $y, $x + 610, $y + 118, $c['shadow']);
    drawText($image, $bold, 17, 0, $x + 22, $y + 33, 'Keterangan', $c['title']);

    imagefilledrectangle($image, $x + 24, $y + 52, $x + 94, $y + 84, $c['blueSoft']);
    imagerectangle($image, $x + 24, $y + 52, $x + 94, $y + 84, $c['blue']);
    drawText($image, $regular, 15, 0, $x + 112, $y + 75, 'Entitas utama', $c['muted']);

    imagefilledpolygon($image, [$x + 306, $y + 68, $x + 346, $y + 48, $x + 386, $y + 68, $x + 346, $y + 88], $c['orangeSoft']);
    imagepolygon($image, [$x + 306, $y + 68, $x + 346, $y + 48, $x + 386, $y + 68, $x + 346, $y + 88], $c['orange']);
    drawText($image, $regular, 15, 0, $x + 405, $y + 75, 'Relasi', $c['muted']);

    drawText($image, $regular, 14, 0, $x + 24, $y + 108, '1 = satu, N = banyak. Semua garis relasi dibuat solid.', $c['note']);
}

function drawEntity(
    GdImage $image,
    ?string $regular,
    ?string $bold,
    int $x,
    int $y,
    int $w,
    int $h,
    string $text,
    array $c,
    bool $pivot = false,
    bool $green = false
): void {
    $fill = $green ? $c['greenSoft'] : ($pivot ? $c['blueSoft2'] : $c['blueSoft']);
    $stroke = $green ? $c['green'] : $c['blue'];
    imagefilledrectangle($image, $x + 5, $y + 6, $x + $w + 5, $y + $h + 6, $c['shadow']);
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $fill);
    imagerectangle($image, $x, $y, $x + $w, $y + $h, $stroke);
    imagerectangle($image, $x + 1, $y + 1, $x + $w - 1, $y + $h - 1, $stroke);
    drawCenteredMultiline($image, $bold, 18, $x, $y, $w, $h, $text, $c['title']);
}

function drawDiamond(GdImage $image, ?string $regular, int $cx, int $cy, int $w, int $h, string $text, array $c): void
{
    $points = [$cx, $cy - (int) ($h / 2), $cx + (int) ($w / 2), $cy, $cx, $cy + (int) ($h / 2), $cx - (int) ($w / 2), $cy];
    imagefilledpolygon($image, [$points[0] + 4, $points[1] + 5, $points[2] + 4, $points[3] + 5, $points[4] + 4, $points[5] + 5, $points[6] + 4, $points[7] + 5], $c['shadow']);
    imagefilledpolygon($image, $points, $c['orangeSoft']);
    imagepolygon($image, $points, $c['orange']);
    imagepolygon($image, [$cx, $cy - (int) ($h / 2) + 1, $cx + (int) ($w / 2) - 1, $cy, $cx, $cy + (int) ($h / 2) - 1, $cx - (int) ($w / 2) + 1, $cy], $c['orange']);
    drawCenteredMultiline($image, $regular, 14, $cx - (int) ($w * 0.33), $cy - (int) ($h * 0.25), (int) ($w * 0.66), (int) ($h * 0.5), $text, $c['title']);
}

function drawNote(GdImage $image, ?string $regular, int $x, int $y, int $w, int $h, string $text, array $c): void
{
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $c['white']);
    imagerectangle($image, $x, $y, $x + $w, $y + $h, $c['shadow']);
    drawWrappedText($image, $regular, 16, $x + 22, $y + 32, $text, $c['muted'], $w - 44, 23);
}

function connect(GdImage $image, array $from, array $to, string $label, ?string $font, array $c, bool $arrow = false): void
{
    imagesetthickness($image, 3);
    imageline($image, $from[0], $from[1], $to[0], $to[1], $c['line']);
    imagesetthickness($image, 1);

    if ($arrow) {
        drawArrowHead($image, $from, $to, $c['line']);
    }

    if ($label !== '') {
        $mx = (int) (($from[0] + $to[0]) / 2);
        $my = (int) (($from[1] + $to[1]) / 2);
        imagefilledellipse($image, $mx, $my - 8, 32, 26, $c['white']);
        drawText($image, $font, 15, 0, $mx - 6, $my - 2, $label, $c['title']);
    }
}

function drawArrowHead(GdImage $image, array $from, array $to, int $color): void
{
    $angle = atan2($to[1] - $from[1], $to[0] - $from[0]);
    $length = 15;
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

function drawCenteredMultiline(GdImage $image, ?string $font, int $size, int $x, int $y, int $w, int $h, string $text, int $color): void
{
    $lines = explode('\n', $text);
    $lineHeight = $size + 8;
    $totalHeight = count($lines) * $lineHeight;
    $startY = (int) ($y + ($h - $totalHeight) / 2 + $size + 1);

    foreach ($lines as $i => $line) {
        $textWidth = textWidth($font, $size, $line);
        drawText($image, $font, $size, 0, (int) ($x + ($w - $textWidth) / 2), $startY + ($i * $lineHeight), $line, $color);
    }
}

function drawWrappedText(GdImage $image, ?string $font, int $size, int $x, int $y, string $text, int $color, int $maxWidth, int $lineHeight): void
{
    $words = preg_split('/\s+/', $text) ?: [];
    $line = '';
    $currentY = $y;

    foreach ($words as $word) {
        $candidate = trim($line.' '.$word);
        if ($line !== '' && textWidth($font, $size, $candidate) > $maxWidth) {
            drawText($image, $font, $size, 0, $x, $currentY, $line, $color);
            $line = $word;
            $currentY += $lineHeight;
            continue;
        }
        $line = $candidate;
    }

    if ($line !== '') {
        drawText($image, $font, $size, 0, $x, $currentY, $line, $color);
    }
}

function drawText(GdImage $image, ?string $font, int $size, int $angle, int $x, int $y, string $text, int $color): void
{
    if ($font !== null && function_exists('imagettftext')) {
        imagettftext($image, $size, $angle, $x, $y, $color, $font, $text);

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

function leftOf(array $box): array
{
    return [$box[0], $box[1] + (int) ($box[3] / 2)];
}

function rightOf(array $box): array
{
    return [$box[0] + $box[2], $box[1] + (int) ($box[3] / 2)];
}

function topOf(array $box): array
{
    return [$box[0] + (int) ($box[2] / 2), $box[1]];
}

function bottomOf(array $box): array
{
    return [$box[0] + (int) ($box[2] / 2), $box[1] + $box[3]];
}

function leftOfDiamond(array $diamond): array
{
    return [$diamond[0] - (int) ($diamond[2] / 2), $diamond[1]];
}

function rightOfDiamond(array $diamond): array
{
    return [$diamond[0] + (int) ($diamond[2] / 2), $diamond[1]];
}

function topOfDiamond(array $diamond): array
{
    return [$diamond[0], $diamond[1] - (int) ($diamond[3] / 2)];
}

function bottomOfDiamond(array $diamond): array
{
    return [$diamond[0], $diamond[1] + (int) ($diamond[3] / 2)];
}

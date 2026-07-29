<?php

declare(strict_types=1);

if (! extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$output = $root.'/docs/erd-sistem-lengkap-warehouse-monitoring.png';

$width = 2300;
$height = 1450;

$image = imagecreatetruecolor($width, $height);
imagealphablending($image, true);
imagesavealpha($image, true);

$rgb = [
    'bg' => [255, 255, 255],
    'title' => [15, 23, 42],
    'muted' => [71, 85, 105],
    'line' => [31, 41, 55],
    'blue' => [37, 99, 235],
    'blueSoft' => [239, 246, 255],
    'violet' => [79, 70, 229],
    'violetSoft' => [238, 242, 255],
    'green' => [5, 150, 105],
    'greenSoft' => [236, 253, 245],
    'orange' => [249, 115, 22],
    'orangeSoft' => [255, 247, 237],
    'white' => [255, 255, 255],
    'shadow' => [203, 213, 225],
    'legend' => [248, 250, 252],
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

drawCenteredText($image, $fontBold, 34, 0, $width, 58, 'ERD Utama Sistem Warehouse Monitoring PT ISS', $c['title']);
drawCenteredText($image, $fontRegular, 17, 0, $width, 92, 'Relasi antar entitas utama, stok, mutasi, serta tabel role-permission Spatie dalam satu diagram.', $c['muted']);

$entities = [
    // Spatie + user row
    'role' => box(120, 160, 170, 72, 'Role', 'entity'),
    'model_has_roles' => box(430, 160, 285, 82, 'model_has_roles\n(Pivot)', 'pivot'),
    'user' => box(850, 160, 175, 72, 'User', 'entity'),
    'model_has_permissions' => box(1185, 160, 330, 82, 'model_has_permissions\n(Pivot langsung)', 'pivot'),
    'permission' => box(1665, 160, 205, 72, 'Permission', 'entity'),
    'role_has_permissions' => box(1185, 350, 330, 82, 'role_has_permissions\n(Pivot)', 'pivot'),

    // Warehouse row
    'kategori' => box(120, 570, 205, 78, 'Kategori\nBarang', 'entity'),
    'barang_kategori' => box(455, 570, 290, 86, 'Barang Kategori\nBarang', 'pivot'),
    'barang' => box(875, 570, 175, 78, 'Barang', 'entity'),
    'mutasi' => box(1295, 570, 210, 88, 'Mutasi\nBarang', 'entity'),
    'lokasi' => box(455, 900, 190, 78, 'Lokasi', 'entity'),
    'barang_lokasi' => box(812, 900, 300, 88, 'Barang Lokasi\n/ Stok', 'stock'),
];

// Top role-permission area.
drawHorizontalRelation($image, $fontRegular, $entities['role'], $entities['model_has_roles'], [360, 201, 142, 66, 'Memiliki'], '1', 'N', $c);
drawHorizontalRelation($image, $fontRegular, $entities['model_has_roles'], $entities['user'], [785, 201, 150, 66, 'Dimiliki'], 'N', '1', $c);
drawHorizontalRelation($image, $fontRegular, $entities['user'], $entities['model_has_permissions'], [1100, 201, 170, 72, 'Permission\nLangsung'], '1', 'N', $c);
drawHorizontalRelation($image, $fontRegular, $entities['model_has_permissions'], $entities['permission'], [1595, 201, 170, 72, 'Mengacu\nPermission'], 'N', '1', $c);

drawPolylineRelation($image, $fontRegular, [
    bottom($entities['role']),
    [205, 391],
    [1185, 391],
], [675, 391, 165, 70, 'Role\nMemiliki Izin'], '1', 'N', $c, [220, 284], [1145, 366]);

drawPolylineRelation($image, $fontRegular, [
    right($entities['role_has_permissions']),
    [1768, 391],
    [1768, 232],
], [1595, 391, 170, 72, 'Mengacu\nPermission'], 'N', '1', $c, [1530, 365], [1788, 252]);

// Domain warehouse.
drawHorizontalRelation($image, $fontRegular, $entities['kategori'], $entities['barang_kategori'], [390, 609, 142, 66, 'Memiliki'], '1', 'N', $c);
drawHorizontalRelation($image, $fontRegular, $entities['barang_kategori'], $entities['barang'], [810, 609, 152, 66, 'Terhubung'], 'N', '1', $c);
drawHorizontalRelation($image, $fontRegular, $entities['barang'], $entities['mutasi'], [1185, 609, 150, 70, 'Dicatat\nPada'], '1', 'N', $c);

drawVerticalRelation($image, $fontRegular, $entities['barang'], $entities['barang_lokasi'], [962, 760, 150, 70, 'Memiliki\nStok'], '1', 'N', $c);
drawHorizontalRelation($image, $fontRegular, $entities['lokasi'], $entities['barang_lokasi'], [760, 939, 168, 72, 'Menyimpan\nStok'], '1', 'N', $c);

drawPolylineRelation($image, $fontRegular, [
    right($entities['lokasi']),
    [1210, 939],
    [1210, 645],
    left($entities['mutasi']),
], [1168, 790, 165, 72, 'Terlibat\nMutasi'], '1', 'N', $c, [675, 914], [1250, 655]);

drawPolylineRelation($image, $fontRegular, [
    bottom($entities['user']),
    [937, 500],
    [1400, 500],
    top($entities['mutasi']),
], [1175, 500, 160, 72, 'Mengelola\nMutasi'], '1', 'N', $c, [954, 258], [1418, 548]);

foreach ($entities as $entity) {
    drawEntity($image, $fontBold, $entity, $c);
}

drawNote($image, $fontRegular, $fontBold, 1550, 550, 560, 150, 'Catatan relasi mutasi', [
    'User pada mutasi berperan sebagai pencatat, pembuat, penyetuju, atau pembatal.',
    'Lokasi pada mutasi dapat menjadi lokasi asal maupun lokasi tujuan.',
    'Stok aktif disimpan pada Barang Lokasi / Stok.',
], $c);

drawNote($image, $fontRegular, $fontBold, 120, 1085, 620, 118, 'Catatan kategori barang', [
    'Kategori Barang tidak dihubungkan langsung ke Barang.',
    'Relasi kategori dan barang melewati pivot Barang Kategori Barang.',
], $c);

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

function box(int $x, int $y, int $w, int $h, string $text, string $type): array
{
    return compact('x', 'y', 'w', 'h', 'text', 'type');
}

function drawEntity(GdImage $image, ?string $font, array $entity, array $c): void
{
    ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'text' => $text, 'type' => $type] = $entity;

    $fill = match ($type) {
        'pivot' => $c['violetSoft'],
        'stock' => $c['greenSoft'],
        default => $c['blueSoft'],
    };
    $stroke = match ($type) {
        'pivot' => $c['violet'],
        'stock' => $c['green'],
        default => $c['blue'],
    };

    imagefilledrectangle($image, $x + 6, $y + 7, $x + $w + 6, $y + $h + 7, $c['shadow']);
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $fill);
    imagerectangle($image, $x, $y, $x + $w, $y + $h, $stroke);
    imagerectangle($image, $x + 1, $y + 1, $x + $w - 1, $y + $h - 1, $stroke);
    drawCenteredMultiline($image, $font, 17, $x, $y, $w, $h, $text, $c['title']);
}

function drawHorizontalRelation(
    GdImage $image,
    ?string $font,
    array $from,
    array $to,
    array $diamond,
    string $leftCardinality,
    string $rightCardinality,
    array $c,
    ?array $fromPoint = null,
    ?array $toPoint = null,
): void {
    [$dx, $dy, $dw, $dh, $label] = $diamond;
    $fromPoint ??= right($from);
    $toPoint ??= left($to);
    $leftDiamond = [$dx - (int) ($dw / 2), $dy];
    $rightDiamond = [$dx + (int) ($dw / 2), $dy];

    line($image, $fromPoint, $leftDiamond, $c);
    line($image, $rightDiamond, $toPoint, $c);
    drawCardinality($image, $font, [$fromPoint[0] + 20, $fromPoint[1] - 18], $leftCardinality, $c);
    drawCardinality($image, $font, [$toPoint[0] - 36, $toPoint[1] - 18], $rightCardinality, $c);
    drawDiamond($image, $font, $dx, $dy, $dw, $dh, $label, $c);
}

function drawVerticalRelation(GdImage $image, ?string $font, array $from, array $to, array $diamond, string $topCardinality, string $bottomCardinality, array $c): void
{
    [$dx, $dy, $dw, $dh, $label] = $diamond;
    $fromPoint = bottom($from);
    $toPoint = top($to);
    $topDiamond = [$dx, $dy - (int) ($dh / 2)];
    $bottomDiamond = [$dx, $dy + (int) ($dh / 2)];

    line($image, $fromPoint, $topDiamond, $c);
    line($image, $bottomDiamond, $toPoint, $c);
    drawCardinality($image, $font, [$fromPoint[0] + 18, $fromPoint[1] + 24], $topCardinality, $c);
    drawCardinality($image, $font, [$toPoint[0] + 18, $toPoint[1] - 16], $bottomCardinality, $c);
    drawDiamond($image, $font, $dx, $dy, $dw, $dh, $label, $c);
}

function drawPolylineRelation(GdImage $image, ?string $font, array $points, array $diamond, string $firstCardinality, string $lastCardinality, array $c, array $firstLabelAt, array $lastLabelAt): void
{
    [$dx, $dy, $dw, $dh, $label] = $diamond;
    $diamondCenter = [$dx, $dy];

    $before = [];
    $after = [];
    $inserted = false;
    for ($i = 0; $i < count($points) - 1; $i++) {
        $a = $points[$i];
        $b = $points[$i + 1];

        if (! $inserted && segmentNearDiamond($a, $b, $diamondCenter)) {
            if (abs($a[1] - $b[1]) < abs($a[0] - $b[0])) {
                $beforePoint = [$dx - (int) ($dw / 2), $dy];
                $afterPoint = [$dx + (int) ($dw / 2), $dy];
            } else {
                $beforePoint = [$dx, $dy - (int) ($dh / 2)];
                $afterPoint = [$dx, $dy + (int) ($dh / 2)];
            }
            line($image, $a, $beforePoint, $c);
            line($image, $afterPoint, $b, $c);
            $inserted = true;
            continue;
        }

        line($image, $a, $b, $c);
    }

    drawCardinality($image, $font, $firstLabelAt, $firstCardinality, $c);
    drawCardinality($image, $font, $lastLabelAt, $lastCardinality, $c);
    drawDiamond($image, $font, $dx, $dy, $dw, $dh, $label, $c);
}

function segmentNearDiamond(array $a, array $b, array $diamond): bool
{
    if ($a[1] === $b[1]) {
        return abs($a[1] - $diamond[1]) < 4 && min($a[0], $b[0]) <= $diamond[0] && max($a[0], $b[0]) >= $diamond[0];
    }

    if ($a[0] === $b[0]) {
        return abs($a[0] - $diamond[0]) < 4 && min($a[1], $b[1]) <= $diamond[1] && max($a[1], $b[1]) >= $diamond[1];
    }

    return false;
}

function line(GdImage $image, array $from, array $to, array $c): void
{
    imagesetthickness($image, 3);
    imageline($image, $from[0], $from[1], $to[0], $to[1], $c['line']);
    imagesetthickness($image, 1);
}

function drawDiamond(GdImage $image, ?string $font, int $cx, int $cy, int $w, int $h, string $text, array $c): void
{
    $points = [$cx, $cy - (int) ($h / 2), $cx + (int) ($w / 2), $cy, $cx, $cy + (int) ($h / 2), $cx - (int) ($w / 2), $cy];
    imagefilledpolygon($image, [$points[0] + 4, $points[1] + 5, $points[2] + 4, $points[3] + 5, $points[4] + 4, $points[5] + 5, $points[6] + 4, $points[7] + 5], $c['shadow']);
    imagefilledpolygon($image, $points, $c['orangeSoft']);
    imagepolygon($image, $points, $c['orange']);
    imagepolygon($image, [$cx, $cy - (int) ($h / 2) + 1, $cx + (int) ($w / 2) - 1, $cy, $cx, $cy + (int) ($h / 2) - 1, $cx - (int) ($w / 2) + 1, $cy], $c['orange']);
    drawCenteredMultiline($image, $font, 12, $cx - (int) ($w * 0.36), $cy - (int) ($h * 0.28), (int) ($w * 0.72), (int) ($h * 0.6), $text, $c['title']);
}

function drawCardinality(GdImage $image, ?string $font, array $point, string $text, array $c): void
{
    imagefilledellipse($image, $point[0] + 6, $point[1] - 5, 34, 26, $c['white']);
    drawText($image, $font, 15, $point[0], $point[1], $text, $c['title']);
}

function drawNote(GdImage $image, ?string $regular, ?string $bold, int $x, int $y, int $w, int $h, string $title, array $items, array $c): void
{
    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $c['orangeSoft']);
    imagerectangle($image, $x, $y, $x + $w, $y + $h, $c['orange']);
    drawText($image, $bold, 15, $x + 18, $y + 30, $title, $c['title']);
    $lineY = $y + 58;
    foreach ($items as $item) {
        drawText($image, $regular, 13, $x + 18, $lineY, '- '.$item, $c['title']);
        $lineY += 22;
    }
}

function drawLegend(GdImage $image, ?string $regular, ?string $bold, array $c): void
{
    $x = 120;
    $y = 1290;
    $w = 700;
    $h = 105;

    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $c['legend']);
    imagerectangle($image, $x, $y, $x + $w, $y + $h, $c['line']);
    drawText($image, $bold, 15, $x + 20, $y + 30, 'Keterangan:', $c['title']);
    drawText($image, $regular, 14, $x + 24, $y + 58, '1 = satu / one, N = banyak / many', $c['title']);
    drawText($image, $regular, 14, $x + 24, $y + 82, 'Kotak = entitas, diamond = relasi, pivot Spatie tetap ditampilkan sebagai entitas.', $c['title']);
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
    $lineHeight = $size + 8;
    $totalHeight = count($lines) * $lineHeight;
    $startY = (int) ($y + ($h - $totalHeight) / 2 + $size + 1);

    foreach ($lines as $i => $line) {
        $textWidth = textWidth($font, $size, $line);
        drawText($image, $font, $size, (int) ($x + ($w - $textWidth) / 2), $startY + ($i * $lineHeight), $line, $color);
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

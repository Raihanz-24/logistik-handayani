<?php

declare(strict_types=1);

if (! extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$output = $root.'/docs/erd-bab4-semua-table.png';

$width = 2300;
$height = 1500;

$image = imagecreatetruecolor($width, $height);
imagealphablending($image, true);
imagesavealpha($image, true);

$rgb = [
    'bg' => [255, 255, 255],
    'text' => [15, 23, 42],
    'muted' => [71, 85, 105],
    'line' => [31, 41, 55],
    'blue' => [37, 99, 235],
    'blueSoft' => [239, 246, 255],
    'pink' => [219, 39, 119],
    'pinkSoft' => [253, 242, 248],
    'green' => [5, 150, 105],
    'greenSoft' => [236, 253, 245],
    'orange' => [249, 115, 22],
    'orangeSoft' => [255, 247, 237],
    'white' => [255, 255, 255],
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

drawCenteredText($image, $fontBold, 32, 0, $width, 58, 'ERD Sistem Warehouse Monitoring PT ISS', $c['text']);
drawCenteredText($image, $fontRegular, 17, 0, $width, 92, 'Relasi tabel utama sistem mutasi, stok, kategori barang, serta role dan permission.', $c['muted']);

// Zona role dan permission.
drawZone($image, $fontBold, 80, 130, 2140, 470, 'A. Relasi Role dan Permission', $c);

// Zona data warehouse.
drawZone($image, $fontBold, 80, 650, 2140, 670, 'B. Relasi Data Warehouse dan Mutasi', $c);

$tables = [
    // Access control row.
    'roles' => tableBox(130, 225, 235, 78, 'roles', 'access'),
    'model_has_roles' => tableBox(495, 220, 290, 88, "model_has_roles\n(pivot)", 'pivot'),
    'users' => tableBox(910, 225, 235, 78, 'users', 'main'),
    'model_has_permissions' => tableBox(1270, 220, 345, 88, "model_has_permissions\n(pivot)", 'pivot'),
    'permissions' => tableBox(1805, 225, 265, 78, 'permissions', 'access'),
    'role_has_permissions' => tableBox(1100, 430, 345, 88, "role_has_permissions\n(pivot)", 'pivot'),

    // Warehouse row.
    'kategori_barangs' => tableBox(130, 805, 270, 84, 'kategori_barangs', 'main'),
    'barang_kategori_barang' => tableBox(535, 795, 335, 104, "barang_kategori_barang\n(pivot)", 'pivot'),
    'barangs' => tableBox(1005, 805, 240, 84, 'barangs', 'main'),
    'mutasis' => tableBox(1810, 795, 270, 104, 'mutasis', 'main'),
    'lokasis' => tableBox(535, 1125, 240, 84, 'lokasis', 'main'),
    'barang_lokasi' => tableBox(1005, 1110, 300, 114, "barang_lokasi\n(stok / pivot)", 'stock'),
];

// Role & permission relations.
connectHorizontal($image, $fontRegular, right($tables['roles']), left($tables['model_has_roles']), '1', 'N', 'memiliki', $c);
connectHorizontal($image, $fontRegular, right($tables['model_has_roles']), left($tables['users']), 'N', '1', 'dimiliki', $c);
connectHorizontal($image, $fontRegular, right($tables['users']), left($tables['model_has_permissions']), '1', 'N', 'akses langsung', $c);
connectHorizontal($image, $fontRegular, right($tables['model_has_permissions']), left($tables['permissions']), 'N', '1', 'mengacu', $c);

connectPolyline(
    $image,
    $fontRegular,
    [left($tables['roles']), [95, 264], [95, 560], [1272, 560], bottom($tables['role_has_permissions'])],
    '1',
    'N',
    [108, 248],
    [1290, 548],
    'memiliki permission',
    [650, 547],
    $c
);

connectPolyline(
    $image,
    $fontRegular,
    [bottom($tables['permissions']), [1938, 390], [1445, 390], right($tables['role_has_permissions'])],
    '1',
    'N',
    [1950, 333],
    [1468, 454],
    'dimiliki role',
    [1810, 347],
    $c
);

// Warehouse relations.
connectHorizontal($image, $fontRegular, right($tables['kategori_barangs']), left($tables['barang_kategori_barang']), '1', 'N', 'memiliki', $c);
connectHorizontal($image, $fontRegular, right($tables['barang_kategori_barang']), left($tables['barangs']), 'N', '1', 'dimiliki', $c);
connectHorizontal($image, $fontRegular, right($tables['barangs']), left($tables['mutasis']), '1', 'N', 'dicatat pada', $c);

connectPolyline(
    $image,
    $fontRegular,
    [bottom($tables['barangs']), [1125, 1000], [1155, 1000], top($tables['barang_lokasi'])],
    '1',
    'N',
    [1140, 930],
    [1140, 1080],
    'memiliki stok',
    [1125, 1000],
    $c
);

connectHorizontal($image, $fontRegular, right($tables['lokasis']), left($tables['barang_lokasi']), '1', 'N', 'menyimpan stok', $c);

connectPolyline(
    $image,
    $fontRegular,
    [top($tables['users']), [1028, 112], [2200, 112], [2200, 720], [1945, 720], top($tables['mutasis'])],
    '1',
    'N',
    [1045, 205],
    [1960, 765],
    'mengelola mutasi',
    [2070, 707],
    $c
);

connectPolyline(
    $image,
    $fontRegular,
    [bottom($tables['lokasis']), [655, 1275], [1945, 1275], bottom($tables['mutasis'])],
    '1',
    'N',
    [675, 1245],
    [1960, 940],
    'asal / tujuan mutasi',
    [1415, 1262],
    $c
);

foreach ($tables as $table) {
    drawTable($image, $fontBold, $table, $c);
}

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

function tableBox(int $x, int $y, int $w, int $h, string $text, string $type): array
{
    return compact('x', 'y', 'w', 'h', 'text', 'type');
}

function drawZone(GdImage $image, ?string $font, int $x, int $y, int $w, int $h, string $title, array $c): void
{
    imagerectangle($image, $x, $y, $x + $w, $y + $h, $c['shadow']);
    drawText($image, $font, 16, $x + 22, $y + 34, $title, $c['muted']);
}

function drawTable(GdImage $image, ?string $font, array $box, array $c): void
{
    $fill = match ($box['type']) {
        'pivot' => $c['pinkSoft'],
        'stock' => $c['greenSoft'],
        'access' => $c['orangeSoft'],
        default => $c['blueSoft'],
    };
    $stroke = match ($box['type']) {
        'pivot' => $c['pink'],
        'stock' => $c['green'],
        'access' => $c['orange'],
        default => $c['blue'],
    };

    imagefilledrectangle($image, $box['x'] + 6, $box['y'] + 7, $box['x'] + $box['w'] + 6, $box['y'] + $box['h'] + 7, $c['shadow']);
    imagefilledrectangle($image, $box['x'], $box['y'], $box['x'] + $box['w'], $box['y'] + $box['h'], $fill);
    imagerectangle($image, $box['x'], $box['y'], $box['x'] + $box['w'], $box['y'] + $box['h'], $stroke);
    imagerectangle($image, $box['x'] + 1, $box['y'] + 1, $box['x'] + $box['w'] - 1, $box['y'] + $box['h'] - 1, $stroke);
    drawCenteredMultiline($image, $font, 17, $box['x'], $box['y'], $box['w'], $box['h'], $box['text'], $c['text']);
}

function connectHorizontal(GdImage $image, ?string $font, array $from, array $to, string $fromCard, string $toCard, string $label, array $c): void
{
    line($image, $from, $to, $c);
    drawText($image, $font, 16, $from[0] + 22, $from[1] - 14, $fromCard, $c['text']);
    drawText($image, $font, 16, $to[0] - 40, $to[1] - 14, $toCard, $c['text']);
    drawLabel($image, $font, midpoint($from, $to), $label, $c);
}

function connectPolyline(
    GdImage $image,
    ?string $font,
    array $points,
    string $fromCard,
    string $toCard,
    array $fromCardAt,
    array $toCardAt,
    string $label,
    array $labelAt,
    array $c
): void {
    for ($i = 0; $i < count($points) - 1; $i++) {
        line($image, $points[$i], $points[$i + 1], $c);
    }

    drawText($image, $font, 16, $fromCardAt[0], $fromCardAt[1], $fromCard, $c['text']);
    drawText($image, $font, 16, $toCardAt[0], $toCardAt[1], $toCard, $c['text']);
    drawLabel($image, $font, $labelAt, $label, $c);
}

function line(GdImage $image, array $from, array $to, array $c): void
{
    imagesetthickness($image, 3);
    imageline($image, $from[0], $from[1], $to[0], $to[1], $c['line']);
    imagesetthickness($image, 1);
}

function drawLabel(GdImage $image, ?string $font, array $point, string $label, array $c): void
{
    $w = max(120, textWidth($font, 12, $label) + 24);
    $x = $point[0] - (int) ($w / 2);
    $y = $point[1] - 16;
    imagefilledrectangle($image, $x, $y, $x + $w, $y + 25, $c['white']);
    imagerectangle($image, $x, $y, $x + $w, $y + 25, $c['shadow']);
    drawCenteredText($image, $font, 12, $x, $w, $y + 17, $label, $c['muted']);
}

function drawLegend(GdImage $image, ?string $regular, ?string $bold, array $c): void
{
    $x = 110;
    $y = 1360;
    $w = 770;
    $h = 90;

    imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $c['blueSoft']);
    imagerectangle($image, $x, $y, $x + $w, $y + $h, $c['line']);
    drawText($image, $bold, 15, $x + 20, $y + 30, 'Keterangan:', $c['text']);
    drawText($image, $regular, 14, $x + 24, $y + 58, '1 = Satu, N = Banyak', $c['text']);
    drawText($image, $regular, 14, $x + 24, $y + 80, 'Biru = tabel utama, merah muda = pivot, hijau = stok, oranye = role/permission.', $c['text']);
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

function midpoint(array $a, array $b): array
{
    return [(int) (($a[0] + $b[0]) / 2), (int) (($a[1] + $b[1]) / 2)];
}

function drawCenteredMultiline(GdImage $image, ?string $font, int $size, int $x, int $y, int $w, int $h, string $text, int $color): void
{
    $lines = explode("\n", $text);
    $lineHeight = $size + 8;
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

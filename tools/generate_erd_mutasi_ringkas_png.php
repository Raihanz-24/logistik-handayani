<?php

declare(strict_types=1);

if (! extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$output = $root.'/docs/erd-mutasi-ringkas.png';

$width = 1500;
$height = 900;

$image = imagecreatetruecolor($width, $height);
imagealphablending($image, true);
imagesavealpha($image, true);

$rgb = [
    'bg' => [255, 255, 255],
    'text' => [15, 23, 42],
    'line' => [17, 24, 39],
    'blue' => [37, 99, 235],
    'orange' => [249, 115, 22],
    'white' => [255, 255, 255],
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

drawCenteredText($image, $fontBold, 28, 0, $width, 55, 'ERD Ringkas Proses Mutasi Barang', $c['text']);

$entities = [
    'barang' => rect(650, 120, 210, 78, 'Barang'),
    'kategori' => rect(1070, 120, 240, 78, 'Kategori Barang'),

    'user' => rect(120, 390, 210, 78, 'User'),
    'mutasi' => rect(650, 390, 210, 78, 'Mutasi Barang'),
    'stok' => rect(1070, 390, 240, 78, "barang_lokasi\n(stok)"),

    'lokasi' => rect(650, 665, 210, 78, 'Lokasi'),
];

$relations = [
    'barang_kategori' => diamondBox(920, 159, 180, 86, 'Dikategorikan'),
    'barang_mutasi' => diamondBox(755, 285, 180, 86, 'Memiliki'),
    'user_mutasi' => diamondBox(490, 429, 180, 86, 'Mengelola'),
    'mutasi_stok' => diamondBox(920, 429, 180, 86, 'Memperbarui'),
    'mutasi_lokasi' => diamondBox(755, 560, 180, 86, 'Berada di'),
];

// Horizontal top: Barang - Dikelompokkan - Kategori Barang
connectHorizontal($image, $fontRegular, right($entities['barang']), left($relations['barang_kategori']), 'N', $c);
connectHorizontal($image, $fontRegular, right($relations['barang_kategori']), left($entities['kategori']), '1', $c);

// Vertical: Barang - Memiliki - Mutasi Barang
connectVertical($image, $fontRegular, bottom($entities['barang']), top($relations['barang_mutasi']), '1', $c);
connectVertical($image, $fontRegular, bottom($relations['barang_mutasi']), top($entities['mutasi']), 'N', $c);

// Horizontal center: User - Mencatat - Mutasi Barang
connectHorizontal($image, $fontRegular, right($entities['user']), left($relations['user_mutasi']), '1', $c);
connectHorizontal($image, $fontRegular, right($relations['user_mutasi']), left($entities['mutasi']), 'N', $c);

// Horizontal center: Mutasi Barang - Memperbarui - Stok Barang
connectHorizontal($image, $fontRegular, right($entities['mutasi']), left($relations['mutasi_stok']), 'N', $c);
connectHorizontal($image, $fontRegular, right($relations['mutasi_stok']), left($entities['stok']), '1', $c);

// Vertical: Mutasi Barang - Terjadi di - Lokasi
connectVertical($image, $fontRegular, bottom($entities['mutasi']), top($relations['mutasi_lokasi']), 'N', $c);
connectVertical($image, $fontRegular, bottom($relations['mutasi_lokasi']), top($entities['lokasi']), '1', $c);

foreach ($relations as $relation) {
    drawDiamond($image, $fontRegular, $relation, $c);
}

foreach ($entities as $entity) {
    drawEntity($image, $fontRegular, $entity, $c);
}

drawLegend($image, $fontRegular, $c);

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

function rect(int $x, int $y, int $w, int $h, string $text): array
{
    return compact('x', 'y', 'w', 'h', 'text');
}

function diamondBox(int $cx, int $cy, int $w, int $h, string $text): array
{
    return [
        'x' => $cx - (int) ($w / 2),
        'y' => $cy - (int) ($h / 2),
        'w' => $w,
        'h' => $h,
        'cx' => $cx,
        'cy' => $cy,
        'text' => $text,
    ];
}

function drawEntity(GdImage $image, ?string $font, array $box, array $c): void
{
    imagerectangle($image, $box['x'], $box['y'], $box['x'] + $box['w'], $box['y'] + $box['h'], $c['blue']);
    imagerectangle($image, $box['x'] + 1, $box['y'] + 1, $box['x'] + $box['w'] - 1, $box['y'] + $box['h'] - 1, $c['blue']);
    drawCenteredText($image, $font, 15, $box['x'], $box['w'], $box['y'] + 48, $box['text'], $c['text']);
}

function drawDiamond(GdImage $image, ?string $font, array $box, array $c): void
{
    $cx = $box['cx'];
    $cy = $box['cy'];
    $w = $box['w'];
    $h = $box['h'];

    $points = [
        $cx, $cy - (int) ($h / 2),
        $cx + (int) ($w / 2), $cy,
        $cx, $cy + (int) ($h / 2),
        $cx - (int) ($w / 2), $cy,
    ];

    imagepolygon($image, $points, $c['orange']);
    drawCenteredMultiline($image, $font, 13, $cx - 64, $cy - 18, 128, 38, $box['text'], $c['text']);
}

function connectHorizontal(GdImage $image, ?string $font, array $from, array $to, string $cardinality, array $c): void
{
    imagesetthickness($image, 2);
    imageline($image, $from[0], $from[1], $to[0], $to[1], $c['line']);
    imagesetthickness($image, 1);

    $x = (int) (($from[0] + $to[0]) / 2);
    $y = $from[1] - 10;
    drawCenteredText($image, $font, 14, $x - 12, 24, $y, $cardinality, $c['text']);
}

function connectVertical(GdImage $image, ?string $font, array $from, array $to, string $cardinality, array $c): void
{
    imagesetthickness($image, 2);
    imageline($image, $from[0], $from[1], $to[0], $to[1], $c['line']);
    imagesetthickness($image, 1);

    $x = $from[0] + 10;
    $y = (int) (($from[1] + $to[1]) / 2);
    drawCenteredText($image, $font, 14, $x - 10, 24, $y, $cardinality, $c['text']);
}

function drawLegend(GdImage $image, ?string $font, array $c): void
{
    $x = 120;
    $y = 800;
    $w = 330;
    $h = 56;

    imagerectangle($image, $x, $y, $x + $w, $y + $h, $c['line']);
    drawText($image, $font, 12, $x + 16, $y + 24, 'Keterangan: 1 = Satu, N = Banyak', $c['text']);
    drawText($image, $font, 12, $x + 16, $y + 44, 'Kotak = Entitas, Diamond = Relasi', $c['text']);
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
    $lineHeight = $size + 5;
    $totalHeight = count($lines) * $lineHeight;
    $startY = (int) ($y + ($h - $totalHeight) / 2 + $size);

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

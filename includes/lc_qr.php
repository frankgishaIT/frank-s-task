<?php
/**
 * NEW FILE: includes/lc_qr.php — small QR code generator (no library needed): byte mode, error correction M,
 * versions 1–10 (up to 213 characters, enough for a verification link). Used on certificates and transcripts.
 * lc_qr_png($text) returns PNG bytes; lc_qr_data_uri($text) returns "data:image/png;base64,..." for <img> and mPDF.
 */

function lc_qr_matrix(string $text): array
{
    // version => [data codewords per block for each block..., EC codewords per block]
    $T = [1 => [[16], 10], 2 => [[28], 16], 3 => [[44], 26], 4 => [[32, 32], 18], 5 => [[43, 43], 24], 6 => [[27, 27, 27, 27], 16],
          7 => [[31, 31, 31, 31], 18], 8 => [[38, 38, 39, 39], 22], 9 => [[36, 36, 36, 37, 37], 22], 10 => [[43, 43, 43, 43, 44], 26]];
    $ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];
    $bytes = array_values(unpack('C*', $text) ?: []);
    $n = count($bytes);
    $ver = 0;
    foreach ($T as $v => [$blocks, $ec]) {
        $cap = array_sum($blocks) * 8;
        if (4 + ($v < 10 ? 8 : 16) + 8 * $n <= $cap) { $ver = $v; break; }
    }
    if (!$ver) { throw new RuntimeException('Text too long for the QR code.'); }
    [$blocks, $ecLen] = $T[$ver];
    $capBytes = array_sum($blocks);

    // Bit stream: mode 0100, length, data, terminator, padding.
    $bits = [];
    $put = function (int $val, int $len) use (&$bits) { for ($i = $len - 1; $i >= 0; $i--) { $bits[] = ($val >> $i) & 1; } };
    $put(4, 4); $put($n, $ver < 10 ? 8 : 16);
    foreach ($bytes as $b) { $put($b, 8); }
    $put(0, min(4, $capBytes * 8 - count($bits)));
    while (count($bits) % 8) { $bits[] = 0; }
    $data = [];
    for ($i = 0; $i < count($bits); $i += 8) { $v = 0; for ($j = 0; $j < 8; $j++) { $v = ($v << 1) | $bits[$i + $j]; } $data[] = $v; }
    for ($p = 0; count($data) < $capBytes; $p++) { $data[] = $p % 2 ? 0x11 : 0xEC; }

    // Reed-Solomon over GF(256), polynomial 0x11D.
    $mul = function (int $x, int $y): int { $z = 0; for ($i = 7; $i >= 0; $i--) { $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF; $z ^= (($y >> $i) & 1) * $x; } return $z; };
    $div = array_fill(0, $ecLen, 0); $div[$ecLen - 1] = 1; $root = 1;
    for ($i = 0; $i < $ecLen; $i++) {
        for ($j = 0; $j < $ecLen; $j++) { $div[$j] = $mul($div[$j], $root); if ($j + 1 < $ecLen) { $div[$j] ^= $div[$j + 1]; } }
        $root = $mul($root, 2);
    }
    $dataBlocks = []; $ecBlocks = []; $k = 0;
    foreach ($blocks as $len) {
        $blk = array_slice($data, $k, $len); $k += $len;
        $res = array_fill(0, $ecLen, 0);
        foreach ($blk as $b) { $f = $b ^ array_shift($res); $res[] = 0; for ($i = 0; $i < $ecLen; $i++) { $res[$i] ^= $mul($div[$i], $f); } }
        $dataBlocks[] = $blk; $ecBlocks[] = $res;
    }
    $all = [];
    for ($i = 0; $i < max($blocks); $i++) { foreach ($dataBlocks as $blk) { if ($i < count($blk)) { $all[] = $blk[$i]; } } }
    for ($i = 0; $i < $ecLen; $i++) { foreach ($ecBlocks as $blk) { $all[] = $blk[$i]; } }

    // Function patterns.
    $size = 17 + 4 * $ver;
    $m = array_fill(0, $size, array_fill(0, $size, 0));
    $fn = array_fill(0, $size, array_fill(0, $size, false));
    $set = function (int $x, int $y, bool $dark) use (&$m, &$fn) { $m[$y][$x] = $dark ? 1 : 0; $fn[$y][$x] = true; };
    for ($i = 0; $i < $size; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) { for ($dx = -4; $dx <= 4; $dx++) {
            $x = $cx + $dx; $y = $cy + $dy; $d = max(abs($dx), abs($dy));
            if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) { $set($x, $y, $d !== 2 && $d !== 4); }
        } }
    }
    $al = $ALIGN[$ver]; $last = count($al) - 1;
    foreach ($al as $i => $ax) { foreach ($al as $j => $ay) {
        if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) { continue; }
        for ($dy = -2; $dy <= 2; $dy++) { for ($dx = -2; $dx <= 2; $dx++) { $set($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1); } }
    } }
    $drawFormat = function (int $mask) use ($set, $size) {
        $d = (0 << 3) | $mask; $rem = $d;   // level M = 00
        for ($i = 0; $i < 10; $i++) { $rem = ($rem << 1) ^ (($rem >> 9) * 0x537); }
        $b = (($d << 10) | $rem) ^ 0x5412;
        $g = function ($i) use ($b) { return (($b >> $i) & 1) === 1; };
        for ($i = 0; $i <= 5; $i++) { $set(8, $i, $g($i)); }
        $set(8, 7, $g(6)); $set(8, 8, $g(7)); $set(7, 8, $g(8));
        for ($i = 9; $i < 15; $i++) { $set(14 - $i, 8, $g($i)); }
        for ($i = 0; $i < 8; $i++) { $set($size - 1 - $i, 8, $g($i)); }
        for ($i = 8; $i < 15; $i++) { $set(8, $size - 15 + $i, $g($i)); }
        $set(8, $size - 8, true);
    };
    $drawFormat(0);
    if ($ver >= 7) {
        $rem = $ver; for ($i = 0; $i < 12; $i++) { $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25); }
        $b = ($ver << 12) | $rem;
        for ($i = 0; $i < 18; $i++) { $bit = (($b >> $i) & 1) === 1; $a = $size - 11 + $i % 3; $c = intdiv($i, 3); $set($a, $c, $bit); $set($c, $a, $bit); }
    }

    // Data placement (zigzag).
    $i = 0; $total = count($all) * 8;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) { $right = 5; }
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j; $up = (($right + 1) & 2) === 0; $y = $up ? $size - 1 - $vert : $vert;
                if (!$fn[$y][$x] && $i < $total) { $m[$y][$x] = ($all[$i >> 3] >> (7 - ($i & 7))) & 1; $i++; }
            }
        }
    }

    // Mask with the lowest penalty.
    $cond = [function ($x, $y) { return ($x + $y) % 2 === 0; }, function ($x, $y) { return $y % 2 === 0; }, function ($x, $y) { return $x % 3 === 0; },
             function ($x, $y) { return ($x + $y) % 3 === 0; }, function ($x, $y) { return (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; },
             function ($x, $y) { return ($x * $y) % 2 + ($x * $y) % 3 === 0; }, function ($x, $y) { return (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0; },
             function ($x, $y) { return (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0; }];
    $apply = function (array $mm, int $mask) use ($cond, $fn, $size) {
        for ($y = 0; $y < $size; $y++) { for ($x = 0; $x < $size; $x++) { if (!$fn[$y][$x] && $cond[$mask]($x, $y)) { $mm[$y][$x] ^= 1; } } }
        return $mm;
    };
    $penalty = function (array $mm) use ($size): int {
        $p = 0; $dark = 0;
        $lines = [];
        for ($y = 0; $y < $size; $y++) { $lines[] = $mm[$y]; $col = []; for ($x = 0; $x < $size; $x++) { $col[] = $mm[$x][$y]; } $lines[] = $col; }
        foreach ($lines as $ln) {
            $run = 1;
            for ($k = 1; $k <= $size; $k++) {
                if ($k < $size && $ln[$k] === $ln[$k - 1]) { $run++; continue; }
                if ($run >= 5) { $p += 3 + $run - 5; }
                $run = 1;
            }
            $s = implode('', $ln);
            $p += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
        }
        for ($y = 0; $y < $size; $y++) { for ($x = 0; $x < $size; $x++) {
            $dark += $mm[$y][$x];
            if ($x < $size - 1 && $y < $size - 1) { $c = $mm[$y][$x]; if ($c === $mm[$y][$x + 1] && $c === $mm[$y + 1][$x] && $c === $mm[$y + 1][$x + 1]) { $p += 3; } }
        } }
        $tot = $size * $size;
        $p += max(0, (int) ceil(abs($dark * 20 - $tot * 10) / $tot) - 1) * 10;
        return $p;
    };
    $best = null; $bestP = PHP_INT_MAX; $bestMask = 0;
    for ($mask = 0; $mask < 8; $mask++) {
        $drawFormat($mask);
        $mm = $apply($m, $mask);
        $pp = $penalty($mm);
        if ($pp < $bestP) { $bestP = $pp; $best = $mm; $bestMask = $mask; }
    }
    $m = $best;
    // Format bits of the chosen mask on the final matrix.
    $tmp = $m; $set2 = function (int $x, int $y, bool $dark) use (&$tmp) { $tmp[$y][$x] = $dark ? 1 : 0; };
    $d = $bestMask; $rem = $d;
    for ($i = 0; $i < 10; $i++) { $rem = ($rem << 1) ^ (($rem >> 9) * 0x537); }
    $b = (($d << 10) | $rem) ^ 0x5412;
    for ($i = 0; $i <= 5; $i++) { $set2(8, $i, (($b >> $i) & 1) === 1); }
    $set2(8, 7, (($b >> 6) & 1) === 1); $set2(8, 8, (($b >> 7) & 1) === 1); $set2(7, 8, (($b >> 8) & 1) === 1);
    for ($i = 9; $i < 15; $i++) { $set2(14 - $i, 8, (($b >> $i) & 1) === 1); }
    for ($i = 0; $i < 8; $i++) { $set2($size - 1 - $i, 8, (($b >> $i) & 1) === 1); }
    for ($i = 8; $i < 15; $i++) { $set2(8, $size - 15 + $i, (($b >> $i) & 1) === 1); }
    $set2(8, $size - 8, true);
    return $tmp;
}

function lc_qr_png(string $text, int $scale = 6, int $margin = 4): string
{
    $m = lc_qr_matrix($text);
    $size = count($m); $px = ($size + 2 * $margin) * $scale;
    $im = imagecreate($px, $px);
    $white = imagecolorallocate($im, 255, 255, 255); $black = imagecolorallocate($im, 0, 0, 0);
    imagefill($im, 0, 0, $white);
    for ($y = 0; $y < $size; $y++) { for ($x = 0; $x < $size; $x++) {
        if ($m[$y][$x]) { imagefilledrectangle($im, ($x + $margin) * $scale, ($y + $margin) * $scale, ($x + $margin + 1) * $scale - 1, ($y + $margin + 1) * $scale - 1, $black); }
    } }
    ob_start(); imagepng($im); imagedestroy($im);
    return (string) ob_get_clean();
}

function lc_qr_data_uri(string $text, int $scale = 6): string { return 'data:image/png;base64,' . base64_encode(lc_qr_png($text, $scale)); }
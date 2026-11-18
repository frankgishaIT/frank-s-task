<?php
/**
 * NEW FILE: includes/image_kit.php — IMAGE GUIDELINES, UPLOAD PREVIEW / CROP and OPTIMISATION for MY MOTIVE and the public website.
 *
 *   IMG_SPECS      one specification per image category (not one size for everything): recommended size, aspect ratio(s),
 *                  minimum size, formats, maximum file size, where it is used.
 *   img_input()    attributes for an <input type="file">: before saving, the person sees the guideline, a PREVIEW in the
 *                  real proportions, can MOVE / ZOOM the picture inside the frame (and choose a ratio where several are
 *                  allowed), and gets a warning for small or wrong files. The browser then sends the cropped, resized,
 *                  compressed picture (the original is never stretched).
 *   img_optimize_upload()   server-side safety net (also without JavaScript): checks the real file type, centre-crops to the
 *                  category's ratio when needed, scales down to the recommended size and re-compresses (removes camera data).
 *   Display:       pictures are shown in frames of the same ratio with object-fit: cover — never stretched (img_css()).
 */

const IMG_SPECS = [
    'profile'        => ['label' => 'Profile pictures (staff and customers)', 'w' => 600, 'h' => 600, 'ratios' => ['1:1'], 'min' => [300, 300], 'mb' => 2, 'types' => ['jpg', 'png', 'webp'], 'out' => 'jpeg', 'use' => 'Top bar, staff lists, profiles (shown round or square).'],
    'course'         => ['label' => 'Course images (thumbnail and banner)', 'w' => 1600, 'h' => 900, 'ratios' => ['16:9'], 'min' => [960, 540], 'mb' => 3, 'types' => ['jpg', 'png', 'webp'], 'out' => 'jpeg', 'use' => 'Course cards, course page banner and link previews (WhatsApp, Facebook, LinkedIn).'],
    'website_banner' => ['label' => 'Website banners', 'w' => 1920, 'h' => 640, 'ratios' => ['3:1'], 'min' => [1440, 480], 'mb' => 3, 'types' => ['jpg', 'webp'], 'out' => 'jpeg', 'use' => 'Wide banners at the top of public pages. Keep text and faces in the middle 60%: phones show the centre.'],
    'product'        => ['label' => 'Product images', 'w' => 1000, 'h' => 1000, 'ratios' => ['1:1'], 'min' => [600, 600], 'mb' => 2, 'types' => ['jpg', 'png', 'webp'], 'out' => 'jpeg', 'use' => 'Product lists and price lists. Plain light background, product centred.'],
    'news'           => ['label' => 'News and announcement images', 'w' => 1200, 'h' => 675, 'ratios' => ['16:9', '4:3'], 'min' => [800, 450], 'mb' => 3, 'types' => ['jpg', 'png', 'webp'], 'out' => 'jpeg', 'use' => 'Announcements, messages and posts.'],
    'gallery'        => ['label' => 'Gallery images', 'w' => 1600, 'h' => 1200, 'ratios' => ['4:3', '3:2', '1:1', '16:9'], 'min' => [800, 600], 'mb' => 5, 'types' => ['jpg', 'png', 'webp'], 'out' => 'jpeg', 'use' => 'Photo galleries (events, training sessions, facilities).'],
    'logo'           => ['label' => 'Logos and signatures (documents)', 'w' => 700, 'h' => 0, 'ratios' => ['free'], 'min' => [200, 0], 'mb' => 1, 'types' => ['png', 'jpg'], 'out' => 'png', 'use' => 'Certificates, transcripts, invoices. Transparent PNG recommended.'],
    'document_photo' => ['label' => 'Photos of documents (evidence, receipts)', 'w' => 2000, 'h' => 0, 'ratios' => ['free'], 'min' => [800, 0], 'mb' => 5, 'types' => ['jpg', 'png', 'pdf'], 'out' => 'jpeg', 'use' => 'Proofs and scans: never cropped, only made lighter. Prefer PDF for several pages.'],
];

function img_spec(string $key): array { return IMG_SPECS[$key] ?? IMG_SPECS['gallery']; }

/** "1600 × 900 px (16:9) · JPG, PNG or WebP · max 3 MB". */
function img_guideline(string $key): string
{
    $s = img_spec($key);
    $size = $s['h'] ? $s['w'] . ' × ' . $s['h'] . ' px' : 'at least ' . $s['min'][0] . ' px wide';
    $r = $s['ratios'] === ['free'] ? 'any shape' : implode(' or ', $s['ratios']);
    return 'Recommended ' . $size . ' (' . $r . ') · ' . strtoupper(implode(', ', $s['types'])) . ' · max ' . $s['mb'] . ' MB';
}

/** Attributes to put on an <input type="file"> so the browser shows the guideline, preview and crop tool. */
function img_input(string $key): string
{
    $s = img_spec($key);
    $j = ['key' => $key, 'label' => $s['label'], 'w' => $s['w'], 'h' => $s['h'], 'ratios' => $s['ratios'], 'min' => $s['min'], 'mb' => $s['mb'], 'types' => $s['types'], 'out' => $s['out'], 'guide' => img_guideline($key)];
    return ' data-img-spec="' . htmlspecialchars(json_encode($j), ENT_QUOTES, 'UTF-8') . '"';
}

/** Ratio "16:9" → 1.777…, 'free' → 0. */
function img_ratio(string $r): float { if ($r === 'free' || strpos($r, ':') === false) { return 0.0; } [$a, $b] = array_map('floatval', explode(':', $r)); return $b > 0 ? $a / $b : 0.0; }

/**
 * Server side: optimises an uploaded image IN PLACE (same temporary file, so the normal upload code continues unchanged).
 * Returns null when fine, or an error message. Non-images (PDF) are left alone. Never makes a picture larger.
 */
function img_optimize_upload(?array $file, string $key): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string) $file['tmp_name'])) { return null; }
    $s = img_spec($key);
    $path = (string) $file['tmp_name'];
    if ((int) $file['size'] > $s['mb'] * 1048576 * 3) { return 'The picture is too large (max ' . $s['mb'] . ' MB).'; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { return null; } // not a picture: the normal checks decide
    if (!function_exists('imagecreatetruecolor')) { return null; }
    $info = @getimagesize($path);
    if (!$info || $info[0] < 1 || $info[1] < 1) { return 'This file is not a valid picture.'; }
    [$w, $h] = $info;
    if ($w * $h > 40000000) { return 'The picture has too many pixels (max 40 megapixels).'; }
    $src = $mime === 'image/png' ? @imagecreatefrompng($path) : ($mime === 'image/webp' ? @imagecreatefromwebp($path) : @imagecreatefromjpeg($path));
    if (!$src) { return 'This picture could not be read. Save it again as JPG or PNG.'; }
    // Photos from phones: turn upright (EXIF orientation).
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        if (in_array($o, [3, 6, 8], true)) { $r = imagerotate($src, [3 => 180, 6 => -90, 8 => 90][$o], 0); if ($r) { imagedestroy($src); $src = $r; $w = imagesx($src); $h = imagesy($src); } }
    }
    // Ratio: if the picture does not have one of the allowed ratios (no crop was made in the browser), keep the centre.
    $cx = 0; $cy = 0; $cw = $w; $ch = $h;
    $ratios = array_filter(array_map('img_ratio', $s['ratios']));
    if ($ratios) {
        $cur = $w / $h; $best = null;
        foreach ($ratios as $r) { if ($best === null || abs(log($r / $cur)) < abs(log($best / $cur))) { $best = $r; } }
        if (abs($best / $cur - 1) > 0.02) {
            if ($cur > $best) { $cw = (int) round($h * $best); $cx = (int) (($w - $cw) / 2); } else { $ch = (int) round($w / $best); $cy = (int) (($h - $ch) / 2); }
        }
    }
    $scale = $ratios ? min(1, $s['w'] / $cw) : min(1, $s['w'] / max($cw, $ch)); // never enlarged
    $tw = max(1, (int) round($cw * $scale)); $th = max(1, (int) round($ch * $scale));
    $dst = imagecreatetruecolor($tw, $th);
    $png = $s['out'] === 'png' || ($mime === 'image/png' && $s['out'] === 'keep');
    if ($png) { imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127)); }
    else { imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); } // transparent parts become white in JPEG
    imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $tw, $th, $cw, $ch);
    imagedestroy($src);
    ob_start(); $png ? imagepng($dst, null, 7) : imagejpeg($dst, null, 85); $bytes = (string) ob_get_clean();
    imagedestroy($dst);
    if ($bytes === '') { return null; }
    // Keep the original when re-saving would make a small, already-right file bigger.
    if ($tw === $w && $th === $h && strlen($bytes) >= (int) $file['size'] && (($png && $mime === 'image/png') || (!$png && $mime === 'image/jpeg'))) { return null; }
    file_put_contents($path, $bytes);
    clearstatcache(true, $path);
    return null;
}

/** Applies img_optimize_upload() to $_FILES[$field] and updates its size (call before the normal upload code). */
function img_prepare_upload(string $field, string $key): ?string
{
    if (empty($_FILES[$field]) || is_array($_FILES[$field]['name'] ?? null)) { return null; }
    $err = img_optimize_upload($_FILES[$field], $key);
    if ($err === null && ($_FILES[$field]['error'] ?? 1) === UPLOAD_ERR_OK && is_file((string) $_FILES[$field]['tmp_name'])) {
        $_FILES[$field]['size'] = filesize((string) $_FILES[$field]['tmp_name']);
        $_FILES[$field]['name'] = preg_replace('/\.(png|webp|jpe?g)$/i', '', (string) $_FILES[$field]['name']) . '.' . (img_spec($key)['out'] === 'png' ? 'png' : 'jpg');
    }
    return $err;
}

/** CSS for pictures shown in fixed-ratio frames (never stretched). */
function img_css(): string
{
    return '.rm-ratio{position:relative;overflow:hidden;background:#EEF3FF}.rm-ratio>img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}'
        . '.rm-r16x9{aspect-ratio:16/9}.rm-r1x1{aspect-ratio:1/1}.rm-r4x3{aspect-ratio:4/3}.rm-r3x1{aspect-ratio:3/1}';
}

/** The browser part (preview, crop, checks): print once on a page that has img_input() fields. */
function img_kit_js(): string
{
    static $done = false;
    if ($done) { return ''; }
    $done = true;
    return <<<'HTML'
<style>
.ik-ov{position:fixed;inset:0;background:rgba(15,20,40,.72);z-index:3000;display:flex;align-items:center;justify-content:center;padding:12px;font-family:inherit}
.ik-box{background:#fff;border-radius:14px;width:min(720px,100%);max-height:100%;overflow:auto;padding:14px;box-shadow:0 12px 40px rgba(0,0,0,.3)}
.ik-h{font-weight:700;font-size:15px;margin-bottom:2px;color:#0B1A8C}.ik-g{font-size:12.5px;color:#5B6275;margin-bottom:8px}
.ik-stage{position:relative;background:#1E2333;border-radius:10px;overflow:hidden;touch-action:none;user-select:none;margin:0 auto;cursor:grab}
.ik-stage img{position:absolute;left:0;top:0;max-width:none;transform-origin:0 0;pointer-events:none}
.ik-frame{position:absolute;inset:0;box-shadow:inset 0 0 0 2px rgba(255,255,255,.9);pointer-events:none}
.ik-frame::before,.ik-frame::after{content:"";position:absolute;border:0 dashed rgba(255,255,255,.35)}.ik-frame::before{left:33.33%;right:33.33%;top:0;bottom:0;border-left-width:1px;border-right-width:1px}.ik-frame::after{top:33.33%;bottom:33.33%;left:0;right:0;border-top-width:1px;border-bottom-width:1px}
.ik-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px;font-size:13px}.ik-row input[type=range]{flex:1;min-width:140px}
.ik-btn{border:1px solid #D8DCE8;background:#fff;border-radius:8px;padding:5px 12px;font-size:13px;cursor:pointer}.ik-btn.on,.ik-btn.pri{background:#1E2FE0;border-color:#1E2FE0;color:#fff}
.ik-warn{font-size:12.5px;color:#9A5B00;background:#FFF6E5;border-radius:8px;padding:6px 10px;margin-top:8px}.ik-info{font-size:12px;color:#5B6275;margin-top:6px}
.ik-note{font-size:12px;color:#5B6275;margin-top:4px}.ik-thumb{display:block;margin-top:6px;border-radius:8px;max-width:220px;max-height:140px;object-fit:contain;background:#EEF3FF}
</style>
<script>
(function () {
    function el(t, c, h) { var e = document.createElement(t); if (c) e.className = c; if (h != null) e.innerHTML = h; return e; }
    function ratio(r) { if (r === 'free') return 0; var p = r.split(':'); return +p[0] / +p[1]; }
    function setup(inp) {
        if (inp.__ik) return; inp.__ik = 1;
        var spec = JSON.parse(inp.getAttribute('data-img-spec'));
        var note = el('div', 'ik-note', spec.guide + ' · you can position the picture before saving'); inp.insertAdjacentElement('afterend', note);
        var thumb = null;
        inp.addEventListener('change', function (ev) {
            if (inp.__skip) { inp.__skip = 0; return; }
            var f = inp.files && inp.files[0]; if (!f) return;
            var ext = (f.name.split('.').pop() || '').toLowerCase().replace('jpeg', 'jpg');
            if (spec.types.indexOf(ext) < 0 && !(ext === 'pdf' && spec.types.indexOf('pdf') >= 0)) { alert('Please choose a ' + spec.types.join(', ').toUpperCase() + ' file.'); inp.value = ''; return; }
            if (ext === 'pdf' || !/^image\/(jpeg|png|webp)$/.test(f.type)) return; // PDF: sent as it is
            if (f.size > spec.mb * 3 * 1048576) { alert('This picture is too large (max ' + spec.mb + ' MB).'); inp.value = ''; return; }
            ev.stopImmediatePropagation();
            var url = URL.createObjectURL(f), img = new Image();
            img.onload = function () { open(f, img, url); };
            img.onerror = function () { alert('This picture cannot be read. Save it as JPG or PNG and try again.'); inp.value = ''; };
            img.src = url;
        }, true);
        function open(file, img, url) {
            var W = img.naturalWidth, H = img.naturalHeight, rs = spec.ratios, r = ratio(rs[0]) || W / H;
            var ov = el('div', 'ik-ov'), box = el('div', 'ik-box'); ov.appendChild(box);
            box.appendChild(el('div', 'ik-h', spec.label)); box.appendChild(el('div', 'ik-g', spec.guide));
            var rb = el('div', 'ik-row'); if (rs.length > 1) { rb.appendChild(el('span', '', 'Shape:')); rs.forEach(function (x, i) { var b = el('button', 'ik-btn' + (i ? '' : ' on'), x); b.type = 'button'; b.onclick = function () { r = ratio(x); Array.prototype.forEach.call(rb.querySelectorAll('.ik-btn'), function (y) { y.classList.toggle('on', y === b); }); layout(true); }; rb.appendChild(b); }); box.appendChild(rb); }
            var st = el('div', 'ik-stage'), pic = new Image(); pic.src = url; st.appendChild(pic); var fr = el('div', 'ik-frame'); st.appendChild(fr); box.appendChild(st);
            var zr = el('div', 'ik-row', '<span>Zoom</span>'), z = document.createElement('input'); z.type = 'range'; z.min = 100; z.max = 400; z.value = 100; zr.appendChild(z);
            var fit = el('button', 'ik-btn', 'Centre'); fit.type = 'button'; zr.appendChild(fit); box.appendChild(zr);
            var info = el('div', 'ik-info'); box.appendChild(info); var warn = el('div', 'ik-warn'); warn.style.display = 'none'; box.appendChild(warn);
            var act = el('div', 'ik-row'); act.style.justifyContent = 'flex-end'; var cancel = el('button', 'ik-btn', 'Cancel'), ok = el('button', 'ik-btn pri', 'Use this picture'); cancel.type = ok.type = 'button'; act.appendChild(cancel); act.appendChild(ok); box.appendChild(act);
            document.body.appendChild(ov);
            var SW, SH, base, k, ox, oy;
            function clamp() { var iw = W * base * k, ih = H * base * k; ox = Math.min(0, Math.max(SW - iw, ox)); oy = Math.min(0, Math.max(SH - ih, oy)); }
            function draw() { clamp(); pic.style.transform = 'translate(' + ox + 'px,' + oy + 'px) scale(' + base * k + ')'; var c = crop(); info.textContent = 'Your picture: ' + W + ' × ' + H + ' px · saved part: ' + Math.round(c.w) + ' × ' + Math.round(c.h) + ' px → ' + out(c).w + ' × ' + out(c).h + ' px';
                var small = c.w < spec.min[0] || (spec.min[1] && c.h < spec.min[1]); warn.style.display = small ? '' : 'none'; warn.textContent = small ? 'This part of the picture is small (recommended at least ' + spec.min[0] + (spec.min[1] ? ' × ' + spec.min[1] : '') + ' px): it may look blurry on large screens.' : ''; }
            function layout(reset) { var maxW = Math.min(box.clientWidth - 28, 680), rr = r || W / H; SW = maxW; SH = SW / rr; if (SH > window.innerHeight * 0.5) { SH = window.innerHeight * 0.5; SW = SH * rr; } st.style.width = SW + 'px'; st.style.height = SH + 'px'; base = Math.max(SW / W, SH / H); if (reset) { k = 1; z.value = 100; ox = (SW - W * base) / 2; oy = (SH - H * base) / 2; } draw(); }
            function crop() { var s = base * k; return { x: -ox / s, y: -oy / s, w: SW / s, h: SH / s }; }
            function out(c) { var w = Math.min(spec.w, c.w), h = r ? w / r : c.h * (w / c.w); if (!r && spec.w && Math.max(c.w, c.h) > spec.w) { var q = spec.w / Math.max(c.w, c.h); w = c.w * q; h = c.h * q; } return { w: Math.max(1, Math.round(w)), h: Math.max(1, Math.round(h)) }; }
            layout(true);
            z.oninput = function () { var c = crop(), cxp = c.x + c.w / 2, cyp = c.y + c.h / 2; k = z.value / 100; var s = base * k; ox = SW / 2 - cxp * s; oy = SH / 2 - cyp * s; draw(); };
            fit.onclick = function () { layout(true); };
            var d = null;
            st.addEventListener('pointerdown', function (e) { d = { x: e.clientX, y: e.clientY, ox: ox, oy: oy }; st.setPointerCapture(e.pointerId); });
            st.addEventListener('pointermove', function (e) { if (!d) return; ox = d.ox + e.clientX - d.x; oy = d.oy + e.clientY - d.y; draw(); });
            st.addEventListener('pointerup', function () { d = null; });
            st.addEventListener('wheel', function (e) { e.preventDefault(); z.value = Math.max(100, Math.min(400, +z.value - Math.sign(e.deltaY) * 10)); z.oninput(); }, { passive: false });
            function close() { ov.remove(); URL.revokeObjectURL(url); }
            cancel.onclick = function () { close(); inp.value = ''; };
            ok.onclick = function () {
                var c = crop(), o = out(c), cv = document.createElement('canvas'); cv.width = o.w; cv.height = o.h; var cx = cv.getContext('2d');
                var png = spec.out === 'png'; if (!png) { cx.fillStyle = '#fff'; cx.fillRect(0, 0, o.w, o.h); } cx.imageSmoothingQuality = 'high';
                cx.drawImage(img, c.x, c.y, c.w, c.h, 0, 0, o.w, o.h);
                cv.toBlob(function (b) {
                    var name = file.name.replace(/\.[^.]+$/, '') + (png ? '.png' : '.jpg');
                    try { var dt = new DataTransfer(); dt.items.add(new File([b], name, { type: png ? 'image/png' : 'image/jpeg' })); inp.files = dt.files; } catch (x) { /* old browser: the original is sent and the server adjusts it */ }
                    if (!thumb) { thumb = el('img', 'ik-thumb'); note.insertAdjacentElement('afterend', thumb); } thumb.src = URL.createObjectURL(b);
                    note.textContent = 'Ready: ' + o.w + ' × ' + o.h + ' px, ' + Math.max(1, Math.round(b.size / 1024)) + ' KB (saved when you submit the form)';
                    close(); inp.__skip = 1; inp.dispatchEvent(new Event('change', { bubbles: true }));
                }, png ? 'image/png' : 'image/jpeg', 0.86);
            };
        }
    }
    function scan() { document.querySelectorAll('input[type=file][data-img-spec]').forEach(setup); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan); else scan();
})();
</script>
HTML;
}
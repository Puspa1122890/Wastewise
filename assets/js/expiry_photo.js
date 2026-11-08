/*
 * Expiry date from a photo. The user photographs the printed date on a
 * package. The text is read inside the browser with Tesseract.js (the image is
 * not uploaded), the date is picked by WasteWiseLib.parseExpiryDate, and the
 * expiry field is filled in. The user always checks the date before saving.
 */
(function () {
    'use strict';

    var lib = window.WasteWiseLib;
    var fileInput = document.getElementById('expiry-photo');
    var statusEl = document.getElementById('expiry-photo-status');
    var expiryInput = document.querySelector('input[name="expiry_date"]');
    var purchaseInput = document.querySelector('input[name="purchase_date"]');
    if (!fileInput || !statusEl || !expiryInput || !lib) { return; }

    var choicesEl = document.createElement('div');
    choicesEl.className = 'date-choices';
    statusEl.parentNode.insertBefore(choicesEl, statusEl.nextSibling);

    function setStatus(t) { statusEl.textContent = t; }

    // Every date the reader found is offered as a button, so a wrong guess
    // is fixed with one tap instead of typing.
    function showChoices(options, chosen) {
        choicesEl.innerHTML = '';
        var isos = Object.keys(options).sort();
        if (isos.length < 2 && !(isos.length === 1 && isos[0] !== chosen)) { return; }
        var label = document.createElement('span');
        label.textContent = 'Dates seen in the photo: ';
        choicesEl.appendChild(label);
        isos.forEach(function (iso) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'small secondary';
            b.textContent = iso;
            b.addEventListener('click', function () {
                expiryInput.value = iso;
                expiryInput.dispatchEvent(new Event('change', { bubbles: true }));
                setStatus('Expiry date set to ' + iso + '. Please check it against the package.');
            });
            choicesEl.appendChild(b);
        });
    }

    // Mean of a (2r+1) square around every pixel, using an integral image.
    function boxMean(gray, w, h, r) {
        var W = w + 1, ii = new Float64Array(W * (h + 1)), x, y, out = new Float32Array(w * h);
        for (y = 0; y < h; y++) {
            var row = 0;
            for (x = 0; x < w; x++) {
                row += gray[y * w + x];
                ii[(y + 1) * W + x + 1] = ii[y * W + x + 1] + row;
            }
        }
        for (y = 0; y < h; y++) {
            var y0 = Math.max(0, y - r), y1 = Math.min(h, y + r + 1);
            for (x = 0; x < w; x++) {
                var x0 = Math.max(0, x - r), x1 = Math.min(w, x + r + 1);
                out[y * w + x] = (ii[y1 * W + x1] - ii[y0 * W + x1] - ii[y1 * W + x0] + ii[y0 * W + x0]) /
                    ((x1 - x0) * (y1 - y0));
            }
        }
        return out;
    }

    function toCanvas(gray, w, h) {
        var c = document.createElement('canvas');
        c.width = w; c.height = h;
        var ctx = c.getContext('2d'), d = ctx.createImageData(w, h), i, v;
        for (i = 0; i < w * h; i++) {
            v = Math.max(0, Math.min(255, Math.round(gray[i])));
            d.data[i * 4] = d.data[i * 4 + 1] = d.data[i * 4 + 2] = v;
            d.data[i * 4 + 3] = 255;
        }
        ctx.putImageData(d, 0, 0);
        return c;
    }

    // Returns the photo in three versions: contrast stretched, and two
    // "joined dots" versions (blurred, then black text on white) that help
    // with dot-matrix prints such as those on yoghurt pots and cartons.
    function prepareImages(file) {
        return new Promise(function (resolve) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                try {
                    var scale = img.width > 2000 ? 2000 / img.width : Math.min(3, Math.max(1, 1600 / img.width));
                    var w = Math.round(img.width * scale), h = Math.round(img.height * scale);
                    var c = document.createElement('canvas');
                    c.width = w; c.height = h;
                    var ctx = c.getContext('2d');
                    ctx.drawImage(img, 0, 0, w, h);
                    var px = ctx.getImageData(0, 0, w, h).data;
                    var gray = new Float32Array(w * h), hist = new Array(256).fill(0), i, g;
                    for (i = 0; i < w * h; i++) {
                        g = 0.299 * px[i * 4] + 0.587 * px[i * 4 + 1] + 0.114 * px[i * 4 + 2];
                        gray[i] = g;
                        hist[Math.round(g)]++;
                    }
                    var total = w * h, lo = 0, hi = 255, acc = 0;
                    for (i = 0; i < 256; i++) { acc += hist[i]; if (acc > total * 0.01) { lo = i; break; } }
                    acc = 0;
                    for (i = 255; i >= 0; i--) { acc += hist[i]; if (acc > total * 0.01) { hi = i; break; } }
                    if (hi - lo > 10) {
                        for (i = 0; i < w * h; i++) { gray[i] = Math.max(0, Math.min(255, (gray[i] - lo) * 255 / (hi - lo))); }
                    }
                    var versions = [toCanvas(gray, w, h)];
                    var local = boxMean(gray, w, h, 40);
                    [3, 5].forEach(function (r) {
                        var sm = boxMean(boxMean(gray, w, h, r), w, h, r), bw = new Float32Array(w * h), k;
                        for (k = 0; k < w * h; k++) { bw[k] = sm[k] < local[k] - 12 ? 0 : 255; }
                        versions.push(toCanvas(bw, w, h));
                    });
                    URL.revokeObjectURL(url);
                    resolve(versions);
                } catch (e) { URL.revokeObjectURL(url); resolve([file]); }
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve([file]); };
            img.src = url;
        });
    }

    fileInput.addEventListener('change', function () {
        if (!fileInput.files || fileInput.files.length === 0) { return; }
        if (typeof Tesseract === 'undefined') {
            setStatus('The reader could not load (are you online?). Please type the date by hand.');
            return;
        }
        var worker = null;
        setStatus('Reading the date. The first time can take a minute...');
        var seen = [], rejected = '', options = {};
        choicesEl.innerHTML = '';
        function tryModes(images, modes) {
            if (images.length === 0) { return Promise.resolve(null); }
            if (modes.length === 0) { return tryModes(images.slice(1), ['11', '6', '7']); }
            return worker.setParameters({ tessedit_pageseg_mode: modes[0], user_defined_dpi: '300' }).then(function () {
                return worker.recognize(images[0]);
            }).then(function (result) {
                var text = result.data.text || '';
                seen.push(text);
                var found = lib.parseExpiryDate(text);
                var purchase = purchaseInput ? purchaseInput.value : '';
                (found.all || []).forEach(function (c) { if (!purchase || c.iso >= purchase) { options[c.iso] = c.text; } });
                if (found.iso && purchase && found.iso < purchase) {
                    // Impossible: it expires before it was bought. Probably a misread, so keep trying.
                    rejected = found.text;
                    found = { iso: null };
                }
                return found.iso ? found : tryModes(images, modes.slice(1));
            });
        }
        prepareImages(fileInput.files[0]).then(function (images) {
            return Tesseract.createWorker('dan+eng', 1, {}).then(function (w) {
                worker = w;
                return tryModes(images, ['11', '6', '7']);
            });
        }).then(function (found) {
            var purchase = purchaseInput ? purchaseInput.value : '';
            if (!found) {
                var read = seen.join(' ').replace(/\s+/g, ' ').trim().slice(0, 80);
                setStatus((rejected ? 'A date "' + rejected + '" was seen but it is before the purchase date, so it was ignored. ' : '') +
                    'No date was found' + (read ? ' (the reader saw: "' + read + '")' : ' (no text was seen)') +
                    '. Try a closer, sharper photo of just the printed date, or type it by hand.');
            } else if (purchase && found.iso < purchase) {
                setStatus('Found "' + found.text + '", but it is before the purchase date. Please type the date by hand.');
            } else {
                expiryInput.value = found.iso;
                expiryInput.dispatchEvent(new Event('change', { bubbles: true }));
                setStatus('Read "' + found.text + '" as ' + found.iso + (found.monthOnly ? ' (last day of that month)' : '') +
                    (found.yearGuessed ? ' (year guessed)' : '') + '. Please check it against the package.' +
                    (found.monthOnly ? ' The reader saw: "' + seen.join(' ').replace(/\\s+/g, ' ').trim().slice(-90) + '"' : ''));
            }
            showChoices(options, found ? found.iso : null);
        }).catch(function (err) {
            if (window.console) { console.error(err); }
            setStatus('The photo could not be read (' + (err && err.message ? err.message : 'error') + '). Please make sure the newest wastewise_lib.js and expiry_photo.js are both uploaded, or type the date by hand.');
        }).then(function () {
            if (worker) { return worker.terminate(); }
        });
    });
}());

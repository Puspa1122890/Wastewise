/*
 * Receipt import: read a receipt photo with OCR (Tesseract.js, running
 * inside the browser, so the image never leaves the device) or accept pasted
 * text, turn it into editable rows, and let the user check every row before
 * anything is saved.
 */
(function () {
    'use strict';

    var lib = window.WasteWiseLib;
    var categories = window.WW_CATEGORIES || [];

    var fileInput = document.getElementById('receipt-image');
    var ocrBtn = document.getElementById('ocr-run');
    var parseBtn = document.getElementById('parse-text');
    var textArea = document.getElementById('receipt-text');
    var purchaseInput = document.getElementById('purchase-date');
    var statusEl = document.getElementById('ocr-status');
    var rowsBody = document.getElementById('receipt-rows');
    var reviewSection = document.getElementById('review-section');
    var importBtn = document.getElementById('import-submit');

    function setStatus(t) { statusEl.textContent = t; }

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function categoryOptions(selected) {
        return categories.map(function (c) {
            return '<option value="' + esc(c) + '"' + (c === selected ? ' selected' : '') + '>' + esc(c) + '</option>';
        }).join('');
    }

    function renderRows(rows) {
        rowsBody.innerHTML = '';
        rows.forEach(function (r, i) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><input type="checkbox" name="items[' + i + '][include]" value="1" checked></td>' +
                '<td><input type="text" name="items[' + i + '][name]" maxlength="150" value="' + esc(r.name) + '" required></td>' +
                '<td><select name="items[' + i + '][category]">' + categoryOptions(r.category) + '</select></td>' +
                '<td><input type="number" name="items[' + i + '][quantity]" min="1" max="1000" value="' + r.quantity + '" required></td>' +
                '<td><input type="number" name="items[' + i + '][price]" step="0.01" min="0" value="' + r.price.toFixed(2) + '" required></td>' +
                '<td><input type="date" name="items[' + i + '][expiry]" value="' + r.expiry + '" required></td>';
            rowsBody.appendChild(tr);
        });
        reviewSection.hidden = rows.length === 0;
        importBtn.disabled = rows.length === 0;
        setStatus(rows.length === 0
            ? 'No products were recognised. Edit the text above and try again, or add items by hand.'
            : rows.length + ' possible product(s) found. Check every row, especially the category and the expiry date.');
    }

    function parseNow() {
        var purchase = purchaseInput.value;
        if (!purchase) { setStatus('Please choose the purchase date first.'); return; }
        renderRows(lib.parseReceipt(textArea.value, purchase));
    }

    parseBtn.addEventListener('click', parseNow);

    // Changing the purchase date re-suggests expiry dates on the current rows.
    purchaseInput.addEventListener('change', function () {
        if (textArea.value.trim() !== '' && !reviewSection.hidden) { parseNow(); }
    });

    ocrBtn.addEventListener('click', function () {
        if (!fileInput.files || fileInput.files.length === 0) {
            setStatus('Please choose a receipt photo first.');
            return;
        }
        if (typeof Tesseract === 'undefined') {
            setStatus('The OCR engine could not load (are you online?). You can paste the receipt text instead.');
            return;
        }
        ocrBtn.disabled = true;
        setStatus('Reading the receipt. The first run downloads language data and can take a minute...');

        // Small or low contrast photos read badly. Scale small images up and
        // stretch the contrast first, then read the photo in two page modes
        // (4 = one column of varying sizes, 6 = one block) and keep whichever
        // gives more products. Word positions are used to put names and prices
        // back on one line if the OCR read them as separate blocks.
        var worker = null;
        var purchase = purchaseInput.value;

        function prepareImage(file) {
            return new Promise(function (resolve) {
                var url = URL.createObjectURL(file);
                var img = new Image();
                img.onload = function () {
                    try {
                        var scale = Math.min(4, Math.max(1, 1600 / img.width));
                        var c = document.createElement('canvas');
                        c.width = Math.round(img.width * scale);
                        c.height = Math.round(img.height * scale);
                        var ctx = c.getContext('2d');
                        ctx.drawImage(img, 0, 0, c.width, c.height);
                        var data = ctx.getImageData(0, 0, c.width, c.height);
                        var px = data.data, hist = new Array(256).fill(0), i, g;
                        for (i = 0; i < px.length; i += 4) {
                            g = Math.round(0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2]);
                            px[i] = px[i + 1] = px[i + 2] = g;
                            hist[g]++;
                        }
                        var total = c.width * c.height, lo = 0, hi = 255, acc = 0;
                        for (i = 0; i < 256; i++) { acc += hist[i]; if (acc > total * 0.01) { lo = i; break; } }
                        acc = 0;
                        for (i = 255; i >= 0; i--) { acc += hist[i]; if (acc > total * 0.01) { hi = i; break; } }
                        if (hi - lo > 10) {
                            for (i = 0; i < px.length; i += 4) {
                                g = Math.max(0, Math.min(255, Math.round((px[i] - lo) * 255 / (hi - lo))));
                                px[i] = px[i + 1] = px[i + 2] = g;
                            }
                        }
                        ctx.putImageData(data, 0, 0);
                        URL.revokeObjectURL(url);
                        resolve(c);
                    } catch (e) { URL.revokeObjectURL(url); resolve(file); }
                };
                img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
                img.src = url;
            });
        }

        function readWith(image, mode) {
            return worker.setParameters({ tessedit_pageseg_mode: mode, user_defined_dpi: '300' }).then(function () {
                return worker.recognize(image);
            }).then(function (result) {
                var plain = result.data.text || '';
                var rebuilt = lib.linesFromWords(result.data.words || []);
                var plainCount = lib.parseReceipt(plain, purchase).length;
                var rebuiltCount = lib.parseReceipt(rebuilt, purchase).length;
                return rebuilt && rebuiltCount >= plainCount
                    ? { text: rebuilt, count: rebuiltCount }
                    : { text: plain, count: plainCount };
            });
        }

        var best = null;
        Tesseract.createWorker('dan+eng', 1, {
            logger: function (m) {
                if (m.status === 'recognizing text') {
                    setStatus('Reading the receipt... ' + Math.round(m.progress * 100) + '%');
                }
            }
        }).then(function (w) {
            worker = w;
            return prepareImage(fileInput.files[0]);
        }).then(function (image) {
            return readWith(image, '4').then(function (first) {
                best = first;
                return readWith(image, '6');
            }).then(function (second) {
                if (second.count > best.count) { best = second; }
            });
        }).then(function () {
            textArea.value = best.text;
            setStatus('Text read. Fix any mistakes in the text box if you like, then rows are shown below.');
            ocrBtn.disabled = false;
            parseNow();
            return worker.terminate();
        }).catch(function (err) {
            if (window.console) { console.error('Receipt OCR failed:', err); }
            ocrBtn.disabled = false;
            if (worker) { try { worker.terminate(); } catch (e) { /* ignore */ } }
            setStatus('Could not read that image. Try a clearer, flatter photo, or paste the text instead.');
        });
    });
}());

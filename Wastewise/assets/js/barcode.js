/*
 * Barcode scanning and lookup for the "Add a product" form.
 *
 * Two ways in: scan with the camera (html5-qrcode), or type the digits and
 * press "Look up". Either way the barcode number is sent to Open Food Facts
 * (a free, open food database) to fill in the name and category. If the
 * lookup fails or the product is unknown, the form still works by hand.
 *
 * Camera access needs https:// or localhost.
 */
(function () {
    'use strict';

    var startBtn = document.getElementById('scan-start');
    var stopBtn = document.getElementById('scan-stop');
    var view = document.getElementById('scanner-view');
    var statusEl = document.getElementById('scan-status');
    var barcodeInput = document.getElementById('barcode-input');
    var lookupBtn = document.getElementById('barcode-lookup');
    var nameInput = document.getElementById('name-input');
    var categoryInput = document.getElementById('category-input');
    var form = document.getElementById('add-form');

    if (!barcodeInput || !lookupBtn) { return; }

    var scanner = null;

    function setStatus(text) { statusEl.textContent = text; }

    function lookup(code) {
        code = String(code).replace(/\D/g, '');
        if (code.length < 6 || code.length > 14) {
            setStatus('A barcode is 6 to 14 digits.');
            return;
        }
        barcodeInput.value = code;
        setStatus('Looking up ' + code + ' (the number is sent to Open Food Facts)...');

        var url = 'https://world.openfoodfacts.org/api/v2/product/' + encodeURIComponent(code) +
                  '.json?fields=product_name,brands,categories_tags';
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !data.product) {
                    setStatus('Product not found. Please type the name and choose the category.');
                    return;
                }
                var p = data.product;
                if (p.product_name) { nameInput.value = p.product_name; }
                var cat = window.WasteWiseLib.categoryFromOffTags(p.categories_tags);
                categoryInput.value = cat;

                // Suggest an expiry date from the category if none is set yet.
                var expiry = form.querySelector('input[name="expiry_date"]');
                var purchase = form.querySelector('input[name="purchase_date"]');
                if (expiry && !expiry.value && purchase && purchase.value) {
                    expiry.value = window.WasteWiseLib.defaultExpiry(purchase.value, cat);
                }
                setStatus('Found: ' + (p.product_name || code) + '. Category guessed as ' + cat +
                          '. Please check the category and the expiry date on the package.');
            })
            .catch(function () {
                setStatus('Could not reach Open Food Facts. Please fill in the details by hand.');
            });
    }

    function stopScanner() {
        if (!scanner) { return Promise.resolve(); }
        var s = scanner;
        scanner = null;
        return s.stop().then(function () { return s.clear(); }).catch(function () {})
            .then(function () {
                view.hidden = true;
                stopBtn.hidden = true;
                startBtn.hidden = false;
            });
    }

    function startScanner() {
        if (typeof Html5Qrcode === 'undefined') {
            setStatus('The scanner could not load (are you online?). Type the barcode digits instead.');
            return;
        }
        view.hidden = false;
        stopBtn.hidden = false;
        startBtn.hidden = true;
        setStatus('Point the camera at the barcode...');

        scanner = new Html5Qrcode('scanner-view');
        var config = { fps: 10, qrbox: { width: 260, height: 120 } };
        if (typeof Html5QrcodeSupportedFormats !== 'undefined') {
            config.formatsToSupport = [
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.EAN_8,
                Html5QrcodeSupportedFormats.UPC_A,
                Html5QrcodeSupportedFormats.UPC_E
            ];
        }
        scanner.start(
            { facingMode: 'environment' },
            config,
            function (decodedText) {
                stopScanner().then(function () { lookup(decodedText); });
            },
            function () { /* no code in this frame; keep scanning */ }
        ).catch(function () {
            scanner = null;
            view.hidden = true;
            stopBtn.hidden = true;
            startBtn.hidden = false;
            setStatus('Could not start the camera (permission denied, no camera, or not https). Type the digits instead.');
        });
    }

    startBtn.addEventListener('click', startScanner);
    stopBtn.addEventListener('click', function () { stopScanner(); setStatus(''); });
    lookupBtn.addEventListener('click', function () { lookup(barcodeInput.value); });
    barcodeInput.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); lookup(barcodeInput.value); }
    });
}());

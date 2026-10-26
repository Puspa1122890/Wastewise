/*
 * WasteWise helper library: receipt text parsing, category guessing,
 * expiry suggestions and Open Food Facts category mapping.
 *
 * Works in the browser (window.WasteWiseLib) and in Node (module.exports),
 * so tests/receipt_parser_test.js can test it without a browser.
 *
 * Important limits (also stated in the thesis):
 *  - A receipt shows what was bought and what it cost. It does NOT show when
 *    or how much was eaten, and it has no expiry dates.
 *  - OCR output is imperfect, so every extracted row is shown to the user to
 *    check and correct before anything is saved.
 *  - Category guesses and shelf-life suggestions are rough defaults.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.WasteWiseLib = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // Suggested days from purchase to expiry, used only to pre-fill the
    // expiry date. The user must check it against the package.
    var SHELF_LIFE_DAYS = {
        'Dairy': 7,
        'Produce': 5,
        'Meat & Fish': 3,
        'Bread & Bakery': 4,
        'Frozen': 90,
        'Canned Goods': 365,
        'Other': 14
    };

    // Checked in this order. Danish and English words.
    var CATEGORY_KEYWORDS = [
        ['Frozen', ['frost', 'frozen', 'frosne', 'dybfrost', 'pizza', 'pommes', 'ice cream', 'isvaffel']],
        ['Canned Goods', ['dåse', 'daase', 'konserv', 'canned', 'bønner', 'bonner', 'majs', 'hakkede tomat',
                          'flåede tomat', 'linser', 'kikærter', 'tun', 'chana', 'pasta', 'paste']],
        ['Dairy', ['mælk', 'maelk', 'milk', 'yoghurt', 'yogurt', 'skyr', 'ost', 'cheese', 'smør', 'butter',
                   'fløde', 'floede', 'cream', 'kefir', 'creme fraiche', 'cottage', 'mozzarella', 'feta',
                   'æg', 'aeg', 'eggs', 'egg']],
        ['Meat & Fish', ['kød', 'koed', 'kylling', 'chicken', 'svine', 'flæsk', 'oksekød', 'hakket', 'bøf',
                         'pølse', 'bacon', 'skinke', 'laks', 'torsk', 'fisk', 'fish', 'rejer', 'salami',
                         'leverpostej', 'frikadelle', 'beef', 'pork', 'ham', 'pangasus']],
        ['Bread & Bakery', ['brød', 'broed', 'bread', 'rundstykke', 'bolle', 'toast', 'bagel', 'croissant',
                            'kage', 'tortilla', 'wrap', 'rugbrød']],
        ['Produce', ['æble', 'apple', 'banan', 'tomat', 'agurk', 'cucumber', 'salat', 'lettuce', 'gulerod', 'gulerød',
                     'carrot', 'kartof', 'potato', 'løg', 'onion', 'peber', 'broccoli', 'blomkål', 'spinat',
                     'citron', 'lemon', 'appelsin', 'orange', 'pære', 'drue', 'grape', 'jordbær', 'strawberr',
                     'bær', 'avocado', 'svamp', 'champignon', 'squash', 'porre', 'kål', 'frugt', 'fruit',
                     'grønt', 'vegetable', 'chili', 'chilli', 'ingefær', 'koriander', 'persille', 'lime', 'krydderurt', 'basilikum', 'mynte']]
    ];

    // Lines containing these words are receipt furniture, not products.
    var SKIP_WORDS = ['total', 'subtotal', 'i alt', 'ialt', 'moms', 'vat', 'tax', 'kort', 'card', 'cash',
                      'kontant', 'kontan', 'byttepenge', 'byttepeng', 'change', 'rabat', 'discount', 'pant', 'kvittering', 'receipt',
                      'tak for', 'thank', 'visa', 'dankort', 'mastercard', 'betalt', 'payment', 'cvr',
                      'telefon', 'tlf', 'dato', 'kasse', 'bon', 'afrunding', 'afrundet', 'retur', 'modtaget', 'udskevet', 'terminal', 'tilbud', 'spar', 'saldo'];

    var skipRegexes = SKIP_WORDS.map(function (w) {
        var escaped = w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return new RegExp('(^|[^a-zæøå])' + escaped + '($|[^a-zæøå])', 'i');
    });

    function isSkippable(line) {
        for (var i = 0; i < skipRegexes.length; i++) {
            if (skipRegexes[i].test(line)) { return true; }
        }
        return false;
    }

    // "12,95" -> 12.95, "1.299,50" -> 1299.5, "12.95" -> 12.95
    function parsePrice(text) {
        var s = String(text).replace(/\s/g, '');
        var neg = s.charAt(0) === '-';
        if (neg) { s = s.slice(1); }
        var lastComma = s.lastIndexOf(',');
        var lastDot = s.lastIndexOf('.');
        var decimalPos = Math.max(lastComma, lastDot);
        if (decimalPos === -1) { return NaN; }
        var intPart = s.slice(0, decimalPos).replace(/[.,]/g, '');
        var decPart = s.slice(decimalPos + 1);
        var value = parseFloat(intPart + '.' + decPart);
        return neg ? -value : value;
    }

    function tidyName(name) {
        var n = name.replace(/^\d{4,}\s+/, '')      // leading item code
                    .replace(/\s{2,}/g, ' ')
                    .replace(/[\s«»©®+=_|\u2014\u2013>]+[xX×]$/, '')
                    .replace(/^[\s\-*.:«»©®+=_|\u2014\u2013>]+|[\s\-*.:«»©®+=_|\u2014\u2013>]+$/g, '');
        if (n === n.toUpperCase()) {
            n = n.toLowerCase().replace(/(^|\s)([a-zæøå])/g, function (m, a, b) {
                return a + b.toUpperCase();
            });
        }
        return n;
    }

    // Returns { name, quantity, price } or null if the line is not a product.
    function parseLine(rawLine) {
        var line = String(rawLine).replace(/\t/g, ' ').trim();
        if (line.length < 4) { return null; }
        if (isSkippable(line)) { return null; }
        // OCR sometimes puts a space inside a price: "18, 95" or "10 ,00".
        line = line.replace(/(\d)\s*([.,])\s+(\d{2})(?!\d)/g, '$1$2$3').replace(/(\d)\s+([.,])(\d{2})(?!\d)/g, '$1$2$3');
        // Some receipts print "unit price  line total" columns, for example
        // "1X MILK  * 24,95  24,95". Keep only the line total.
        line = line.replace(/\s*[*«]\s*\d+[.,]\d{2}\s+(?=-?\d+[.,]\d{2}\s*$)/, '  ');
        // OCR often reads "1X" as "IX", "lX" or "|X".
        line = line.replace(/^[\s_.\-]*[Il|]\s*[xX×][\s*'\u2019]+/, '1X ');

        var m = line.match(/^(.*?)\s+(-?\d+(?:[.,]\d{3})*[.,]\d{2})(?:\s*(?:kr\.?|dkk))?(?:\s+[A-Za-z])?\s*$/i);
        if (!m) { return null; }

        var price = parsePrice(m[2]);
        if (!isFinite(price) || price <= 0) { return null; }

        var namePart = m[1];
        var quantity = 1;

        var lead = namePart.match(/^(\d{1,3})\s*[x×]\s*(.+)$/i);
        if (lead) {
            quantity = parseInt(lead[1], 10);
            namePart = lead[2];
        } else {
            var trail = namePart.match(/^(.*?)\s+(\d{1,3})\s*[x×]\s*\d+(?:[.,]\d{2})?$/i);
            if (trail) {
                quantity = parseInt(trail[2], 10);
                namePart = trail[1];
            }
        }

        // A leading weight such as "0.18 CHILI" is not part of the name.
        namePart = namePart.replace(/^\d\s+(?=\d+[.,]\d+\s)/, '').replace(/^\d+[.,]\d+\s+/, '');
        var name = tidyName(namePart);
        if (!/[a-zæøå]{2}/i.test(name)) { return null; }
        if (quantity < 1 || quantity > 1000) { quantity = 1; }

        return { name: name, quantity: quantity, price: Math.round(price * 100) / 100 };
    }

    function guessCategory(name) {
        var lower = String(name).toLowerCase();
        for (var i = 0; i < CATEGORY_KEYWORDS.length; i++) {
            var category = CATEGORY_KEYWORDS[i][0];
            var words = CATEGORY_KEYWORDS[i][1];
            for (var j = 0; j < words.length; j++) {
                var escaped = words[j].replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                // Danish builds compound words ("letmælk"), so longer keywords
                // match anywhere in a word. Short ones (ost, æg, kål) only
                // match at the start of a word, so "kost" is not "ost".
                var pattern = words[j].length >= 4 ? escaped : '(^|[^a-zæøå])' + escaped;
                if (new RegExp(pattern, 'i').test(lower)) {
                    return category;
                }
            }
        }
        return 'Other';
    }

    function defaultExpiry(purchaseISO, category) {
        var days = SHELF_LIFE_DAYS.hasOwnProperty(category) ? SHELF_LIFE_DAYS[category] : SHELF_LIFE_DAYS.Other;
        var d = new Date(purchaseISO + 'T00:00:00Z');
        d.setUTCDate(d.getUTCDate() + days);
        return d.toISOString().slice(0, 10);
    }

    // Rebuild text lines from OCR words using their positions on the page.
    // Receipts have a name column and a price column, and OCR in automatic
    // mode may read them as separate blocks. Grouping words by vertical
    // position puts each product and its price back on one line.
    // words: [{ text: 'MILK', bbox: { x0, y0, x1, y1 } }, ...]
    function linesFromWords(words) {
        var clean = (words || []).filter(function (w) {
            return w && w.text && String(w.text).trim() !== '' && w.bbox;
        }).map(function (w) {
            return { text: String(w.text).trim(), x0: w.bbox.x0, xc: (w.bbox.x0 + w.bbox.x1) / 2,
                     yc: (w.bbox.y0 + w.bbox.y1) / 2, h: Math.max(1, w.bbox.y1 - w.bbox.y0) };
        });
        if (clean.length === 0) { return ''; }
        var heights = clean.map(function (w) { return w.h; }).sort(function (a, b) { return a - b; });
        var tolerance = Math.max(3, heights[Math.floor(heights.length / 2)] * 0.6);
        clean.sort(function (a, b) { return a.yc - b.yc; });
        var lines = [];
        clean.forEach(function (w) {
            var last = lines[lines.length - 1];
            if (last && Math.abs(w.yc - last.yc) <= tolerance) {
                last.words.push(w);
                last.yc = (last.yc * (last.words.length - 1) + w.yc) / last.words.length;
            } else {
                lines.push({ yc: w.yc, words: [w] });
            }
        });
        return lines.map(function (l) {
            l.words.sort(function (a, b) { return a.x0 - b.x0; });
            return l.words.map(function (w) { return w.text; }).join(' ');
        }).join('\n');
    }

    // Full pipeline: receipt text -> list of candidate rows to review.
    function parseReceipt(text, purchaseISO) {
        var rows = [];
        String(text).split(/\r?\n/).forEach(function (line) {
            var item = parseLine(line);
            if (item) {
                item.category = guessCategory(item.name);
                item.expiry = defaultExpiry(purchaseISO, item.category);
                rows.push(item);
            }
        });
        return rows;
    }

    // Map Open Food Facts category tags (e.g. "en:dairies", "en:milks") to
    // one of WasteWise's categories.
    function categoryFromOffTags(tags) {
        var joined = (tags || []).join(' ').toLowerCase();
        if (/beverage|juice|drink|water|soda|coffee|tea/.test(joined)) { return 'Other'; }
        if (/frozen/.test(joined)) { return 'Frozen'; }
        if (/canned|preserved/.test(joined)) { return 'Canned Goods'; }
        if (/dair|milk|cheese|yogurt|yoghurt|butter|cream|egg/.test(joined)) { return 'Dairy'; }
        if (/meat|fish|seafood|sausage|poultry|ham|beef|pork|chicken/.test(joined)) { return 'Meat & Fish'; }
        if (/bread|bakery|pastr|cake|bun/.test(joined)) { return 'Bread & Bakery'; }
        if (/fruit|vegetable|salad|potato|plant-based-foods/.test(joined) && !/snack|chips|sauce/.test(joined)) {
            return 'Produce';
        }
        return 'Other';
    }

    return {
        SHELF_LIFE_DAYS: SHELF_LIFE_DAYS,
        parsePrice: parsePrice,
        parseLine: parseLine,
        parseReceipt: parseReceipt,
        linesFromWords: linesFromWords,
        guessCategory: guessCategory,
        defaultExpiry: defaultExpiry,
        categoryFromOffTags: categoryFromOffTags
    };
}));

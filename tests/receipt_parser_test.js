// Run with:  node tests/receipt_parser_test.js
const assert = require('assert');
const lib = require('../assets/js/wastewise_lib.js');

let passed = 0, failed = 0;
function test(name, fn) {
    try { fn(); passed++; console.log('  PASS  ' + name); }
    catch (e) { failed++; console.log('  FAIL  ' + name + '\n        ' + e.message); }
}

console.log('Price parsing');
test('comma decimal', () => assert.strictEqual(lib.parsePrice('12,95'), 12.95));
test('dot decimal', () => assert.strictEqual(lib.parsePrice('12.95'), 12.95));
test('thousands with comma decimal', () => assert.strictEqual(lib.parsePrice('1.299,50'), 1299.5));
test('negative price', () => assert.strictEqual(lib.parsePrice('-5,00'), -5));
test('not a number', () => assert.ok(isNaN(lib.parsePrice('abc'))));

console.log('\nSingle line parsing');
test('simple Danish line', () => {
    const r = lib.parseLine('MÆLK 1L        12,95');
    assert.deepStrictEqual(r, { name: 'Mælk 1l', quantity: 1, price: 12.95 });
});
test('line with kr suffix', () => {
    const r = lib.parseLine('Yoghurt naturel 18,00 kr');
    assert.strictEqual(r.price, 18);
    assert.strictEqual(r.name, 'Yoghurt naturel');
});
test('leading quantity "2 x"', () => {
    const r = lib.parseLine('2 x Hakket oksekød 400g 59,90');
    assert.strictEqual(r.quantity, 2);
    assert.strictEqual(r.name, 'Hakket oksekød 400g');
    assert.strictEqual(r.price, 59.9);
});
test('trailing quantity "3 x 5,00 15,00"', () => {
    const r = lib.parseLine('BANANER 3 x 5,00   15,00');
    assert.strictEqual(r.quantity, 3);
    assert.strictEqual(r.price, 15);
    assert.strictEqual(r.name, 'Bananer');
});
test('leading item code removed', () => {
    const r = lib.parseLine('5701234 Rugbrød 21,50');
    assert.strictEqual(r.name, 'Rugbrød');
});
test('tax flag letter after price tolerated', () => {
    const r = lib.parseLine('Smør 250g 24,95 A');
    assert.strictEqual(r.price, 24.95);
});

console.log('\nLines that must be ignored');
['I ALT 245,60', 'TOTAL 245.60', 'MOMS 49,12', 'Dankort 245,60', 'KONTANT 300,00',
 'BYTTEPENGE 54,40', 'Rabat -5,00', 'PANT 1,00', 'Tak for besøget', 'CVR 12345678',
 '12:45', '---------', 'Netto', ''].forEach(line => {
    test('ignores "' + line + '"', () => assert.strictEqual(lib.parseLine(line), null));
});
test('ignores negative amounts', () => assert.strictEqual(lib.parseLine('Tilbudsvare -10,00'), null));
test('ignores zero price', () => assert.strictEqual(lib.parseLine('Pose 0,00'), null));

console.log('\nCategory guessing');
const cats = [
    ['Letmælk', 'Dairy'], ['Yoghurt', 'Dairy'], ['Økologiske æg', 'Dairy'], ['Mozzarella', 'Dairy'],
    ['Kyllingebryst', 'Meat & Fish'], ['Hakket oksekød', 'Meat & Fish'], ['Laks', 'Meat & Fish'],
    ['Rugbrød', 'Bread & Bakery'], ['Rundstykker', 'Bread & Bakery'],
    ['Bananer', 'Produce'], ['Agurk', 'Produce'], ['Gulerødder', 'Produce'],
    ['Hakkede tomater', 'Canned Goods'], ['Kidneybønner', 'Canned Goods'], ['Tun i vand', 'Canned Goods'],
    ['Frosne grøntsager', 'Frozen'], ['Pizza', 'Frozen'],
    ['Kost fiber pulver', 'Other'], ['Toiletpapir', 'Other']
];
cats.forEach(([name, expected]) => {
    test(name + ' -> ' + expected, () => assert.strictEqual(lib.guessCategory(name), expected));
});

console.log('\nExpiry suggestion');
test('dairy +7 days', () => assert.strictEqual(lib.defaultExpiry('2026-10-01', 'Dairy'), '2026-10-08'));
test('month rollover', () => assert.strictEqual(lib.defaultExpiry('2026-10-28', 'Dairy'), '2026-11-04'));
test('year rollover', () => assert.strictEqual(lib.defaultExpiry('2026-12-30', 'Produce'), '2027-01-04'));
test('unknown category falls back', () => assert.strictEqual(lib.defaultExpiry('2026-10-01', 'Nope'), '2026-10-15'));

console.log('\nFull receipts');
const danish = [
    'NETTO',
    'Nørrebrogade 12',
    'CVR 12345678',
    '',
    'MÆLK 1L                12,95',
    '2 x YOGHURT NATUREL    28,00',
    'RUGBRØD                21,50',
    'BANANER 3 x 5,00       15,00',
    'HAKKET OKSEKØD 400G    39,95',
    'Rabat                  -5,00',
    'PANT                    3,00',
    'I ALT                 115,40',
    'MOMS                   23,08',
    'DANKORT               115,40',
    'Tak for besøget'
].join('\n');
test('Danish receipt yields the 5 real products', () => {
    const rows = lib.parseReceipt(danish, '2026-10-06');
    assert.strictEqual(rows.length, 5);
    assert.deepStrictEqual(rows.map(r => r.name), ['Mælk 1l', 'Yoghurt Naturel', 'Rugbrød', 'Bananer', 'Hakket Oksekød 400g']);
    assert.deepStrictEqual(rows.map(r => r.category), ['Dairy', 'Dairy', 'Bread & Bakery', 'Produce', 'Meat & Fish']);
    assert.deepStrictEqual(rows.map(r => r.quantity), [1, 2, 1, 3, 1]);
    assert.strictEqual(rows[0].expiry, '2026-10-13');
    const total = rows.reduce((s, r) => s + r.price, 0);
    assert.strictEqual(Math.round(total * 100) / 100, 117.4);
});

const english = [
    'FRESH MART', '14 High Street', 'Milk 2L 1.89', 'Wholemeal bread 1.20', 'Chicken breast 5.50',
    'Tinned tomatoes 0.65', 'Carrots 0.89', 'SUBTOTAL 10.13', 'VAT 1.69', 'TOTAL 10.13', 'CARD 10.13'
].join('\n');
test('English receipt', () => {
    const rows = lib.parseReceipt(english, '2026-10-06');
    assert.deepStrictEqual(rows.map(r => r.name), ['Milk 2L 1.89'.replace(' 1.89', ''), 'Wholemeal bread', 'Chicken breast', 'Tinned tomatoes', 'Carrots']);
});

test('empty text gives no rows', () => assert.deepStrictEqual(lib.parseReceipt('', '2026-10-06'), []));
test('garbage text gives no rows', () => assert.deepStrictEqual(lib.parseReceipt('###\n@@@\n12345', '2026-10-06'), []));

console.log('\nOpen Food Facts category mapping');
const off = [
    [['en:dairies', 'en:milks'], 'Dairy'],
    [['en:frozen-foods', 'en:pizzas'], 'Frozen'],
    [['en:canned-foods', 'en:vegetables'], 'Canned Goods'],
    [['en:meats', 'en:sausages'], 'Meat & Fish'],
    [['en:breads'], 'Bread & Bakery'],
    [['en:fruits-and-vegetables-based-foods', 'en:fruits'], 'Produce'],
    [['en:beverages', 'en:fruit-juices'], 'Other'],
    [['en:snacks', 'en:chips-and-fries'], 'Other'],
    [[], 'Other'],
    [undefined, 'Other']
];
off.forEach(([tags, expected]) => {
    test(JSON.stringify(tags) + ' -> ' + expected, () => assert.strictEqual(lib.categoryFromOffTags(tags), expected));
});

console.log('\nResult: ' + passed + ' passed, ' + failed + ' failed');
process.exit(failed > 0 ? 1 : 0);

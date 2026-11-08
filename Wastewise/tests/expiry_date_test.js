// Run with:  node tests/expiry_date_test.js
const assert = require('assert');
const lib = require('../assets/js/wastewise_lib.js');
const TODAY = '2026-11-08';

let passed = 0, failed = 0;
function test(name, fn) {
    try { fn(); passed++; console.log('  PASS  ' + name); }
    catch (e) { failed++; console.log('  FAIL  ' + name + '\n        ' + e.message); }
}
const read = (t) => lib.parseExpiryDate(t, TODAY);

console.log('Expiry date reading');
test('dots, full year', () => assert.strictEqual(read('12.11.2026').iso, '2026-11-12'));
test('slashes, two digit year', () => assert.strictEqual(read('12/11/26').iso, '2026-11-12'));
test('dashes', () => assert.strictEqual(read('05-12-2026').iso, '2026-12-05'));
test('ISO format', () => assert.strictEqual(read('2026-12-24').iso, '2026-12-24'));
test('English month name', () => assert.strictEqual(read('BEST BEFORE 12 NOV 2026').iso, '2026-11-12'));
test('Danish month name (okt, maj)', () => {
    assert.strictEqual(read('Mindst holdbar til 03 OKT 2026').iso, '2026-10-03');
    assert.strictEqual(read('14 maj 2027').iso, '2027-05-14');
});
test('keyword date wins over packing date', () => {
    assert.strictEqual(read('Packed 01.11.2026  Best before 20.11.2026').iso, '2026-11-20');
    assert.strictEqual(read('Best before 20.11.2026  Packed 01.11.2026').iso, '2026-11-20');
});
test('without a keyword the later date is used', () => assert.strictEqual(read('01.11.2026 20.11.2026').iso, '2026-11-20'));
test('month and year only uses the last day', () => {
    const r = read('BB 02/2028');
    assert.strictEqual(r.iso, '2028-02-29');
    assert.strictEqual(r.monthOnly, true);
});
test('OCR misreads O and l as digits', () => assert.strictEqual(read('EXP 2O.11.2O26').iso, '2026-11-20'));
test('day and month without a year uses the next one', () => {
    const r = read('EXP 12 NOV');
    assert.strictEqual(r.iso, '2026-11-12');
    assert.strictEqual(r.yearGuessed, true);
    assert.strictEqual(read('EXP 05 JAN').iso, '2027-01-05');
});
test('impossible dates are ignored', () => {
    assert.strictEqual(read('31.02.2026').iso, null);
    assert.strictEqual(read('45.13.2026').iso, null);
});
test('text without a date', () => {
    assert.strictEqual(read('Organic whole milk 1 L').iso, null);
    assert.strictEqual(read('').iso, null);
    assert.strictEqual(read(null).iso, null);
});
test('barcode digits are not read as a date', () => assert.strictEqual(read('5701234567890').iso, null));
test('several lines of noise around a date', () => assert.strictEqual(read('L2345 A\nBedst før: 09.12.2026\nNetto 500 g').iso, '2026-12-09'));

console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);

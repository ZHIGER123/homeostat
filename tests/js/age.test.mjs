import test from 'node:test';
import assert from 'node:assert/strict';
import { maxBirthDate, isOldEnough } from '../../resources/js/age.js';

const today = new Date(2026, 10, 19);
for (const [value, expected] of [
    ['2010-11-19', true], // Exactly sixteen today.
    ['2010-11-20', false],
    ['2008-02-29', true],
    ['2000-02-30', false],
    ['1899-12-31', false],
    ['2027-01-01', false],
    ['', false],
    ['2000-1-1', false],
]) {
    test(`birthday ${JSON.stringify(value)}: ${expected}`, () => {
        assert.equal(isOldEnough(value, today), expected);
    });
}
test('cutoff uses the local calendar without UTC date drift', () => {
    assert.equal(maxBirthDate(today), '2010-11-19');
    assert.equal(maxBirthDate(new Date(2024, 1, 29)), '2008-02-29');
});

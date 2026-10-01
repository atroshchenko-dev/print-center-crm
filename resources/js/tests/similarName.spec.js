import { describe, it, expect } from 'vitest';
import { findSimilarName } from '@/utils/similarName';

/**
 * Довідник уже містить «Департамерт реклами» поряд із «Департамент реклами»
 * — саме тому підказка існує. Вона попереджає, а не забороняє.
 */
describe('findSimilarName', () => {
    const book = ['Департамент реклами', 'Кафедра туризму', 'КЖУР'];

    it('catches a one-letter typo', () => {
        expect(findSimilarName('Департамерт реклами', book)).toBe('Департамент реклами');
    });

    it('catches a difference in case and spacing', () => {
        expect(findSimilarName('  кафедра  туризму ', book)).toBe('Кафедра туризму');
    });

    it('stays silent for a genuinely new name', () => {
        expect(findSimilarName('Відділ аспірантури', book)).toBeNull();
    });

    it('stays silent for a short input that resembles nothing', () => {
        expect(findSimilarName('АБ', book)).toBeNull();
    });

    it('stays silent for an empty input', () => {
        expect(findSimilarName('', book)).toBeNull();
        expect(findSimilarName('   ', book)).toBeNull();
    });
});

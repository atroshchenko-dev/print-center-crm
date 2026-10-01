import path from 'node:path';

/**
 * Where auth.setup.ts leaves the signed-in session for the rest of the run.
 *
 * Lives in its own module because Playwright refuses to let one test file
 * import another, and `auth.setup.ts` is a test file as far as the runner is
 * concerned.
 */
export const AUTH_FILE = path.join('e2e', '.auth', 'user.json');

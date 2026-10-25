// PHP warnings and errors the run caused. A production-like server does not
// print them on the page, so the page checks cannot see them: give the tests
// the site's PHP error log (ITFLOW_PHP_LOG, e.g. /var/log/apache2/itflow-error.log)
// when they run on the server itself. Also files a page asked for that Apache
// refused (AH01630 with a referer - e.g. an image pointing at a folder), which
// the page checks can miss when the browser only shows a broken image. Runs last.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

test('no PHP warnings or errors in the log during the run', async () => {
    const log = process.env.ITFLOW_PHP_LOG;
    test.skip(!log, 'ITFLOW_PHP_LOG not given');
    expect(fs.existsSync(log), `${log} readable`).toBe(true);
    const offset = Number(fs.readFileSync(path.join(__dirname, '.auth', 'php-log-offset'), 'utf8') || 0);
    const size = fs.statSync(log).size;
    const added = size > offset ? fs.readFileSync(log).subarray(offset).toString('utf8') : '';
    // One line per message, without the time / pid / client in front, each once
    const problems = [...new Set(added.split(/\r?\n/)
        .filter((line) => /PHP (Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable)/.test(line)
            || (/AH01630/.test(line) && /, referer: /.test(line)))
        .map((line) => line.replace(/^.*?(PHP |AH01630)/, '$1').replace(/, referer: .*$/, '')))];
    expect(problems).toEqual([]);
});

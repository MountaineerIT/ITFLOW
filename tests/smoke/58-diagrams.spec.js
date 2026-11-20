// Diagrams with draw.io (functions/mit_diagrams.php, agent/js/mit_diagrams.js, 26.11.8.10): the editor is draw.io's own web app served from this
// server (/mit_drawio/) - nothing it loads comes from another site.
//  1. Files: New diagram in the Upload menu -> a shape -> Save & close makes "<name>.drawio.png" - a PNG carrying the diagram (mxfile).
//  2. Edit diagram opens it again with its shape; another shape -> Save keeps the one before as a version; Earlier versions -> Put this back.
//  3. A document: the editor's Diagram button inserts a diagram; ITFlow's own save writes it to uploads/documents/<id>/ with the mit-drawio class.
//  4. The document's page: Edit diagram saves straight to it, and ITFlow keeps the version before.
// Removes what it makes.

const fs = require('fs');
const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, expectClean } = require('./helpers');
const db = require('./mitdb');

const ROOT = process.env.ITFLOW_ROOT || '/var/www/itflow';
const CLIENT = Number(db.val("SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1"));
const made = { name: smokeName('network map'), doc: smokeName('site map document'), fileIds: [], documentId: 0 };

test.afterAll(() => {
    for (const id of made.fileIds) {
        const ref = db.val(`SELECT file_reference_name FROM files WHERE file_id = ${id}`);
        if (ref) {
            fs.rmSync(`${ROOT}/uploads/clients/${CLIENT}/${ref}`, { force: true });
        }
        fs.rmSync(`${ROOT}/uploads/mit_diagrams/${id}`, { recursive: true, force: true });
        db.exec(`DELETE FROM files WHERE file_id = ${id}`);
    }
    if (made.documentId) {
        db.exec(`DELETE FROM document_versions WHERE document_version_document_id = ${made.documentId}`);
        db.exec(`DELETE FROM documents WHERE document_id = ${made.documentId}`);
        fs.rmSync(`${ROOT}/uploads/documents/${made.documentId}`, { recursive: true, force: true });
    }
});

// The editor's frame, once its diagram is in (our bar's Save is enabled on draw.io's "load" message)
async function editor(page) {
    const shade = page.locator('[data-mit-drawio-editor]');
    await shade.locator('[data-mit-drawio-save]:not([disabled])').waitFor({ timeout: 45000 });
    const frame = page.frames().find((f) => f.url().includes('/mit_drawio/'));
    expect(frame, 'draw.io is served from this server').toBeTruthy();
    return { shade, frame };
}

// Shapes on the diagram
async function shapes(frame) {
    return frame.evaluate(() => document.querySelectorAll('.geDiagramContainer svg > g > g:nth-child(2) > g').length);
}

async function addShape(frame, nth = 0) {
    const before = await shapes(frame);
    await frame.locator('.geSidebar a.geItem').nth(nth).click();
    await expect.poll(() => shapes(frame), 'a shape was added').toBeGreaterThan(before);
}

test('diagrams: draw.io in Files and documents, with earlier versions', async ({ page }) => {
    test.skip(!CLIENT, 'no client here');
    test.skip(!db.val("SHOW TABLES LIKE 'mit_diagram_versions'"), 'M066 not run');
    test.setTimeout(240000);
    const errors = watch(page);
    const outside = [];
    page.on('request', (r) => {
        const url = r.url();
        if (!/^(data|blob|about):/.test(url) && new URL(url).host !== new URL(process.env.ITFLOW_URL || 'https://itflow-beta.test').host) {
            outside.push(url);
        }
    });

    // 1. A new diagram in Files
    await visit(page, `agent/files.php?client_id=${CLIENT}`, errors);
    await page.locator('button[aria-label="More ways to add"]').click();
    await page.locator('[data-mit-diagram-new]').click();
    await page.locator('.swal2-input').fill(made.name);
    await page.locator('.swal2-confirm').click();
    let { shade, frame } = await editor(page);
    await expect(shade.locator('[data-mit-drawio-title]')).toHaveText(made.name);
    await addShape(frame, 0);
    await expect(shade.locator('[data-mit-drawio-state]')).toHaveText('Not saved');
    await Promise.all([page.waitForEvent('load'), shade.locator('[data-mit-drawio-save-close]').click()]);
    const fileId = Number(db.val(`SELECT file_id FROM files WHERE file_name = ${db.q(made.name + '.drawio.png')} AND file_client_id = ${CLIENT}`) || 0);
    expect(fileId, 'the diagram is a file of the client').toBeGreaterThan(0);
    made.fileIds.push(fileId);
    const ref = db.val(`SELECT file_reference_name FROM files WHERE file_id = ${fileId}`);
    const first = fs.readFileSync(`${ROOT}/uploads/clients/${CLIENT}/${ref}`);
    expect(first.subarray(1, 4).toString(), 'a PNG').toBe('PNG');
    expect(first.includes('mxfile'), 'with the diagram inside it').toBe(true);

    // 2. Edit it again - the shape is there; another shape, Save, then close: the one before is a version
    await page.locator(`[data-mit-diagram-edit][data-file-id="${fileId}"]`).first().evaluate((el) => el.click());
    ({ shade, frame } = await editor(page));
    expect(await shapes(frame), 'its shape came back').toBe(1);
    await addShape(frame, 4);
    await shade.locator('[data-mit-drawio-save]').click();
    await expect(shade.locator('[data-mit-drawio-state]')).toHaveText('Saved');
    await Promise.all([page.waitForEvent('load'), shade.locator('[data-mit-drawio-close]').click()]);
    expect(db.val(`SELECT COUNT(*) FROM mit_diagram_versions WHERE file_id = ${fileId}`), 'the first save kept as a version').toBe('1');
    expect(fs.readFileSync(`${ROOT}/uploads/clients/${CLIENT}/${ref}`).equals(first), 'the file holds the new picture').toBe(false);

    // Earlier versions, from the file's ⋮ menu
    await page.locator(`a.ajax-modal[data-modal-url="modals/mit_diagram/versions.php?id=${fileId}"]`).first().evaluate((el) => el.click());
    const versions = page.locator('.modal.show [data-mit-diagram-versions]');
    await versions.waitFor();
    await expect(versions.locator('[data-mit-diagram-version]')).toHaveCount(1);
    page.once('dialog', (d) => d.accept());
    await Promise.all([page.waitForEvent('load'), versions.locator('[data-mit-diagram-restore]').click()]);
    expect(fs.readFileSync(`${ROOT}/uploads/clients/${CLIENT}/${ref}`).equals(first), 'the first picture is back').toBe(true);
    expect(db.val(`SELECT COUNT(*) FROM mit_diagram_versions WHERE file_id = ${fileId}`), 'what was there kept too').toBe('2');

    // 3. A diagram inside a new document
    let doc = await openModal(page, `modals/document/document_add.php?client_id=${CLIENT}`, 'xl');
    await doc.locator('.tox-tbtn', { hasText: 'Diagram' }).click();
    ({ shade, frame } = await editor(page));
    await addShape(frame, 0);
    await shade.locator('[data-mit-drawio-save-close]').click();
    await expect(shade).toHaveCount(0);
    await fillForm(doc, { name: made.doc });
    await submit(doc, 'add_document');
    made.documentId = Number(db.val(`SELECT document_id FROM documents WHERE document_name = ${db.q(made.doc)}`) || 0);
    expect(made.documentId, 'the document was saved').toBeGreaterThan(0);
    const content = db.val(`SELECT document_content FROM documents WHERE document_id = ${made.documentId}`);
    const src = (/<img[^>]*class="mit-drawio"[^>]*src="([^"]+)"|<img[^>]*src="([^"]+)"[^>]*class="mit-drawio"/.exec(content) || []).slice(1).find(Boolean) || '';
    expect(src, 'ITFlow wrote the picture to uploads/documents/').toMatch(new RegExp(`^/uploads/documents/${made.documentId}/img_`));
    expect(fs.readFileSync(ROOT + src).includes('mxfile'), 'the document\'s picture is still an editable diagram').toBe(true);

    // 4. The document's page: Edit diagram saves straight to it, the version before kept
    await visit(page, `agent/document.php?client_id=${CLIENT}&document_id=${made.documentId}`, errors);
    await expect(page.locator('.itflow-document-body img.mit-drawio')).toHaveCount(1);
    await page.locator('[data-mit-diagram-doc-edit]').click();
    ({ shade, frame } = await editor(page));
    expect(await shapes(frame), 'the document\'s diagram came back').toBe(1);
    await addShape(frame, 4);
    await Promise.all([page.waitForEvent('load'), shade.locator('[data-mit-drawio-save-close]').click()]);
    const after = db.val(`SELECT document_content FROM documents WHERE document_id = ${made.documentId}`);
    expect(after, 'a new picture').not.toContain(src);
    expect(after).toContain('mit-drawio');
    expect(db.val(`SELECT COUNT(*) FROM document_versions WHERE document_version_document_id = ${made.documentId}`), 'ITFlow\'s version of before').toBe('1');

    expect(outside, 'nothing from another site').toEqual([]);
    await expectClean(page, errors);
});

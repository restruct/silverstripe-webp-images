import { test as base, expect, type Locator, type Page } from '@playwright/test';

// Shared fixtures and helpers for the webp-images specs.
//
// Fixtures (tests/browser/fixtures/, copied into the scratch host by the runner):
//  - /webpb-page: a front-end page rendering seeded images through the module, one per <section>;
//  - webpb/webpb-photo.jpg (published, 800 x 600), webpb/webpb-anim.gif (published) and
//    webpb/webpb-draft.jpg (draft only, so protected), drawn with GD at dev/build;
//  - fixtures/_config/webpb.yml: picture_formats [avif, webp].

/**
 * test, extended with an automatic console guard: every spec fails if the page logs a console
 * error or throws an uncaught exception at any point (a broken image URL arrives as "Failed to
 * load resource"). Warnings do not count.
 */
export const test = base.extend<{ consoleGuard: void }>({
    consoleGuard: [
        async ({ page }, use, testInfo) => {
            const errors: string[] = [];
            page.on('console', (msg) => {
                if (msg.type() === 'error') {
                    errors.push(`console.error: ${msg.text()} (${msg.location().url})`);
                }
            });
            page.on('pageerror', (err) => errors.push(`uncaught: ${err.message}`));

            await use();

            if (errors.length) {
                await testInfo.attach('console-errors', { body: errors.join('\n'), contentType: 'text/plain' });
            }
            expect(errors, 'no console errors or uncaught exceptions').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };

/** Scroll an <img> into view (images are lazy-loaded), wait until it decoded, return its size. */
export async function naturalSize(img: Locator): Promise<{ width: number; height: number }> {
    await img.scrollIntoViewIfNeeded();
    await expect
        .poll(() => img.evaluate((i: HTMLImageElement) => i.complete && i.naturalWidth > 0), { message: 'image decoded' })
        .toBe(true);
    return img.evaluate((i: HTMLImageElement) => ({ width: i.naturalWidth, height: i.naturalHeight }));
}

/** GET a URL with the page's session and return status and content type. */
export async function served(page: Page, url: string): Promise<{ status: number; type: string }> {
    const res = await page.request.get(new URL(url, page.url()).toString());
    return { status: res.status(), type: (res.headers()['content-type'] ?? '').split(';')[0] };
}

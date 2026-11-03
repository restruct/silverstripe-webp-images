import { test, expect, naturalSize, served } from './support';

// What a browser gets on the front end: resized images as decoded WebP, originals and gifs
// untouched, <picture> sources in preference order with the jpeg fallback, and protected draft
// variants served as WebP to the CMS user only.

test.beforeEach(async ({ page }) => {
    const response = await page.goto('/webpb-page');
    expect(response?.status()).toBe(200);
});

test('a resized jpeg is served as a WebP variant the browser decodes', async ({ page }) => {
    const img = page.locator('#webpb-auto img');
    const src = (await img.getAttribute('src'))!;
    expect(src, 'core ExtRewrite variant').toMatch(/__[^/]*ExtRewrite[^/]*\.webp$/);
    expect(src, 'not a 2.x sidecar').not.toMatch(/\.jpg\.webp$/);
    // the tag keeps the variant's own size, and the browser decoded the file at that size
    await expect(img).toHaveAttribute('width', '200');
    expect(await naturalSize(img)).toEqual({ width: 200, height: 150 });
    expect(await served(page, src)).toEqual({ status: 200, type: 'image/webp' });

    // published, so public: an anonymous visitor gets it too
    const anon = await page.context().browser()!.newContext({ storageState: { cookies: [], origins: [] } });
    const res = await anon.request.get(new URL(src, page.url()).toString());
    expect(res.status(), 'served to an anonymous visitor').toBe(200);
    await anon.close();
});

test('the original and a gif keep their own format', async ({ page }) => {
    const original = page.locator('#webpb-original img');
    expect(await original.getAttribute('src')).toMatch(/webpb-photo\.jpg$/);
    expect(await naturalSize(original)).toEqual({ width: 800, height: 600 });

    const gif = page.locator('#webpb-gif img');
    expect(await gif.getAttribute('src')).toMatch(/\.gif$/);
    expect(await naturalSize(gif)).toEqual({ width: 100, height: 75 });
});

test('<picture> offers its sources in order and falls back to the jpeg', async ({ page }) => {
    const picture = page.locator('#webpb-picture picture');
    await expect(picture).toHaveCount(1);
    const img = picture.locator('img');
    expect(await img.getAttribute('src'), 'fallback in the original format').toMatch(/\.jpg$/);

    const sources = await picture.locator('source').evaluateAll((els) =>
        els.map((e) => ({ type: e.getAttribute('type')!, srcset: e.getAttribute('srcset')! })),
    );
    const types = sources.map((s) => s.type);
    // AVIF is configured first (fixture config) but only present when GD/Imagick can encode it
    expect([['image/avif', 'image/webp'], ['image/webp']]).toContainEqual(types);
    for (const s of sources) {
        expect(await served(page, s.srcset), s.srcset).toEqual({ status: 200, type: s.type });
    }

    // Chromium supports both formats, so it takes the first source, decoded at the variant size
    expect(await naturalSize(img)).toEqual({ width: 240, height: 180 });
    const current = await img.evaluate((i: HTMLImageElement) => i.currentSrc);
    expect(new URL(current).pathname).toBe(new URL(sources[0].srcset, page.url()).pathname);
});

test('a draft image variant is a protected WebP, served to the CMS user only', async ({ page }) => {
    const img = page.locator('#webpb-draft img');
    const src = (await img.getAttribute('src'))!;
    expect(src).toMatch(/\.webp$/);
    expect(await naturalSize(img)).toEqual({ width: 120, height: 90 });
    expect(await served(page, src)).toEqual({ status: 200, type: 'image/webp' });

    const anon = await page.context().browser()!.newContext({ storageState: { cookies: [], origins: [] } });
    const res = await anon.request.get(new URL(src, page.url()).toString());
    expect(res.status(), 'not served without the CMS session').not.toBe(200);
    await anon.close();
});

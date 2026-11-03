import { test, expect } from './support';

// The Files section (asset-admin) shows thumbnails of a whole folder at once. With the default
// convert_in_cms: false they stay jpeg/png, so opening a big folder never triggers a WebP encode
// per thumbnail in that request.
//
// Only a PUBLISHED image's thumbnail goes through getURL() (and so through the module): asset-admin
// inlines a protected (draft) thumbnail as a data: URI read from the stored jpeg. The fixture
// therefore publishes webpb-photo.jpg and pre-generates its thumbnails at dev/build.

test('Files section thumbnails of a published jpeg stay jpeg', async ({ page }) => {
    await page.goto('/admin/assets');
    // exact title: a reused host may hold other files whose names contain "webpb"
    const folder = page.locator('.gallery-item').filter({ has: page.getByText('webpb', { exact: true }) });
    await expect(folder).toHaveCount(1);
    await folder.click();

    const tile = page.locator('.gallery-item').filter({ has: page.getByText('webpb photo', { exact: true }) });
    await expect(tile).toHaveCount(1);
    const thumb = tile.locator('.gallery-item__thumbnail');
    await expect
        .poll(() => thumb.evaluate((e) => getComputedStyle(e).backgroundImage), { message: 'a thumbnail URL' })
        .toMatch(/^url\(/);
    const style = await thumb.evaluate((e) => getComputedStyle(e).backgroundImage);
    expect(style, 'a public thumbnail URL, not inlined').toMatch(/^url\("?(https?:|\/)/);
    expect(style, 'thumbnail in its own format').toMatch(/\.jpg(\?[^"]*)?"?\)$/);
    expect(style).not.toMatch(/\.webp|ExtRewrite/);
});

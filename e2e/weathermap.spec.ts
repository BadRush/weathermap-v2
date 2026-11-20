import { expect, test } from '@playwright/test';

const USERNAME = process.env.LIBRENMS_USER ?? 'admin';
const PASSWORD = process.env.LIBRENMS_PASSWORD ?? 'admin';

test.describe('LibreNMS Weathermap E2E journey', () => {
    test.skip(!process.env.LIBRENMS_URL, 'Set LIBRENMS_URL to run live LibreNMS E2E tests.');

    test('create map, add nodes & link, save, view live SVG, and open kiosk', async ({ page }) => {
        await page.goto('/login');
        await page.fill('input[name="username"]', USERNAME);
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('button[type="submit"]');
        await expect(page).not.toHaveURL(/\/login/);

        await page.goto('/weathermap');
        await expect(page.getByRole('heading', { name: /Weathermap/i })).toBeVisible();

        // Create map via API and open editor
        const created = await page.evaluate(async () => {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
            const res = await fetch('/weathermap/api/maps', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ name: `E2E Map ${Date.now()}` }),
            });
            return res.json();
        });

        await page.goto(`/weathermap/maps/${created.map.id}/edit`);
        await expect(page.getByRole('button', { name: '+ Node' })).toBeVisible();

        // Place two nodes on canvas
        await page.getByRole('button', { name: '+ Node' }).click();
        const svg = page.locator('svg.wm-editor-svg');
        await svg.click({ position: { x: 180, y: 200 } });
        await page.getByRole('button', { name: '+ Node' }).click();
        await svg.click({ position: { x: 460, y: 200 } });

        // Save map
        await page.getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText(/Saved \(rev 2\)/)).toBeVisible();

        // View live SVG map
        await page.goto(`/weathermap/maps/${created.map.id}`);
        await expect(page.locator('svg.wm-scene-svg')).toBeVisible();

        // Open Kiosk mode
        await page.goto(`/weathermap/kiosk?maps=${created.map.id}`);
        await expect(page.locator('svg.wm-scene-svg')).toBeVisible();
    });
});

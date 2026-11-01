<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Search engines never list the panel: every response says so in
 * X-Robots-Tag, every page in its robots meta tag, and robots.txt turns
 * crawlers away.
 */
class NoIndexTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'noindex, nofollow, noarchive, nosnippet';

    private const META = '<meta name="robots" content="noindex, nofollow">';

    public function test_sign_in_page_is_not_indexed(): void
    {
        $this->get('/login')->assertOk()
            ->assertHeader('X-Robots-Tag', self::HEADER)
            ->assertSee(self::META, false);
    }

    public function test_panel_pages_redirects_and_errors_are_not_indexed(): void
    {
        $this->get('/sites')->assertRedirect('/login')->assertHeader('X-Robots-Tag', self::HEADER);
        $this->get('/no-such-page')->assertNotFound()
            ->assertHeader('X-Robots-Tag', self::HEADER)
            ->assertSee(self::META, false);
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertHeader('X-Robots-Tag', self::HEADER);

        $this->actingAs(User::factory()->create());
        $this->get('/install')->assertOk()->assertHeader('X-Robots-Tag', self::HEADER)->assertSee(self::META, false);
    }

    public function test_maintenance_page_of_an_update_is_not_indexed(): void
    {
        $this->artisan('down')->assertSuccessful();
        try {
            $this->get('/login')->assertServiceUnavailable()->assertHeader('X-Robots-Tag', self::HEADER);
        } finally {
            $this->artisan('up');
        }
    }

    /**
     * Crawlers may fetch the pages, on purpose: one they may not fetch is
     * never seen to be noindex, and a page already listed stays listed.
     */
    public function test_robots_txt_lets_crawlers_see_noindex(): void
    {
        $this->assertSame("# Every response here is noindex (X-Robots-Tag).\nUser-agent: *\nDisallow:\n", file_get_contents(public_path('robots.txt')));
    }

    public function test_every_page_layout_carries_the_robots_meta_tag(): void
    {
        $views = resource_path('views');
        $missing = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $html = file_get_contents($file->getPathname());
            // Customers' sites' error pages are their own concern.
            if (preg_match('/<head[\s>]/', $html) && ! str_contains($html, self::META) && ! str_contains($file->getPathname(), '/sites/')) {
                $missing[] = substr($file->getPathname(), strlen($views) + 1);
            }
        }
        $this->assertSame([], $missing, 'pages without the robots meta tag');
    }
}

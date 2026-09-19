<?php

namespace Webrek\Idempotency\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Router;
use Webrek\Idempotency\Tests\Support\Counter;
use Webrek\Idempotency\Tests\TestCase;

/**
 * All requests here are `$this->post(...)` (form submissions, not JSON) so
 * the raw request body is empty and the middleware must build the
 * fingerprint from the parsed input and uploaded files instead.
 */
class FingerprintTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('idempotency')->group(function (Router $router): void {
            $router->post('/submit', fn () => response()->json(['id' => Counter::next()], 201));
        });
    }

    public function test_a_changed_top_level_field_is_detected(): void
    {
        $this->post('/submit', ['a' => 1, 'b' => 1], ['Idempotency-Key' => 'k12'])->assertStatus(201);

        $this->post('/submit', ['a' => 1, 'b' => 2], ['Idempotency-Key' => 'k12'])->assertStatus(422);

        $this->assertSame(1, Counter::$count);
    }

    public function test_a_changed_field_is_detected_even_when_an_unchanged_file_is_also_present(): void
    {
        $file = UploadedFile::fake()->createWithContent('doc.txt', 'same-content');

        $this->post('/submit', ['sku' => 'A', 'doc' => $file], ['Idempotency-Key' => 'k13'])->assertStatus(201);

        // The file-derived suffix must be appended (.=), not assigned (=),
        // otherwise it would wipe out the encoded "sku" change below.
        $this->post('/submit', ['sku' => 'B', 'doc' => $file], ['Idempotency-Key' => 'k13'])->assertStatus(422);

        $this->assertSame(1, Counter::$count);
    }

    public function test_file_submission_order_does_not_affect_the_fingerprint(): void
    {
        $a = UploadedFile::fake()->createWithContent('a.txt', 'aaaa');
        $b = UploadedFile::fake()->createWithContent('b.txt', 'bbbb');

        $this->post('/submit', ['a' => $a, 'b' => $b], ['Idempotency-Key' => 'k14'])->assertStatus(201);

        $this->post('/submit', ['b' => $b, 'a' => $a], ['Idempotency-Key' => 'k14'])
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, Counter::$count);
    }

    public function test_a_change_to_the_second_file_is_detected_even_when_the_first_is_unchanged(): void
    {
        $a = UploadedFile::fake()->createWithContent('a.txt', 'aaaa');
        $b1 = UploadedFile::fake()->createWithContent('b.txt', 'bbbb');
        $b2 = UploadedFile::fake()->createWithContent('b.txt', 'cccc');

        $this->post('/submit', ['a' => $a, 'b' => $b1], ['Idempotency-Key' => 'k15'])->assertStatus(201);

        $this->post('/submit', ['a' => $a, 'b' => $b2], ['Idempotency-Key' => 'k15'])->assertStatus(422);

        $this->assertSame(1, Counter::$count);
    }

    public function test_same_size_different_content_is_detected_via_hash_not_size(): void
    {
        $this->post('/submit', ['file' => UploadedFile::fake()->createWithContent('f.txt', 'aaaa')], ['Idempotency-Key' => 'k16'])
            ->assertStatus(201);

        $this->post('/submit', ['file' => UploadedFile::fake()->createWithContent('f.txt', 'bbbb')], ['Idempotency-Key' => 'k16'])
            ->assertStatus(422);

        $this->assertSame(1, Counter::$count);
    }

    public function test_a_nested_file_change_is_detected_when_its_leaf_key_collides_with_a_top_level_key(): void
    {
        $a = UploadedFile::fake()->createWithContent('a.txt', 'aaaa');
        $f2 = UploadedFile::fake()->createWithContent('n.txt', 'nnnn');
        $f3 = UploadedFile::fake()->createWithContent('n.txt', 'mmmm');

        $this->post('/submit', ['a' => $a, 'docs' => ['a' => $f2]], ['Idempotency-Key' => 'k17'])->assertStatus(201);

        $this->post('/submit', ['a' => $a, 'docs' => ['a' => $f3]], ['Idempotency-Key' => 'k17'])->assertStatus(422);

        $this->assertSame(1, Counter::$count);
    }

    public function test_a_change_to_a_nested_sibling_file_is_detected(): void
    {
        $f1 = UploadedFile::fake()->createWithContent('a.txt', 'aaaa');
        $f2 = UploadedFile::fake()->createWithContent('b.txt', 'bbbb');
        $f3 = UploadedFile::fake()->createWithContent('b.txt', 'cccc');

        $this->post('/submit', ['docs' => ['a' => $f1, 'b' => $f2]], ['Idempotency-Key' => 'k18'])->assertStatus(201);

        $this->post('/submit', ['docs' => ['a' => $f1, 'b' => $f3]], ['Idempotency-Key' => 'k18'])->assertStatus(422);

        $this->assertSame(1, Counter::$count);
    }

    public function test_an_unreadable_file_falls_back_to_a_literal_marker_without_erroring(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root; chmod(0) does not make a file unreadable.');
        }

        $path = tempnam(sys_get_temp_dir(), 'idem');
        file_put_contents($path, 'abcd');
        chmod($path, 0);

        try {
            $file = new UploadedFile($path, 'x.txt', null, null, true);

            $this->post('/submit', ['file' => $file], ['Idempotency-Key' => 'k19'])->assertStatus(201);

            $this->post('/submit', ['file' => $file], ['Idempotency-Key' => 'k19'])
                ->assertStatus(201)
                ->assertHeader('Idempotency-Replayed', 'true');

            $this->assertSame(1, Counter::$count);
        } finally {
            chmod($path, 0644);
            unlink($path);
        }
    }
}

<?php

namespace Modules\TransactionVerification\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\TransactionVerification\Contracts\OcrEngine;
use Modules\TransactionVerification\Contracts\TransactionVerifier;
use Modules\TransactionVerification\Data\ExpectedDestination;
use Modules\TransactionVerification\Data\VerificationRequest;
use Modules\TransactionVerification\Data\VerificationResult;
use Modules\TransactionVerification\Http\Middleware\VerifyServiceCaller;
use Spectator\Spectator;
use Modules\TransactionVerification\Tests\TestCase;

/** The service-to-service API as a caller sees it: signed requests, JSON answers. */
class InternalApiTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/internal/transaction-verification/v1';

    private const SECRET = 'test_secret_do_not_use';

    private const RECEIPT = "3,070 EGP\nTransfer Amount\nTo Instapay\n01000000001\nReference 100000000001";

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'transaction-verification.disk' => 'hetzner',
            'transaction-verification.folder' => 'transaction-verifications',
            'transaction-verification.engine' => 'null',
            'transaction-verification.second_engine.mode' => 'off',
            'transaction-verification.confidence_threshold' => 0.90,
            'transaction-verification.api.enabled' => true,
            'transaction-verification.api.consumers' => ['caller' => ['secrets' => [self::SECRET], 'allowed_ips' => []]],
        ]);
        Storage::fake('hetzner');
        Http::preventStrayRequests();
    }

    public function test_a_signed_caller_reads_a_verification_with_the_phone_masked(): void
    {
        $this->reading(self::RECEIPT);
        $uuid = $this->submitted()->uuid;

        $response = $this->signed('GET', self::PREFIX."/verifications/{$uuid}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.uuid', $uuid)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.verdict', 'match')
            ->assertJsonPath('data.checks.amount.outcome', 'pass')
            ->assertJsonPath('data.extracted.phone', '********001');
        $this->assertStringNotContainsString('01000000001', $response->getContent());
    }

    public function test_a_caller_submits_a_receipt_as_signed_json(): void
    {
        $this->reading(self::RECEIPT);

        $response = $this->signed('POST', self::PREFIX.'/verifications', json_encode($this->submission()));

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subject_type', 'affiliate_payout')
            ->assertJsonPath('data.subject_id', '7')
            ->assertJsonPath('data.verdict', 'match');
        $this->assertSame($response->json('data.uuid'), app(TransactionVerifier::class)->latestFor('affiliate_payout', 7)?->uuid);
        $this->assertCount(1, Storage::disk('hetzner')->allFiles('transaction-verifications'));
    }

    public function test_the_same_idempotency_key_returns_the_same_verification(): void
    {
        $first = $this->signed('POST', self::PREFIX.'/verifications', json_encode($this->submission()))->json('data.uuid');
        $second = $this->signed('POST', self::PREFIX.'/verifications', json_encode($this->submission()))->json('data.uuid');

        $this->assertSame($first, $second);
        $this->assertCount(1, Storage::disk('hetzner')->allFiles('transaction-verifications'));
    }

    public function test_a_bad_submission_is_refused_with_its_reason_and_nothing_is_stored(): void
    {
        config(['transaction-verification.max_file_bytes' => 5000]);

        foreach ([
            'file_invalid' => ['file_base64' => 'not base64!!'],
            'file_too_large' => ['file_base64' => base64_encode(str_repeat("\x89PNG", 2000))],
            'file_type' => ['file_base64' => base64_encode('just some text, not a receipt')],
            'validation' => ['expected_amount_minor' => null],
        ] as $error => $overrides) {
            $this->signed('POST', self::PREFIX.'/verifications', json_encode($this->submission($overrides)))
                ->assertStatus(422)->assertJson(['success' => false, 'error' => $error]);
        }

        // Too long to fit the limit once decoded: refused before it is decoded at all.
        $this->signed('POST', self::PREFIX.'/verifications', json_encode($this->submission(['file_base64' => str_repeat('!', 8000)])))
            ->assertStatus(422)->assertJson(['error' => 'file_too_large']);

        $unknownType = $this->submission(['expected_destination' => ['type' => 'bank_account', 'value' => '1']]);
        $this->signed('POST', self::PREFIX.'/verifications', json_encode($unknownType))
            ->assertStatus(422)->assertJson(['error' => 'validation']);

        $this->assertSame([], Storage::disk('hetzner')->allFiles());
    }

    public function test_a_caller_lists_a_subjects_verifications_newest_first(): void
    {
        $old = $this->submitted('one')->uuid;
        $new = $this->submitted('two')->uuid;
        $this->submitted('other', subjectId: 2);

        // Query keys out of order: the signature sorts them.
        $response = $this->signed('GET', self::PREFIX.'/verifications?subject_id=1&subject_type=affiliate_payout&per_page=1');

        $response->assertOk()
            ->assertJsonPath('data.items.0.uuid', $new)
            ->assertJsonPath('data.meta', ['current_page' => 1, 'per_page' => 1, 'last_page' => 2, 'total' => 2]);
        $this->signed('GET', self::PREFIX.'/verifications?subject_type=affiliate_payout&subject_id=1&page=2&per_page=1')
            ->assertJsonPath('data.items.0.uuid', $old);
        $this->signed('GET', self::PREFIX.'/verifications?subject_type=affiliate_payout')
            ->assertStatus(422)->assertJson(['error' => 'validation']);
    }

    public function test_a_caller_reruns_a_verification(): void
    {
        $this->reading(self::RECEIPT);
        $uuid = $this->submitted()->uuid;
        $this->reading(str_replace('3,070', '3,080', self::RECEIPT));

        $this->signed('POST', self::PREFIX."/verifications/{$uuid}/reprocess")
            ->assertStatus(202)->assertJsonPath('data.uuid', $uuid)->assertJsonPath('data.verdict', 'mismatch');
    }

    public function test_a_caller_is_redirected_to_a_link_to_the_receipt(): void
    {
        $uuid = $this->submitted()->uuid;

        $response = $this->signed('GET', self::PREFIX."/verifications/{$uuid}/file");

        $response->assertRedirect();
        $this->assertStringContainsString("transaction-verifications/{$uuid}.png", $response->headers->get('Location'));
        $this->assertStringContainsString('expiration=', $response->headers->get('Location'));
    }

    public function test_every_answer_matches_the_committed_spec(): void
    {
        Spectator::using('transaction-verification-internal.yaml');
        // The spec's paths are relative to its server URL, which carries the prefix.
        config(['spectator.path_prefix' => self::PREFIX]);
        $this->reading(self::RECEIPT);

        $done = $this->signed('POST', self::PREFIX.'/verifications', json_encode($this->submission()));
        $done->assertValidRequest()->assertValidResponse(202);
        $uuid = $done->json('data.uuid');

        $this->signed('GET', self::PREFIX."/verifications/{$uuid}")->assertValidResponse(200);
        $this->signed('GET', self::PREFIX.'/verifications?subject_type=affiliate_payout&subject_id=7')->assertValidResponse(200);
        $this->signed('POST', self::PREFIX."/verifications/{$uuid}/reprocess")->assertValidResponse(202);
        $this->signed('GET', self::PREFIX.'/verifications/'.Str::uuid())->assertValidResponse(404);
        $this->signed('POST', self::PREFIX.'/verifications', json_encode($this->submission(['file_base64' => 'not base64!!'])))->assertValidResponse(422);
        $this->getJson(self::PREFIX."/verifications/{$uuid}")->assertValidResponse(401);
        $this->signed('GET', self::PREFIX."/verifications/{$uuid}/file")->assertValidResponse(302);

        // The middleware's answers are documented on every operation, reads included.
        config(['transaction-verification.api.consumers.caller.allowed_ips' => ['10.0.0.9']]);
        $this->signed('GET', self::PREFIX."/verifications/{$uuid}")->assertValidResponse(403);
        config(['transaction-verification.api.enabled' => false]);
        $this->signed('GET', self::PREFIX.'/verifications?subject_type=affiliate_payout&subject_id=7')->assertValidResponse(503);
        config(['transaction-verification.api.enabled' => true, 'transaction-verification.api.consumers.caller.allowed_ips' => []]);

        // Still waiting to be read: checks and what was read are empty, and must still be objects.
        Queue::fake();
        $waiting = $this->signed('POST', self::PREFIX.'/verifications', json_encode($this->submission(['idempotency_key' => 'waiting'])));
        $waiting->assertValidResponse(202)->assertJsonPath('data.status', 'pending');
        $this->assertStringContainsString('"checks":{}', $waiting->getContent());
    }

    public function test_writes_are_limited_to_thirty_a_minute(): void
    {
        $url = self::PREFIX.'/verifications/'.Str::uuid().'/reprocess';

        for ($i = 0; $i < 30; $i++) {
            $this->signed('POST', $url)->assertNotFound();
        }

        $this->signed('POST', $url)->assertStatus(429)->assertJson(['success' => false, 'error' => 'rate_limited']);
        // Reads have their own, larger budget.
        $this->signed('GET', self::PREFIX.'/verifications/'.Str::uuid())->assertNotFound();
    }

    public function test_unsigned_requests_under_a_callers_key_id_cannot_use_up_its_budget(): void
    {
        $url = self::PREFIX.'/verifications/'.Str::uuid().'/reprocess';

        // The key id is public (the README's default): only a signed request may count against it.
        for ($i = 0; $i < 31; $i++) {
            $this->signed('POST', $url, secret: 'not_the_secret')->assertStatus(401);
        }

        $this->signed('POST', $url)->assertNotFound();
    }

    public function test_each_caller_key_has_its_own_budget_even_from_one_address(): void
    {
        config(['transaction-verification.api.consumers.other' => ['secrets' => ['other_secret'], 'allowed_ips' => []]]);
        $url = self::PREFIX.'/verifications/'.Str::uuid().'/reprocess';

        for ($i = 0; $i < 30; $i++) {
            $this->signed('POST', $url);
        }

        $this->signed('POST', $url)->assertStatus(429);
        $this->signed('POST', $url, headers: ['X-Service-Key-Id' => 'other'], secret: 'other_secret')->assertNotFound();
    }

    public function test_a_form_encoded_submit_is_refused_because_its_body_is_not_signed(): void
    {
        // PHP hands the app a form body already unpacked, so the signature would cover an empty body.
        $this->signed('POST', self::PREFIX.'/verifications', form: $this->submission())
            ->assertStatus(415)->assertJson(['success' => false, 'error' => 'json_required']);

        $this->assertSame([], Storage::disk('hetzner')->allFiles());
    }

    public function test_a_negative_merchant_id_is_a_caller_mistake(): void
    {
        $this->signed('POST', self::PREFIX.'/verifications', json_encode($this->submission(['merchant_id' => -1])))
            ->assertStatus(422)->assertJson(['error' => 'validation']);
    }

    public function test_a_wrong_path_or_method_still_answers_in_the_error_envelope(): void
    {
        // No Accept header: a caller that forgets it still gets JSON.
        $this->call('GET', self::PREFIX.'/verifications/not-a-uuid')
            ->assertNotFound()->assertExactJson(['success' => false, 'error' => 'not_found', 'message' => 'No such endpoint.']);
        $this->call('DELETE', self::PREFIX.'/verifications/'.Str::uuid())
            ->assertStatus(405)->assertJson(['success' => false, 'error' => 'method_not_allowed']);

        // Outside the prefix nothing changes.
        $this->assertStringNotContainsString('not_found', (string) $this->call('GET', '/api/internal/nothing-here')->getContent());
    }

    public function test_a_failed_reading_shows_the_caller_a_fixed_message_not_the_internal_error(): void
    {
        $this->app->instance(OcrEngine::class, new class implements OcrEngine
        {
            public function read(string $localPath): string
            {
                throw new \RuntimeException('SQLSTATE[42S22]: Column not found: secret_column');
            }

            public function name(): string
            {
                return 'broken';
            }

            public function version(): string
            {
                return '1';
            }
        });
        $uuid = $this->submitted()->uuid;

        $response = $this->signed('GET', self::PREFIX."/verifications/{$uuid}");

        $response->assertOk()->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'The receipt could not be read. Reprocess it or check it by hand.');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_an_unknown_verification_is_404(): void
    {
        foreach ([['GET', ''], ['POST', '/reprocess'], ['GET', '/file']] as [$method, $suffix]) {
            $this->signed($method, self::PREFIX.'/verifications/'.Str::uuid().$suffix)
                ->assertNotFound()->assertJson(['success' => false, 'error' => 'verification_not_found']);
        }
    }

    public function test_an_unsigned_or_wrongly_signed_request_is_refused(): void
    {
        $uuid = $this->submitted()->uuid;

        $this->getJson(self::PREFIX."/verifications/{$uuid}")
            ->assertStatus(401)->assertJson(['success' => false, 'error' => 'service_auth']);
        $this->signed('GET', self::PREFIX."/verifications/{$uuid}", secret: 'another_secret')
            ->assertStatus(401)->assertJson(['success' => false, 'error' => 'service_auth']);
    }

    public function test_an_old_or_reused_request_is_refused(): void
    {
        $url = self::PREFIX.'/verifications/'.$this->submitted()->uuid;

        $this->signed('GET', $url, headers: ['X-Service-Timestamp' => (string) now()->subMinutes(6)->getTimestamp()])
            ->assertStatus(401)->assertJson(['error' => 'service_auth_expired']);

        $nonce = bin2hex(random_bytes(16));
        $this->signed('GET', $url, headers: ['X-Service-Nonce' => $nonce])->assertOk();
        $this->signed('GET', $url, headers: ['X-Service-Nonce' => $nonce])
            ->assertStatus(401)->assertJson(['error' => 'service_auth_replay']);

        $this->signed('GET', $url, headers: ['X-Service-Nonce' => 'not-hex'])
            ->assertStatus(401)->assertJson(['error' => 'service_auth']);
    }

    public function test_a_query_naming_one_key_twice_is_refused_even_when_signed(): void
    {
        // Both spellings decode to subject_id, and PHP keeps whichever comes last: reordering the pairs would change
        // the subject without changing the signed (sorted) string.
        $queries = ['subject_id=1&%73ubject_id=2', 'subject_id=1&subject.id=2', 'subject_id=1&subject_id=2',
            'subject_id=1&%20subject_id=2', 'subject_id=1&subject_id%00x=2', 'subject_id=1&subject[id=2', 'subject_id=1&subject_id_=3&subject_id[=2'];

        foreach ($queries as $query) {
            $this->signed('GET', self::PREFIX."/verifications?subject_type=affiliate_payout&{$query}")
                ->assertStatus(401)->assertJson(['success' => false, 'error' => 'service_auth', 'message' => 'Service request query names a key twice or one PHP cannot read.']);
        }
    }

    public function test_the_readme_signing_example_is_accepted(): void
    {
        $this->travelTo(now()->setTimestamp(1700000000));

        $this->call('GET', self::PREFIX.'/verifications?subject_id=123&subject_type=affiliate_payout', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SERVICE_KEY_ID' => 'caller',
            'HTTP_X_SERVICE_TIMESTAMP' => '1700000000',
            'HTTP_X_SERVICE_NONCE' => '0123456789abcdef0123456789abcdef',
            'HTTP_X_SERVICE_SIGNATURE' => '8fa7229404a13c5a0705875ab386b3f113cc7a4c85c077227409713300cca20e',
        ])->assertOk();
    }

    public function test_a_query_key_php_would_drop_is_refused_not_an_error(): void
    {
        // Straight to the middleware: the test client would parse (and warn about) the query first.
        // PHP warns about a key nested too deep only when errors are not displayed, as in production.
        $displayErrors = ini_set('display_errors', '0');

        try {
            foreach (['a'.str_repeat('[x]', 70).'=1', '%00=1', '+=1'] as $pair) {
                $request = Request::create(self::PREFIX.'/verifications', server: [
                    'HTTP_X_SERVICE_KEY_ID' => 'caller', 'HTTP_X_SERVICE_TIMESTAMP' => (string) now()->getTimestamp(),
                    'HTTP_X_SERVICE_NONCE' => str_repeat('a', 32), 'HTTP_X_SERVICE_SIGNATURE' => 'x',
                ]);
                $request->server->set('QUERY_STRING', "subject_type=affiliate_payout&subject_id=1&{$pair}");

                $response = (new VerifyServiceCaller)->handle($request, fn () => response('passed'));

                $this->assertSame(401, $response->getStatusCode(), $pair);
                $this->assertSame('Service request query names a key twice or one PHP cannot read.', json_decode($response->getContent())->message);
            }
        } finally {
            ini_set('display_errors', $displayErrors);
        }
    }

    public function test_empty_query_segments_are_not_taken_for_a_repeated_key(): void
    {
        $this->submitted();

        $this->signed('GET', self::PREFIX.'/verifications?subject_type=affiliate_payout&&&subject_id=1')
            ->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_the_previous_secret_still_works_while_rotating(): void
    {
        config(['transaction-verification.api.consumers.caller.secrets' => ['new_secret', self::SECRET]]);

        $this->signed('GET', self::PREFIX.'/verifications/'.$this->submitted()->uuid)->assertOk();
    }

    public function test_a_caller_outside_the_ip_allowlist_is_refused(): void
    {
        config(['transaction-verification.api.consumers.caller.allowed_ips' => ['10.0.0.9']]);

        $this->signed('GET', self::PREFIX.'/verifications/'.$this->submitted()->uuid)
            ->assertStatus(403)->assertJson(['error' => 'service_auth_ip']);
    }

    public function test_the_api_answers_503_while_switched_off(): void
    {
        config(['transaction-verification.api.enabled' => false]);

        $this->signed('GET', self::PREFIX.'/verifications/'.$this->submitted()->uuid)
            ->assertStatus(503)->assertJson(['success' => false, 'error' => 'transaction_verification_unavailable']);
    }

    /** Signs like a real caller: METHOD \n PATH?SORTED_QUERY \n sha256(body) \n TIMESTAMP \n NONCE. */
    private function signed(string $method, string $url, ?string $body = null, array $headers = [], ?string $secret = null, array $form = []): TestResponse
    {
        $parts = parse_url($url);
        $query = $this->sortedQuery($parts['query'] ?? '');
        // Signed with whatever the test sends, so a bad timestamp or nonce fails on its own rule.
        $timestamp = $headers['X-Service-Timestamp'] ?? (string) now()->getTimestamp();
        $nonce = $headers['X-Service-Nonce'] ?? bin2hex(random_bytes(16));
        $canonical = implode("\n", [$method, $parts['path'].($query === '' ? '' : "?{$query}"), hash('sha256', $body ?? ''), $timestamp, $nonce]);

        $headers = [
            'X-Service-Key-Id' => $headers['X-Service-Key-Id'] ?? 'caller',
            'X-Service-Timestamp' => $timestamp,
            'X-Service-Nonce' => $nonce,
            'X-Service-Signature' => hash_hmac('sha256', $canonical, $secret ?? self::SECRET),
        ];
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => $form === [] ? 'application/json' : 'application/x-www-form-urlencoded'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $url, $form, [], [], $server, $body);
    }

    private function sortedQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = explode('&', $query);
        usort($pairs, fn ($a, $b) => strcmp(explode('=', $a, 2)[0], explode('=', $b, 2)[0]));

        return implode('&', $pairs);
    }

    /** A valid submit body; $overrides replace top-level keys (null removes one). */
    private function submission(array $overrides = []): array
    {
        $image = UploadedFile::fake()->image('receipt.png', 898, 1600);

        return array_filter($overrides + [
            'subject_type' => 'affiliate_payout',
            'subject_id' => '7',
            'expected_amount_minor' => 307000,
            'expected_destination' => ['type' => 'phone', 'value' => '01000000001'],
            'idempotency_key' => 'affiliate_payout:7',
            'context' => ['uploaded_by' => 'finance'],
            'file_base64' => base64_encode((string) file_get_contents($image->getRealPath())),
        ], fn ($value) => $value !== null);
    }

    private function submitted(string $key = 'affiliate_payout:1', int $subjectId = 1): VerificationResult
    {
        return app(TransactionVerifier::class)->submit(new VerificationRequest(
            subjectType: 'affiliate_payout',
            subjectId: $subjectId,
            expectedAmountMinor: 307000,
            expectedDestination: new ExpectedDestination(ExpectedDestination::PHONE, '01000000001'),
            file: UploadedFile::fake()->image('receipt.png', 898, 1600),
            idempotencyKey: $key,
        ));
    }

    private function reading(string $text): void
    {
        $this->app->instance(OcrEngine::class, new class($text) implements OcrEngine
        {
            public function __construct(private readonly string $text) {}

            public function read(string $localPath): string
            {
                return $this->text;
            }

            public function name(): string
            {
                return 'recorded';
            }

            public function version(): string
            {
                return '1';
            }
        });
    }
}

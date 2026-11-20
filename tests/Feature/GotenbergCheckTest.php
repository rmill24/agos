<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.gotenberg' => ['base_url' => 'https://pdf.example.test', 'username' => 'fixture', 'password' => 'synthetic', 'timeout' => 20]]);
    Http::preventStrayRequests();
});

it('checks public Chromium health and requires credentials for synthetic conversion', function () {
    Http::fakeSequence()
        ->push(['status' => 'up', 'details' => ['chromium' => ['status' => 'up']]])
        ->push('', 401)
        ->push("%PDF-1.7\nfixture\n%%EOF\n", 200, ['Content-Type' => 'application/pdf']);

    $this->artisan('gotenberg:check')->assertSuccessful();
    Http::assertSentInOrder([
        fn (Request $request) => $request->method() === 'GET' && $request->url() === 'https://pdf.example.test/health'
            && ! $request->hasHeader('Authorization'),
        fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://pdf.example.test/forms/chromium/convert/html'
            && ! $request->hasHeader('Authorization'),
        fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://pdf.example.test/forms/chromium/convert/html'
            && $request->hasFile('files', null, 'index.html')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('fixture:synthetic')),
    ]);
});

it('rejects incomplete or insecure configuration before sending requests', function ($key, $value) {
    config(['services.gotenberg.'.$key => $value]);
    Http::fake();
    $this->artisan('gotenberg:check')->assertFailed();
    Http::assertNothingSent();
})->with([['base_url', 'http://pdf.example.test'], ['base_url', 'https://your-gotenberg-service.onrender.com'], ['username', ''], ['password', '']]);

it('stops before using credentials when readiness or authentication enforcement fails', function (int $health, int $challenge, int $count) {
    Http::fakeSequence()->push(['status' => 'up', 'details' => ['chromium' => ['status' => 'up']]], $health)->push('', $challenge);
    $this->artisan('gotenberg:check')->assertFailed();
    Http::assertSentCount($count);
})->with([[503, 401, 1], [302, 401, 1], [200, 200, 2]]);

it('reports an unsuccessful conversion without printing the response body', function ($status, $mime, $body) {
    Http::fakeSequence()->push(['status' => 'up', 'details' => ['chromium' => ['status' => 'up']]])->push('', 401)
        ->push($body, $status, ['Content-Type' => $mime]);
    $this->artisan('gotenberg:check')->expectsOutput('Authenticated conversion did not return the expected PDF (HTTP '.$status.').')->assertFailed();
})->with([[401, 'text/plain', 'Unauthorized'], [200, 'text/html', '<html>error</html>'], [200, 'application/pdf', '%PDF-truncated']]);

it('handles provider connection failures', function () {
    Http::fake(['*' => Http::failedConnection()]);
    $this->artisan('gotenberg:check')->assertFailed();
});

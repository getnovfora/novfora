<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use App\Models\ApiToken;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-API idempotency (E1 / NOV-135, ADR-0115). A mutating request may carry an `Idempotency-Key` header. The
 * key is RESERVED (a pending row inserted) BEFORE the mutation runs, so the UNIQUE (token, key) index makes the
 * mutation run at most ONCE even under a concurrent race — a loser replays the winner's response (or 409s while
 * it is still in flight). A completed key replays its stored response within 24h; a failed mutation releases the
 * reservation so it stays retryable. The stored method+path is a fingerprint: reusing one key for a DIFFERENT
 * request is rejected (422) rather than misrouted. Runs AFTER AuthenticateApiToken (needs the resolved token);
 * safe (GET/HEAD) and keyless requests pass straight through.
 */
final class EnforceIdempotency
{
    /** Sentinel response_status while a reserved key's mutation is still in flight (a real HTTP status is ≥ 100). */
    private const PENDING = 0;

    public function handle(Request $request, Closure $next): Response
    {
        $rawKey = trim((string) $request->header('Idempotency-Key'));
        $token = $request->attributes->get('api_token');

        // Only dedupe a keyed, mutating request from a resolved token.
        if ($rawKey === '' || $request->isMethodSafe() || ! $token instanceof ApiToken) {
            return $next($request);
        }
        $key = hash('sha256', $rawKey); // fixed-length storage key; no truncation collision on a long client key
        $method = $request->method();
        $path = mb_substr($request->path(), 0, 255);

        // RESERVE the key BEFORE executing (insert a pending row). A concurrent request loses at the unique index,
        // so the mutation runs at most ONCE even under a race — the index guards execution, not just storage.
        try {
            $row = ApiIdempotencyKey::create([
                'api_token_id' => $token->getKey(),
                'idempotency_key' => $key,
                'method' => $method,
                'path' => $path,
                'response_status' => self::PENDING,
                'response_body' => '',
                'expires_at' => now()->addDay(),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->handleExistingKey($token, $key, $method, $path, $request, $next);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // A THROWN outcome (abort 403 from the canDo check, a validation 422, a 500, a crash) means the
            // mutation did not complete → release the reservation so the caller can retry, then re-throw for
            // normal rendering. Without this the PENDING row would strand a permanent 409.
            $row->delete();

            throw $e;
        }

        if ($response->getStatusCode() < 300) {
            // Success → record it for replay.
            $row->forceFill([
                'response_status' => $response->getStatusCode(),
                'response_body' => (string) $response->getContent(),
            ])->save();
        } else {
            // A returned non-2xx must stay retryable — release the reservation.
            $row->delete();
        }

        return $response;
    }

    /** A prior/concurrent request holds this key: replay a completed one, 409 an in-flight one, 422 a reused one. */
    private function handleExistingKey(ApiToken $token, string $key, string $method, string $path, Request $request, Closure $next): Response
    {
        $existing = ApiIdempotencyKey::query()
            ->where('api_token_id', $token->getKey())->where('idempotency_key', $key)->first();
        if (! $existing instanceof ApiIdempotencyKey) {
            return $next($request); // vanished (TTL prune race) — just run it
        }

        // Stale (past its TTL) → clear it and run fresh. Bounds a crashed PENDING reservation and a stale
        // completed replay to the 24h TTL even if the scheduled prune hasn't run yet.
        if ($existing->expires_at !== null && $existing->expires_at->isPast()) {
            $existing->delete();

            return $next($request);
        }

        // The key was used for a DIFFERENT request (method+path fingerprint mismatch) → reject, don't misroute.
        if (strtoupper($existing->method).' '.$existing->path !== strtoupper($method).' '.$path) {
            return response()->json(['error' => [
                'code' => 'idempotency_key_reused',
                'message' => 'This Idempotency-Key was already used for a different request.',
            ]], 422);
        }

        // Still in flight → tell the caller to retry rather than double-execute.
        if ((int) $existing->response_status === self::PENDING) {
            return response()->json(['error' => [
                'code' => 'idempotency_in_progress',
                'message' => 'A request with this Idempotency-Key is already in progress.',
            ]], 409);
        }

        // Completed → replay the stored response verbatim.
        return response($existing->response_body, (int) $existing->response_status)
            ->header('Content-Type', 'application/json')
            ->header('Idempotent-Replay', 'true');
    }
}

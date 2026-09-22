{{--
    The framework's own `errors::429` says "Too Many Requests" twice and
    throws the exception's message away, which left the waiting room's
    per-IP refusal (#221) as a blank wall: no reason, no "come back at".
    This file is the vendor view plus one change — the message is the
    exception's own when it carries one — so the refusal's text and its
    `Retry-After` header say the same thing.

    It extends `errors::minimal` like every other error page here, so it
    inherits the correlation ID line (ADR-0056) and there is still exactly
    one error-page mechanism in this application.
--}}
@extends('errors::minimal')

@section('title', __('Too Many Requests'))
@section('code', '429')
@section('message', e($exception->getMessage() ?: __('Too Many Requests')))

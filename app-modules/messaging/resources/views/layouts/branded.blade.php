{{--
    The layout every mail wears (ADR-0048 point 4). Table-based, 600px,
    inline CSS only — the shapes a mail client actually renders reliably.
    No dependency on the app's own semantic tokens: mail lives outside the
    CSS bundle, so the only brand-controlled colours are the two the
    identity resolver hands in (`accentColor`/`accentTextColor`), with the
    theme catalogue's own defaults as the fallback already baked into them.

    Expects: $subject, $body (raw HTML), $identity (BrandMailIdentityData),
    $preferencesUrl (?string), $locale, $preheader, and optionally a hero
    picture — $heroImageUrl / $heroImageAlt (?string, #292) — printed as one
    full-width row between the header and the body. Width and height are
    left to the picture: a mail client that blocks remote images shows the
    alt text in the space it would take and nothing else moves.

    The footer words are catalog lookups in the message's own `$locale`
    (ADR-0068), not the process locale: a queued send renders in a worker
    whose locale belongs to nobody in particular.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5;">
    {{-- Hidden preheader: the snippet an inbox list shows beside the subject. --}}
    <div style="display:none; max-height:0; max-width:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f4f4f5;">
        {{ $preheader }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;">
        <tr>
            <td align="center" style="padding:24px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:100%;">
                    <tr>
                        <td style="background-color:{{ $identity->accentColor }}; color:{{ $identity->accentTextColor }}; padding:24px 32px; font-family:Arial, Helvetica, sans-serif;">
                            @if ($identity->logoUrl !== null)
                                <img src="{{ $identity->logoUrl }}" alt="{{ $identity->logoAlt }}" height="40" style="display:block; border:0; height:40px;">
                            @else
                                <span style="font-size:20px; font-weight:bold; line-height:40px;">{{ $identity->brandName }}</span>
                            @endif
                        </td>
                    </tr>
                    @if (($heroImageUrl ?? null) !== null)
                        <tr>
                            <td style="background-color:#ffffff; padding:0; line-height:0;">
                                <img src="{{ $heroImageUrl }}" alt="{{ $heroImageAlt ?? '' }}" width="600" style="display:block; border:0; width:100%; max-width:600px; height:auto;">
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td style="background-color:#ffffff; padding:32px; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.6; color:#18181b;">
                            {!! $body !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#f4f4f5; padding:24px 32px; font-family:Arial, Helvetica, sans-serif; font-size:12px; line-height:1.6; color:#71717a;">
                            <p style="margin:0 0 8px;">{{ $identity->sellerLine }}</p>
                            @if ($identity->imprintUrl !== null || $identity->privacyUrl !== null)
                                <p style="margin:0 0 8px;">
                                    @if ($identity->imprintUrl !== null)
                                        <a href="{{ $identity->imprintUrl }}" style="color:#71717a;">{{ __('Imprint', [], $locale) }}</a>
                                    @endif
                                    @if ($identity->imprintUrl !== null && $identity->privacyUrl !== null)
                                        &nbsp;·&nbsp;
                                    @endif
                                    @if ($identity->privacyUrl !== null)
                                        <a href="{{ $identity->privacyUrl }}" style="color:#71717a;">{{ __('Privacy', [], $locale) }}</a>
                                    @endif
                                </p>
                            @endif
                            @if ($preferencesUrl !== null)
                                <p style="margin:0;">
                                    <a href="{{ $preferencesUrl }}" style="color:#71717a;">{{ __('Notification settings', [], $locale) }}</a>
                                </p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

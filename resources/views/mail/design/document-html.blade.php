<!doctype html>
<html lang="cs">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"></head>
<body style="margin:0;padding:0;background:{{ $theme['page'] }};color:#1f2937;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $messageData['preheader'] ?? $messageData['title'] }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:{{ $theme['page'] }};"><tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;border-collapse:separate;background:{{ $theme['panel'] }};border:1px solid {{ $theme['border'] }};border-radius:{{ $theme['radius'] }}px;overflow:hidden;">
        <tr><td style="padding:{{ $theme['spacing'] }}px;background:{{ $theme['header'] }};color:{{ $theme['header_text'] }};">
            @if($hasLogo)<!--mail-logo-start--><img src="__MAIL_LOGO__" alt="{{ $senderName }}" style="display:block;max-width:220px;max-height:60px;width:auto;height:auto;margin:0 0 18px 0;border:0;"><!--mail-logo-end-->@endif
            <div style="font-size:12px;line-height:18px;letter-spacing:.08em;text-transform:uppercase;opacity:.8;">{{ $messageData['internal'] ?? false ? 'Interní oznámení' : $senderName }}</div>
            <h1 style="margin:8px 0 0;font-size:26px;line-height:34px;font-weight:700;">{{ $messageData['title'] }}</h1>
        </td></tr>
        <tr><td style="padding:{{ $theme['spacing'] }}px;">
            @if(filled($messageData['status'] ?? null))
                <div style="display:inline-block;margin:0 0 20px;padding:7px 12px;border-radius:999px;background:{{ ($messageData['status_tone'] ?? '') === 'warning' ? '#fff7ed' : (($messageData['status_tone'] ?? '') === 'success' ? '#ecfdf5' : '#eff6ff') }};color:{{ ($messageData['status_tone'] ?? '') === 'warning' ? '#9a3412' : (($messageData['status_tone'] ?? '') === 'success' ? '#047857' : '#1d4ed8') }};font-size:13px;font-weight:700;">{{ $messageData['status'] }}</div>
            @endif
            @if(filled($messageData['intro'] ?? null))<div style="font-size:16px;line-height:25px;color:#334155;">{!! nl2br(e($messageData['intro'])) !!}</div>@endif
            @if(!empty($messageData['facts']))
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;border-collapse:collapse;border-top:1px solid {{ $theme['border'] }};">
                    @foreach($messageData['facts'] as $fact)
                        <tr><td style="padding:10px 8px;border-bottom:1px solid {{ $theme['border'] }};color:#64748b;font-size:14px;">{{ $fact['label'] }}</td><td align="right" style="padding:10px 8px;border-bottom:1px solid {{ $theme['border'] }};color:#0f172a;font-size:{{ !empty($fact['emphasis']) ? '17' : '14' }}px;font-weight:{{ !empty($fact['emphasis']) ? '700' : '600' }};">{{ $fact['value'] }}</td></tr>
                    @endforeach
                </table>
            @endif
            @if(filled($messageData['cta_url'] ?? null))
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 12px;"><tr><td style="border-radius:6px;background:{{ $theme['accent'] }};"><a href="{{ $messageData['cta_url'] }}" title="Zobrazit fakturu online" style="display:inline-block;padding:13px 20px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;">{{ $messageData['cta_label'] ?? 'Zobrazit fakturu' }}</a></td></tr></table>
                <p style="margin:0 0 20px;font-size:11px;line-height:17px;color:#64748b;">Pokud tlačítko nefunguje, otevřete Webfakturu pomocí <a href="{{ $messageData['cta_url'] }}" style="color:{{ $theme['accent'] }};">tohoto odkazu</a>.</p>
            @endif
            @if(filled($messageData['outro'] ?? null))<div style="margin-top:20px;font-size:15px;line-height:24px;color:#475569;">{!! nl2br(e($messageData['outro'])) !!}</div>@endif
            @if(collect($signature)->filter()->isNotEmpty())
                <div style="margin-top:28px;padding-top:20px;border-top:1px solid {{ $theme['border'] }};font-size:14px;line-height:21px;color:#475569;">
                    @if($signature['legacy']){!! nl2br(e($signature['legacy'])) !!}@else
                        @if($signature['text'])<div style="margin-bottom:10px;">{!! nl2br(e($signature['text'])) !!}</div>@endif
                        @if($signature['name'])<strong style="color:#0f172a;">{{ $signature['name'] }}</strong><br>@endif
                        @if($signature['role']){{ $signature['role'] }}<br>@endif
                        @if($signature['phone']){{ $signature['phone'] }}<br>@endif
                        @if($signature['email'])<a href="mailto:{{ $signature['email'] }}" style="color:{{ $theme['accent'] }};">{{ $signature['email'] }}</a><br>@endif
                        @if($signature['web'])<a href="{{ $signature['web'] }}" style="color:{{ $theme['accent'] }};">{{ $signature['web'] }}</a>@endif
                    @endif
                </div>
            @endif
        </td></tr>
        <tr><td style="padding:14px {{ $theme['spacing'] }}px;background:#f8fafc;color:#94a3b8;font-size:10px;line-height:16px;">Tato zpráva byla vytvořena systémem {{ config('app.name') }}.</td></tr>
    </table>
</td></tr></table>
</body></html>

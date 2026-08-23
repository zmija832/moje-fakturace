@php
    $html = $bodyHtml ?? '<!doctype html><html lang="cs"><body><div style="font-family:Arial,sans-serif;line-height:1.5">'.nl2br(e($bodyText)).'</div></body></html>';
    if (!empty($logoData) && !empty($logoMime) && !empty($logoName)) {
        $html = str_replace('__MAIL_LOGO__', $message->embedData($logoData, $logoName, $logoMime), $html);
    } else {
        $html = preg_replace('/<!--mail-logo-start-->.*?<!--mail-logo-end-->/s', '', $html);
    }
@endphp
{!! $html !!}

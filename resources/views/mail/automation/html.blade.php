@php
    $safeBody = e($bodyText);
    if ($webInvoiceUrl !== null) {
        $escapedUrl = e($webInvoiceUrl);
        $safeBody = str_replace($escapedUrl, '<a href="'.$escapedUrl.'">'.$escapedUrl.'</a>', $safeBody);
    }
@endphp
<!doctype html><html lang="cs"><body><p>{!! nl2br($safeBody) !!}</p></body></html>

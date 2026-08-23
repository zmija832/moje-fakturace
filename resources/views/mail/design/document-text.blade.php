{{ $message['title'] }}

@if(filled($message['status'] ?? null)){{ $message['status'] }}

@endif
@if(filled($message['intro'] ?? null)){{ $message['intro'] }}

@endif
@foreach($message['facts'] ?? [] as $fact){{ $fact['label'] }}: {{ $fact['value'] }}
@endforeach
@if(filled($message['cta_url'] ?? null))
{{ $message['cta_label'] ?? 'Zobrazit fakturu' }}: {{ $message['cta_url'] }}
@endif
@if(filled($message['pdf_url'] ?? null))Stáhnout PDF faktury: {{ $message['pdf_url'] }}
@endif
@if(filled($message['outro'] ?? null))
{{ $message['outro'] }}
@endif
@if(collect($signature)->filter()->isNotEmpty())
@if($signature['legacy']){{ $signature['legacy'] }}@else
@if($signature['text']){{ $signature['text'] }}
@endif
@if($signature['name']){{ $signature['name'] }}
@endif
@if($signature['role']){{ $signature['role'] }}
@endif
@if($signature['phone']){{ $signature['phone'] }}
@endif
@if($signature['email']){{ $signature['email'] }}
@endif
@if($signature['web']){{ $signature['web'] }}
@endif
@endif
@endif

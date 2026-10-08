<x-mail::message>
# {{ $copy['title'] }}

{{ $copy['hello'] }}, {{ $applicantName }}.

**{{ $copy['company'] }}:** {{ $companyName }}

{{ $copy['body'] }}

<x-mail::button :url="$loginUrl">
{{ $copy['button'] }}
</x-mail::button>

{{ $copy['help'] }}
</x-mail::message>

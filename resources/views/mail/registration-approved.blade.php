<x-mail::layout>
<x-slot:header>
<x-mail::header :url="$loginUrl">
{{ $companyName }}
</x-mail::header>
</x-slot:header>

# {{ $copy['title'] }}

{{ $copy['hello'] }}, {{ $applicantName }}.

{{ $copy['body'] }}

<x-mail::panel>
{{ $copy['company'] }}

**{{ $companyName }}**
</x-mail::panel>

{{ $copy['credentials'] }}

<x-mail::button :url="$loginUrl">
{{ $copy['button'] }}
</x-mail::button>

{{ $copy['help'] }}

{{ $copy['closing'] }}

**{{ $companyName }}**

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $companyName }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

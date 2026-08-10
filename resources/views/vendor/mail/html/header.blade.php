@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- Compared against the configured name so a rename does not silently drop the logo --}}
@if(trim($slot) === config('app.name'))
<img src="{{ asset('images/worklogd-logo.png') }}" srcset="{{ asset('images/worklogd-logo.svg') }}" class="logo" alt="{{ config('app.name') }}">
@else
{{ $slot }}
@endif
</a>
</td>
</tr>

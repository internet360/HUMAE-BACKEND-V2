@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<img src="{{ rtrim((string) config('app.frontend_url', config('app.url')), '/') }}/logos/humae-logo-light.png" class="logo" alt="HUMAE">
</a>
</td>
</tr>

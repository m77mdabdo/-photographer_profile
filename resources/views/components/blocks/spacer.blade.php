@props(['block', 'media'])
{{-- Presentational only, so it is hidden from assistive technology. --}}
<div class="block block--spacer"
     style="height: {{ (int) ($block->data['height'] ?? 96) }}px"
     aria-hidden="true"></div>

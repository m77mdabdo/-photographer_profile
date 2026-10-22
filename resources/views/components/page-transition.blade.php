{{--
    This page takes part in the page transition (Task 38 Part F). The opt-in
    is inline and allowed by CSP hash, and the script is inline with the
    request's nonce — App\Support\PageTransition says why each is not a file.
--}}
<style>{!! \App\Support\PageTransition::RULE !!}</style>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" data-page-transition>{!! \App\Support\PageTransition::SCRIPT !!}</script>

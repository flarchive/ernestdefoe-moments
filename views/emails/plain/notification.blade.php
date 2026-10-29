<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-moments.email.'.$blueprint->emailKey().'.body', [
'{actor}' => $blueprint->from->display_name,
'{url}' => $url->to('forum')->route('moments.show', ['id' => $blueprint->entry->id]),
]) !!}

{!! resolve(\Ernestdefoe\Moments\Content::class)->plain($blueprint->entry->content) !!}
</x-slot:body>
</x-mail::plain.notification>

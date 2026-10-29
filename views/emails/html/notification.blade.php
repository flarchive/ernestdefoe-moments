<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-moments.email.'.$blueprint->emailKey().'.body', [
            '{actor}' => $blueprint->from->display_name,
            '{url}' => $url->to('forum')->route('moments.show', ['id' => $blueprint->entry->id]),
        ])) !!}
    </x-slot:body>

    <x-slot:preview>
        {!! resolve(\Ernestdefoe\Moments\Content::class)->render($blueprint->entry->content, $blueprint->entry) !!}
    </x-slot:preview>
</x-mail::html.notification>

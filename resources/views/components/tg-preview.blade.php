{{-- Approximates how a post looks in a Telegram channel. --}}
@props(['content', 'channel' => 'Channel preview'])
@php
    use App\Enums\ContentSource;
    use App\Enums\ContentType;
    use App\Support\TelegramFormatter;

    $type = $content->type;
    $url = $content->previewUrl();
    $body = TelegramFormatter::toHtml($content->body(), $content->parse_mode);
    $isFileId = $content->source === ContentSource::FileId;
@endphp
<div {{ $attributes->merge(['class' => 'tg-chat rounded-xl p-4 sm:p-6']) }}>
    <div class="tg-bubble">
        @if ($type !== ContentType::Text)
            @if ($isFileId || ! $url)
                <div class="flex items-center gap-3 bg-slate-100 p-4 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                    <x-content-icon :type="$type" />
                    <div class="min-w-0">
                        <div class="font-medium">{{ $type->label() }}</div>
                        <div class="truncate text-xs opacity-70">{{ $isFileId ? 'Sent by Telegram file ID (no preview available)' : 'No media attached' }}</div>
                    </div>
                </div>
            @elseif ($type === ContentType::Image)
                <img src="{{ $url }}" alt="" class="max-h-96 w-full object-cover" loading="lazy" referrerpolicy="no-referrer">
            @elseif ($type === ContentType::Video || ($type === ContentType::Animation && ! str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.gif')))
                <video src="{{ $url }}" class="max-h-96 w-full bg-black" controls preload="metadata" @if ($type === ContentType::Animation) autoplay loop muted playsinline @endif></video>
            @elseif ($type === ContentType::Animation)
                <img src="{{ $url }}" alt="" class="max-h-96 w-full object-cover" loading="lazy" referrerpolicy="no-referrer">
            @elseif ($type === ContentType::Audio)
                <div class="p-3"><audio src="{{ $url }}" controls preload="none" class="w-full"></audio></div>
            @else
                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-3 p-3 hover:opacity-90">
                    <span class="flex size-11 items-center justify-center rounded-full bg-[#3390ec] text-white"><x-icon name="document" class="size-5" /></span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium">{{ $content->metadata['original_name'] ?? basename(parse_url($url, PHP_URL_PATH) ?: 'document') }}</span>
                        <span class="block text-xs opacity-60">{{ $content->media_size ? number_format($content->media_size / 1048576, 2).' MB' : 'Document' }}</span>
                    </span>
                </a>
            @endif
        @endif

        @if ($body !== '')
            <div class="break-words px-3 py-2 [&_a]:text-[#3390ec] [&_a]:underline-offset-2 hover:[&_a]:underline [&_blockquote]:border-l-2 [&_blockquote]:border-[#3390ec] [&_blockquote]:pl-2 [&_code]:rounded [&_code]:bg-black/5 [&_code]:px-1 [&_code]:font-mono [&_code]:text-[13px] [&_pre]:whitespace-pre-wrap [&_pre]:rounded [&_pre]:bg-black/5 [&_pre]:p-2 [&_pre]:font-mono [&_pre]:text-[13px]">{!! $body !!}</div>
        @endif

        <div class="flex items-center justify-end gap-1 px-3 pb-1.5 text-[11px] text-slate-400">
            <x-icon name="eye" class="size-3" /> 1 · {{ now()->setTimezone(display_tz())->format('g:i A') }}
        </div>
    </div>
    <div class="mt-2 text-center text-[11px] text-slate-600/70 dark:text-slate-400/70">{{ $channel }}</div>
</div>

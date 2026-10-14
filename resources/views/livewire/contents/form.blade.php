<div>
    <x-page-header :title="$content ? 'Edit content' : 'New content'" :description="$content ? $content->title : 'Compose a post. The preview on the right shows roughly how it will look in Telegram.'">
        <x-slot:breadcrumb>
            <a href="{{ route('contents.index') }}" wire:navigate class="hover:text-brand-600">Content Library</a>
            <x-icon name="chevron-right" class="size-3" />
            <span>{{ $content ? 'Edit' : 'New' }}</span>
        </x-slot:breadcrumb>
    </x-page-header>

    <form wire:submit="save" class="grid grid-cols-1 gap-6 lg:grid-cols-5">
        <div class="space-y-6 lg:col-span-3">
            {{-- Basics --}}
            <section class="card p-5">
                <x-field label="Title" for="title" required hint="Internal name — not posted to Telegram.">
                    <input id="title" type="text" wire:model="title" class="input" maxlength="200" placeholder="e.g. Product launch video">
                </x-field>

                <div class="mt-5">
                    <span class="label">Content type <span class="text-red-500">*</span></span>
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                        @foreach (\App\Enums\ContentType::cases() as $case)
                            <label wire:key="type-{{ $case->value }}" @class([
                                'flex cursor-pointer flex-col items-center gap-1.5 rounded-lg p-3 text-center text-xs font-medium ring-1 transition',
                                'bg-brand-50 text-brand-700 ring-2 ring-brand-500 dark:bg-brand-500/15 dark:text-brand-200' => $type === $case->value,
                                'text-slate-600 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800' => $type !== $case->value,
                            ])>
                                <input type="radio" wire:model.live="type" value="{{ $case->value }}" class="sr-only">
                                <x-content-icon :type="$case" class="size-8" />
                                {{ $case === \App\Enums\ContentType::Animation ? 'GIF' : $case->label() }}
                            </label>
                        @endforeach
                    </div>
                    @error('type') <p class="error">{{ $message }}</p> @enderror
                </div>
            </section>

            {{-- Media source --}}
            @if ($typeEnum->isMedia())
                <section class="card p-5">
                    <h2 class="card-title">Media</h2>
                    <div class="mt-3 inline-flex rounded-lg bg-slate-100 p-1 dark:bg-slate-800">
                        @foreach ([\App\Enums\ContentSource::Url, \App\Enums\ContentSource::Upload, \App\Enums\ContentSource::FileId] as $case)
                            <button type="button" wire:click="$set('source', '{{ $case->value }}')" wire:key="src-{{ $case->value }}" @class([
                                'rounded-md px-3 py-1.5 text-xs font-medium transition',
                                'bg-white text-slate-900 shadow dark:bg-slate-700 dark:text-white' => $source === $case->value,
                                'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' => $source !== $case->value,
                            ])>{{ $case->label() }}</button>
                        @endforeach
                    </div>
                    @error('source') <p class="error">{{ $message }}</p> @enderror

                    <div class="mt-4">
                        @if ($source === 'url')
                            <x-field label="Media URL" for="media_url" required error="media_url"
                                     hint="Direct HTTPS link to the file. Telegram downloads it (max 5 MB for photos, 20 MB for other files). After the first post the Telegram file_id is reused, so the URL is not downloaded again.">
                                <div class="flex gap-2">
                                    <input id="media_url" type="url" wire:model.live.debounce.600ms="media_url" class="input font-mono text-xs" placeholder="https://example.com/video.mp4">
                                    <button type="button" wire:click="checkUrl" class="btn btn-secondary shrink-0" wire:loading.attr="disabled" wire:target="checkUrl" @disabled(trim($media_url) === '')>
                                        <span wire:loading wire:target="checkUrl"><x-spinner /></span> Check URL
                                    </button>
                                </div>
                            </x-field>
                            @if ($urlCheck)
                                <p @class(['mt-2 flex items-start gap-1.5 text-xs', 'text-emerald-600 dark:text-emerald-400' => $urlCheck['ok'], 'text-red-600 dark:text-red-400' => ! $urlCheck['ok']])>
                                    <x-icon :name="$urlCheck['ok'] ? 'check-circle' : 'x-circle'" class="size-4" /> {{ $urlCheck['message'] }}
                                </p>
                            @endif
                        @elseif ($source === 'upload')
                            <x-field label="File" for="upload" error="upload"
                                     :hint="'Allowed: '.implode(', ', $typeEnum->allowedExtensions()).' · max '.($typeEnum->maxUploadKilobytes() / 1024).' MB (Telegram Bot API limit).'">
                                <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500 hover:border-brand-400 hover:bg-brand-50/40 dark:border-slate-700 dark:hover:bg-brand-500/5">
                                    <x-icon name="upload" class="size-7 text-slate-400" />
                                    <span wire:loading.remove wire:target="upload">
                                        @if ($upload)
                                            <strong class="text-slate-700 dark:text-slate-200">{{ $upload->getClientOriginalName() }}</strong> ({{ number_format($upload->getSize() / 1048576, 2) }} MB) — click to replace
                                        @elseif ($content?->media_path && $content->source === \App\Enums\ContentSource::Upload)
                                            Current file: <strong>{{ $content->metadata['original_name'] ?? basename($content->media_path) }}</strong> — click to replace
                                        @else
                                            Click to choose a file
                                        @endif
                                    </span>
                                    <span wire:loading wire:target="upload" class="flex items-center gap-2"><x-spinner /> Uploading…</span>
                                    <input id="upload" type="file" wire:model="upload" class="sr-only" accept="{{ collect($typeEnum->allowedExtensions())->map(fn ($e) => '.'.$e)->implode(',') }}">
                                </label>
                            </x-field>
                        @else
                            <x-field label="Telegram file ID" for="telegram_file_id" required error="telegram_file_id"
                                     hint="A file_id received by the same bot that will post this content (file_ids cannot be shared between bots).">
                                <input id="telegram_file_id" type="text" wire:model.live.debounce.600ms="telegram_file_id" class="input font-mono text-xs" placeholder="BAACAgIAAxkBAAIB…">
                            </x-field>
                        @endif
                    </div>
                </section>
            @endif

            {{-- Text / caption --}}
            <section class="card p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="card-title">{{ $typeEnum->isMedia() ? 'Caption' : 'Message text' }} @if (! $typeEnum->isMedia())<span class="text-red-500">*</span>@endif</h2>
                    <div class="flex items-center gap-3">
                        @if ($aiEnabled)
                            <button type="button" wire:click="openAi" class="btn btn-secondary btn-sm">
                                @if ($aiStatus === 'pending')
                                    <x-spinner /> AI likh raha hai…
                                @else
                                    <x-icon name="sparkles" class="size-4 text-purple-500" /> AI se likho
                                @endif
                            </button>
                        @endif
                        <span @class(['text-xs tabular-nums', 'text-red-600' => $bodyLength > $bodyLimit, 'text-slate-400' => $bodyLength <= $bodyLimit])>{{ number_format($bodyLength) }} / {{ number_format($bodyLimit) }}</span>
                    </div>
                </div>
                <div class="mt-3">
                    @if ($typeEnum->isMedia())
                        <textarea wire:model.live.debounce.400ms="caption" rows="5" class="input" placeholder="Optional caption…"></textarea>
                        @error('caption') <p class="error">{{ $message }}</p> @enderror
                    @else
                        <textarea wire:model.live.debounce.400ms="text" rows="8" class="input" placeholder="Write your message…"></textarea>
                        @error('text') <p class="error">{{ $message }}</p> @enderror
                    @endif
                </div>
                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-field label="Formatting" for="parse_mode" hint="HTML supports <b>, <i>, <u>, <s>, <a href>, <code>, <pre>, <blockquote>.">
                        <select id="parse_mode" wire:model.live="parse_mode" class="input">
                            <option value="">Plain text</option>
                            @foreach (\App\Enums\ParseMode::options() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <div class="space-y-2 pt-6 text-sm text-slate-700 dark:text-slate-300">
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="disable_notification" class="checkbox"> Send silently</label>
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="protect_content" class="checkbox"> Protect from forwarding/saving</label>
                        @unless ($typeEnum->isMedia())
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="disable_web_page_preview" class="checkbox"> Disable link preview</label>
                        @endunless
                    </div>
                </div>
            </section>

            {{-- Organisation --}}
            <section class="card grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
                <x-field label="Tags" for="tagsInput" error="tagsInput" hint="Comma separated. New tags are created automatically." class="sm:col-span-2">
                    <input id="tagsInput" type="text" wire:model="tagsInput" class="input" list="known-tags" placeholder="promo, video, weekly">
                    <datalist id="known-tags">
                        @foreach ($knownTags as $known)
                            <option value="{{ $known }}"></option>
                        @endforeach
                    </datalist>
                </x-field>
                <x-field label="Status" for="status" hint="Inactive content is skipped by campaigns.">
                    <select id="status" wire:model="status" class="input">
                        @foreach (\App\Enums\ContentStatus::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field label="Thumbnail URL" for="thumbnail" hint="Optional, used in the admin panel.">
                    <input id="thumbnail" type="url" wire:model="thumbnail" class="input" placeholder="https://…">
                </x-field>
                <x-field label="Notes" for="notes" class="sm:col-span-2">
                    <textarea id="notes" wire:model="notes" rows="2" class="input" placeholder="Internal notes"></textarea>
                </x-field>
                <x-field label="Metadata (JSON)" for="metadataJson" class="sm:col-span-2" hint="Optional key/value data for integrations, e.g. {&quot;source&quot;: &quot;rss&quot;}.">
                    <textarea id="metadataJson" wire:model="metadataJson" rows="2" class="input font-mono text-xs" placeholder='{"campaign_ref": "Q4"}'></textarea>
                </x-field>
            </section>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <a href="{{ route('contents.index') }}" wire:navigate class="btn btn-secondary">Cancel</a>
                @unless ($content)
                    <button type="button" wire:click="save(true)" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="save,upload">Save & add another</button>
                @endunless
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,upload">
                    <span wire:loading wire:target="save"><x-spinner /></span> Save content
                </button>
            </div>
        </div>

        {{-- Live preview --}}
        <aside class="lg:col-span-2">
            <div class="lg:sticky lg:top-24">
                <div class="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-200"><x-icon name="eye" class="size-4" /> Live preview</div>
                <x-tg-preview :content="$preview" />
                <p class="mt-2 text-xs text-slate-400">Approximation — final rendering is done by the Telegram app.</p>
            </div>
        </aside>
    </form>

    {{-- AI draft: keeps checking every 2 s while the server writes, even if the dialog is closed. --}}
    @if ($aiStatus === 'pending')
        <div wire:poll.2s="pollAi" class="hidden"></div>
    @endif

    @if ($aiEnabled)
    <x-modal wire:model="showAi" title="✨ AI se likho" max-width="lg">
        <div class="space-y-4">
            <p class="text-sm text-slate-500">AI server par (offline) draft likhega aur woh {{ $typeEnum->isMedia() ? 'caption' : 'message text' }} mein aa jayega. Save karne se pehle zaroor parh kar theek karein; AI kabhi kabhi ghalat baat bhi likh deta hai.</p>

            <x-field label="Kis baare mein post?" for="aiTopic" error="aiTopic" hint="Jitni tafseel denge, draft utna behtar hoga: product ka naam, offer, date, link waghera.">
                <textarea id="aiTopic" wire:model="aiTopic" rows="3" class="input" maxlength="1000" placeholder="Misaal: Hamari nayi Urdu course 1 November se shuru, registration website par"></textarea>
            </x-field>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <x-field label="Zaban" for="aiLanguage">
                    <select id="aiLanguage" wire:model="aiLanguage" class="input">
                        @foreach (\App\Services\Ai\PostWriter::LANGUAGE_LABELS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field label="Andaaz" for="aiTone">
                    <select id="aiTone" wire:model="aiTone" class="input">
                        <option value="friendly">Friendly</option>
                        <option value="professional">Professional</option>
                        <option value="exciting">Exciting</option>
                        <option value="informative">Informative</option>
                    </select>
                </x-field>
                <x-field label="Lambai" for="aiLength">
                    <select id="aiLength" wire:model="aiLength" class="input">
                        <option value="short">Chhoti</option>
                        <option value="medium">Darmiyani</option>
                        <option value="long">Lambi</option>
                    </select>
                </x-field>
            </div>

            <div class="flex flex-wrap gap-5 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="aiEmojis" class="checkbox"> Emojis</label>
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="aiHashtags" class="checkbox"> Hashtags</label>
            </div>

            @if ($aiStatus === 'pending')
                <div class="flex items-center gap-3 rounded-lg bg-purple-50 p-3 text-sm text-purple-900 dark:bg-purple-500/10 dark:text-purple-200">
                    <x-spinner class="size-5" />
                    <span>AI likh raha hai… server par is mein 10–60 second lag sakte hain. Yeh dialog band bhi kar dein to draft khud text box mein aa jayega.</span>
                </div>
            @elseif ($aiStatus === 'failed' && $aiError)
                <x-docs.callout type="danger">{{ $aiError }}</x-docs.callout>
            @endif

            @if (($typeEnum->isMedia() ? $caption : $text) !== '')
                <p class="text-xs text-amber-600">Dhyaan dein: maujooda {{ $typeEnum->isMedia() ? 'caption' : 'text' }} AI draft se badal jayega.</p>
            @endif
        </div>

        <x-slot:footer>
            <button type="button" class="btn btn-secondary" x-on:click="show = false">Band karein</button>
            <button type="button" wire:click="generateAi" class="btn btn-primary" @disabled($aiStatus === 'pending') wire:loading.attr="disabled" wire:target="generateAi">
                <x-icon name="sparkles" class="size-4" /> Draft banao
            </button>
        </x-slot:footer>
    </x-modal>
    @endif
</div>
